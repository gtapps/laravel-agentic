<?php

namespace Gtapps\LaravelAgentic\Audit;

use Gtapps\LaravelAgentic\Approvals\Canonicalizer;
use Gtapps\LaravelAgentic\Events\ActionExecuted;
use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;

/**
 * One row per audited run — success, failure, denial, and approval-required
 * alike. Non-readOnly actions audit by default; readOnly actions opt in via
 * #[AgentAction(audit: true)].
 *
 * Append-only, no retention/archival in v1 — the audit trail grows without
 * bound by design; revisit if it becomes an operational concern.
 */
class Recorder
{
    public function __construct(
        protected Redactor $redactor,
        protected Repository $config,
    ) {}

    public function record(ActionCall $call, string $status, ?string $error = null): void
    {
        $definition = $call->definition;

        if ($definition === null || ! $definition->isAuditEffective($this->config)) {
            return;
        }

        $args = Canonicalizer::withDefaults($definition, $call->rawArgs);

        $log = ActionLog::create([
            'action' => $definition->name,
            'surface' => $call->context->caller()->value,
            'user_id' => $call->context->user()?->getAuthIdentifier(),
            'args' => $this->redactor->redact($args),
            'args_hash' => Canonicalizer::key($definition, $call->rawArgs),
            'status' => $status,
            'error' => $error,
            'approval_id' => $call->approvalId,
            'definition_hash' => $definition->definitionHash,
            'request_id' => $call->context->requestId(),
            'idempotency_key' => $call->context->idempotencyKey(),
            'duration_ms' => (int) round((microtime(true) - $call->startedAt) * 1000),
        ]);

        if ($status === 'ok') {
            event(new ActionExecuted($log));
        }
    }

    /**
     * record(), for callers who must not be derailed by a failing recorder:
     * one that is already reporting an error, or one whose run is already
     * paused waiting on a human. They are owed the real outcome rather than
     * an audit-infrastructure exception, so the failure is logged and
     * swallowed. Callers on the success path use record() and let it
     * propagate — an audited action with no row is an integrity hole.
     */
    public function recordSafely(ActionCall $call, string $status, ?string $error = null): void
    {
        try {
            $this->record($call, $status, $error);
        } catch (\Throwable $e) {
            Log::warning("laravel-agentic: audit recording failed for {$call->name} ({$status}): {$e->getMessage()}", [
                'action' => $call->name,
                'status' => $status,
                'request_id' => $call->context->requestId(),
                'exception' => $e,
            ]);
        }
    }
}
