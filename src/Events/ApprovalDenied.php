<?php

namespace Gtapps\LaravelAgentic\Events;

use Gtapps\LaravelAgentic\Approvals\Approval;

class ApprovalDenied
{
    public function __construct(
        public readonly Approval $approval,
    ) {}
}
