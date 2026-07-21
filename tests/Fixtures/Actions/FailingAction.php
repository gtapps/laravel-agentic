<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use RuntimeException;

#[AgentAction(
    name: 'failing',
    description: 'Handler always throws — audit must still record the run.',
)]
class FailingAction
{
    public function handle(PingInput $input): never
    {
        throw new RuntimeException('handler exploded');
    }
}
