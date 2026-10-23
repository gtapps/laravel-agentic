<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Exceptions\ActionRefused;

#[AgentAction(
    name: 'subclass-refusing',
    description: 'Refuses with an app subclass that has its own constructor.',
)]
class SubclassRefusingAction
{
    public function handle(PingInput $input): never
    {
        throw new class(3) extends ActionRefused
        {
            public function __construct(int $currentVersion)
            {
                parent::__construct(
                    "Stale: the current version is {$currentVersion}.",
                    'conflict',
                    context: ['currentVersion' => $currentVersion],
                    auditReason: 'Stale version.',
                );
            }
        };
    }
}
