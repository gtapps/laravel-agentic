<?php

namespace Gtapps\LaravelAgentic\Approvals;

use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Gtapps\LaravelAgentic\Kernel\CallsActionMethods;
use Illuminate\Contracts\Container\Container;

/**
 * Whether an invocation needs per-invocation consent — readOnly plus the
 * (possibly class-string) predicate, and nothing else.
 *
 * Deliberately does NO broker I/O. Both ApprovalGate and the ai-tool surface's
 * shouldRequestApproval() hook answer this question, and laravel/ai re-runs
 * that hook when a paused run resumes: if the two ever disagreed, or if the
 * answer depended on grant state that changed while a human was deciding, the
 * gated set would shift mid-flight and laravel/ai would either reject the
 * decision map or execute a call nobody consented to.
 *
 * @internal
 */
class ApprovalRequirement
{
    use CallsActionMethods;

    public function __construct(protected Container $container) {}

    /**
     * A throwing predicate fails CLOSED to "approval required".
     */
    public function required(ActionCall $call): bool
    {
        $definition = $call->definition;

        if ($definition->readOnly) {
            return false;
        }

        if (is_bool($definition->needsApproval)) {
            return $definition->needsApproval;
        }

        try {
            $predicate = $this->container->make($definition->needsApproval);

            return (bool) $this->callAction($predicate, '__invoke', $call);
        } catch (\Throwable) {
            return true;
        }
    }
}
