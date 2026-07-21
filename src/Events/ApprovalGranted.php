<?php

namespace Gtapps\LaravelAgentic\Events;

use Gtapps\LaravelAgentic\Approvals\Approval;

class ApprovalGranted
{
    public function __construct(
        public readonly Approval $approval,
    ) {}
}
