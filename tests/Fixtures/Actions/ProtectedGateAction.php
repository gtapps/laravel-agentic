<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Enums\Surface;

/**
 * A gate the container cannot call. Treating it as absent would run the action
 * ungated, so the pipeline reports it instead.
 */
#[AgentAction(
    name: 'protected-gate',
    description: 'Declares a non-public authorize method.',
    readOnly: true,
    surfaces: [Surface::Cli],
)]
class ProtectedGateAction
{
    public function handle(PingInput $input): string
    {
        return 'protected '.$input->message;
    }

    protected function authorizeCli(): bool
    {
        return true;
    }
}
