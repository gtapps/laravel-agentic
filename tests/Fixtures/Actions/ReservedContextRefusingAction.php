<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Exceptions\ActionRefused;

#[AgentAction(
    name: 'reserved-context-refusing',
    description: 'Refuses with context keys that collide with the fixed HTTP keys.',
)]
class ReservedContextRefusingAction
{
    public function handle(PingInput $input): never
    {
        throw new ActionRefused('Payload too large.', 'too_large', 413, [
            'status' => 'ok',
            'error' => 'none',
            'message' => 'all good',
            'limit' => 10,
        ]);
    }
}
