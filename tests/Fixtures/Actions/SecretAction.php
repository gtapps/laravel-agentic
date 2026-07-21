<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;

#[AgentAction(
    name: 'secret-action',
    description: 'Carries fields that must be redacted from audit and approval payloads.',
    needsApproval: true,
)]
class SecretAction
{
    public function handle(SecretInput $input): string
    {
        return 'done for '.$input->username;
    }
}
