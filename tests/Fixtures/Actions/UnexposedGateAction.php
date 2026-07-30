<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Enums\Surface;

/**
 * Gates a surface it is not exposed on. The gate can never run, so the CLI —
 * the only surface that answers — is ungated.
 */
#[AgentAction(
    name: 'unexposed-gate',
    description: 'Gates MCP while exposed on the CLI only.',
    readOnly: true,
    surfaces: [Surface::Cli],
)]
class UnexposedGateAction
{
    public function authorizeMcp(): bool
    {
        return false;
    }

    public function handle(PingInput $input): string
    {
        return 'unexposed '.$input->message;
    }
}
