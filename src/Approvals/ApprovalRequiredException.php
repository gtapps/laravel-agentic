<?php

namespace Gtapps\LaravelAgentic\Approvals;

use RuntimeException;

/**
 * Control flow, not failure: each surface maps this to its transport
 * (MCP/ai-tool in-band error, HTTP 409, CLI hint). The message is written
 * for the MODEL — it must know to retry the identical call after approval.
 */
class ApprovalRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $actionName,
        public readonly string $key,
        public readonly string $approvalId,
    ) {
        parent::__construct(
            "Approval required for action '{$actionName}'. Pending under key {$key}. "
            ."Ask a human to run: php artisan agentic:approve {$approvalId}. "
            .'Then retry this exact call unchanged.'
        );
    }
}
