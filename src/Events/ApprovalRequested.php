<?php

namespace Gtapps\LaravelAgentic\Events;

use Gtapps\LaravelAgentic\Approvals\Approval;

class ApprovalRequested
{
    /**
     * $token is the plaintext capability token — delivered ONLY here so the
     * app can wire its own notification channel; the DB stores its hash.
     */
    public function __construct(
        public readonly Approval $approval,
        public readonly string $token,
    ) {}
}
