<?php

namespace Gtapps\LaravelAgentic\Kernel\Steps;

use Gtapps\LaravelAgentic\Approvals\ApprovalBroker;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequirement;
use Gtapps\LaravelAgentic\Approvals\Canonicalizer;
use Gtapps\LaravelAgentic\Audit\Redactor;
use Gtapps\LaravelAgentic\Kernel\ActionCall;

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
    public function __construct(
        protected ApprovalBroker $broker,
        protected Redactor $redactor,
        protected ApprovalRequirement $requirement,
    ) {}

    public function __invoke(ActionCall $call): void
    {
        $definition = $call->definition;

        if (! $this->requirement->required($call)) {
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

        throw new ApprovalRequiredException($definition->name, $key, $approval->id);
    }
}
