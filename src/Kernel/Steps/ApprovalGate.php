<?php

namespace Gtapps\LaravelAgentic\Kernel\Steps;

use Gtapps\LaravelAgentic\Approvals\ApprovalBroker;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Approvals\Canonicalizer;
use Gtapps\LaravelAgentic\Audit\Redactor;
use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Gtapps\LaravelAgentic\Kernel\CallsActionMethods;
use Illuminate\Contracts\Container\Container;

/**
 * Runs AFTER Authorize — approval is per-invocation consent on top of
 * standing authorization, never an escalation path. The grant is
 * consumed before Execute: a handler failure after consume means the retry
 * knocks again — never double-execute.
 *
 * @internal
 */
class ApprovalGate
{
    use CallsActionMethods;

    public function __construct(
        protected Container $container,
        protected ApprovalBroker $broker,
        protected Redactor $redactor,
    ) {}

    public function __invoke(ActionCall $call): void
    {
        $definition = $call->definition;

        if ($definition->readOnly || ! $this->needsApproval($call)) {
            return;
        }

        $key = Canonicalizer::key($definition, $call->rawArgs);

        $approval = $this->broker->check($definition, $key, $call->context);

        if ($approval !== null) {
            $call->approvalId = $approval->id;

            return;
        }

        $approval = $this->broker->requestApproval(
            $definition,
            $key,
            $this->redactor->redact(Canonicalizer::withDefaults($definition, $call->rawArgs)),
            $call->context,
        );

        $call->approvalId = $approval->id;

        throw new ApprovalRequiredException($definition->name, $key);
    }

    /**
     * A throwing predicate fails CLOSED to "approval required".
     */
    protected function needsApproval(ActionCall $call): bool
    {
        $needsApproval = $call->definition->needsApproval;

        if (is_bool($needsApproval)) {
            return $needsApproval;
        }

        try {
            $predicate = $this->container->make($needsApproval);

            return (bool) $this->callAction($predicate, '__invoke', $call);
        } catch (\Throwable) {
            return true;
        }
    }
}
