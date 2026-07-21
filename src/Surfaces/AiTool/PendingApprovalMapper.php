<?php

namespace Gtapps\LaravelAgentic\Surfaces\AiTool;

use Gtapps\LaravelAgentic\Approvals\Approval;
use Gtapps\LaravelAgentic\Approvals\ApprovalBroker;
use Gtapps\LaravelAgentic\Approvals\Canonicalizer;
use Gtapps\LaravelAgentic\Audit\Recorder;
use Gtapps\LaravelAgentic\Audit\Redactor;
use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Gtapps\LaravelAgentic\Kernel\ActionDefinition;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Gtapps\LaravelAgentic\Kernel\Registry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;

/**
 * Turns laravel/ai's paused tool calls into the decisions it wants back,
 * reading them from the broker so a human answers once, in one place, however
 * many surfaces are in play.
 *
 * This is where the knock is raised. The Approvable hook cannot do it — it is
 * asked again on resume and would duplicate the row — so the first pass here
 * creates what the human will act on, and later passes read the answer.
 *
 * @internal
 */
class PendingApprovalMapper
{
    public function __construct(
        protected Registry $registry,
        protected ApprovalBroker $broker,
        protected ContextFactory $contexts,
        protected Redactor $redactor,
        protected Recorder $recorder,
        protected AuthFactory $auth,
    ) {}

    /**
     * All-or-nothing by design: laravel/ai rejects a decision map that leaves
     * any gated call unanswered, so an undecided call means the caller should
     * keep waiting rather than resume with a partial answer.
     *
     * Tool calls this package does not own are skipped — only their own app
     * can speak for them — so a run mixing agentic and non-agentic tools must
     * merge its own decisions into what comes back here.
     *
     * @param  iterable<PendingApproval>  $pendingApprovals
     */
    public function decisions(iterable $pendingApprovals, ?Authenticatable $as = null): ?Decisions
    {
        $decisions = [];
        $awaiting = false;

        // The same principal resolution ActionToolAdapter uses. The knock and
        // the execution that later rides it must agree on who is asking:
        // grants are bound to the requesting principal AND the invocation
        // carries it, so a mapper defaulting to null while the tool runs as the
        // ambient user knocks for one principal and executes as another —
        // consent a human gave that the gate then refuses, forever.
        $principal = $as ?? $this->auth->guard()->user();

        foreach ($pendingApprovals as $pending) {
            $definition = $this->registry->find($pending->tool);

            if ($definition === null) {
                continue;
            }

            $context = $this->contexts->make(Surface::AiTool, $principal, idempotencyKey: $pending->id);

            $decision = $this->decide($definition, $pending, $context);

            // Every call is visited even once one is known to be unanswered:
            // bailing early would leave later calls without the knock a human
            // has to act on, and the run would wait forever for consent nobody
            // was ever asked for.
            if ($decision === null) {
                $awaiting = true;
            } else {
                $decisions[$pending->id] = $decision;
            }
        }

        return $awaiting || $decisions === [] ? null : Decisions::from($decisions);
    }

    /**
     * Null means "no answer yet" — not "no".
     */
    protected function decide(ActionDefinition $definition, PendingApproval $pending, ActionContext $context): ?Decision
    {
        $approval = $this->broker->stateFor(ApprovalBroker::invocationKey($context))
            ?? $this->knock($definition, $pending, $context);

        // A definition that changed after the knock was raised voids it: the
        // human approved an action that no longer exists in that shape.
        if ($approval->definition_hash !== $definition->definitionHash) {
            return Decision::reject('The action changed since approval was requested.');
        }

        return match ($approval->status) {
            // 'consumed' means this invocation already ran under its grant.
            // Approving it again cannot double-execute: ApprovalGate's check()
            // matches only 'granted' rows, so a consumed one re-knocks instead
            // of executing.
            'granted', 'consumed' => Decision::approve(),
            'denied' => Decision::reject('A human denied this call.'),
            'expired' => Decision::reject('The approval request expired before it was answered.'),
            default => null,
        };
    }

    protected function knock(ActionDefinition $definition, PendingApproval $pending, ActionContext $context): Approval
    {
        $approval = $this->broker->requestApproval(
            $definition,
            Canonicalizer::key($definition, $pending->arguments),
            $this->redactor->redact(Canonicalizer::withDefaults($definition, $pending->arguments)),
            $context,
        );

        $call = new ActionCall($definition->name, $pending->arguments, $context);
        $call->definition = $definition;
        $call->approvalId = $approval->id;

        // Audited like any other knock, but a failing recorder must not strand
        // a run that is already paused and waiting on a human.
        $this->recorder->recordSafely($call, 'approval_required');

        return $approval;
    }
}
