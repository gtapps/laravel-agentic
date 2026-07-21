<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;

#[AgentAction(
    name: 'ping',
    description: 'Package-layer ping (should be overridden by an app-scanned action of the same name).',
)]
class PackagePing
{
    public function handle(PingInput $input): string
    {
        return 'package: '.$input->message;
    }
}
