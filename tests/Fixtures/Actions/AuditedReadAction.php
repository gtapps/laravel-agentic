<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;

#[AgentAction(
    name: 'audited-read',
    description: 'Read-only action that opts into audit.',
    readOnly: true,
    audit: true,
)]
class AuditedReadAction
{
    public function handle(PingInput $input): string
    {
        return 'read '.$input->message;
    }
}
