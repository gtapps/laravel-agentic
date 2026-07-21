<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\ScanActions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\PingInput;

#[AgentAction(
    name: 'ping',
    description: 'App-layer ping (overrides the package-registered one by name).',
)]
class AppPing
{
    public function handle(PingInput $input): string
    {
        return 'app: '.$input->message;
    }
}
