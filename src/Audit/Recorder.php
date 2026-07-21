<?php

namespace Gtapps\LaravelAgentic\Audit;

use Gtapps\LaravelAgentic\Approvals\Canonicalizer;
use Gtapps\LaravelAgentic\Events\ActionExecuted;
use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Illuminate\Contracts\Config\Repository;

/**
 * One row per non-readOnly run — success, failure, denial, and
 * approval-required alike.
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

        if ($definition === null
            || $definition->readOnly
            || ! $definition->audit
            || ! $this->config->get('agentic.audit.enabled', true)) {
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
            'duration_ms' => (int) round((microtime(true) - $call->startedAt) * 1000),
        ]);

        if ($status === 'ok') {
            event(new ActionExecuted($log));
        }
    }
}
