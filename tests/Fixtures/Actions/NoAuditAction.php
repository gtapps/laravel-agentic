<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;

#[AgentAction(
    name: 'no-audit',
    description: 'Mutating action that opted out of audit.',
    audit: false,
)]
class NoAuditAction
{
    public function handle(PingInput $input): string
    {
        return 'quiet '.$input->message;
    }
}
