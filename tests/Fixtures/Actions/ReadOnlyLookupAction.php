<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;

#[AgentAction(
    name: 'lookup',
    description: 'Read-only lookup, GET-able over HTTP.',
    readOnly: true,
)]
class ReadOnlyLookupAction
{
    public function handle(PingInput $input): array
    {
        return ['found' => $input->message];
    }
}
