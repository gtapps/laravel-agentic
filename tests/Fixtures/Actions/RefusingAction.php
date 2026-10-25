<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Exceptions\ActionRefused;

#[AgentAction(
    name: 'refusing',
    description: 'Handler always refuses for a domain reason.',
)]
class RefusingAction
{
    public function handle(PingInput $input): never
    {
        throw new ActionRefused('Version conflict: retry with baseVersion 2.', 'conflict', 409, ['currentVersion' => 2]);
    }
}
