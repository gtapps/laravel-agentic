<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Exceptions\ActionRefused;
use RuntimeException;

#[AgentAction(
    name: 'bare-refusing',
    description: 'Refuses with a subclass whose constructor skips the parent one.',
)]
class BareRefusingAction
{
    public function handle(PingInput $input): never
    {
        throw new class('Stale version.') extends ActionRefused
        {
            public function __construct(string $message)
            {
                RuntimeException::__construct($message);
            }
        };
    }
}
