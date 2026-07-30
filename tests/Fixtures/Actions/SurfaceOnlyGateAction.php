<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Enums\Surface;

/**
 * No generic authorize(). MCP is gated; the CLI is not — an unnamed surface is
 * ungated, exactly as an action with no authorize() at all behaves.
 */
#[AgentAction(
    name: 'surface-only-gate',
    description: 'Gated on MCP only.',
    readOnly: true,
    surfaces: [Surface::Mcp, Surface::Cli],
)]
class SurfaceOnlyGateAction
{
    public function authorizeMcp(): bool
    {
        return false;
    }

    public function handle(PingInput $input): string
    {
        return 'only '.$input->message;
    }
}
