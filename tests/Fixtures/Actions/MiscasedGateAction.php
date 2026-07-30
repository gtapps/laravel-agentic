<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Enums\Surface;

/**
 * PHP method lookup is case-insensitive, so this spelling gates MCP just as
 * authorizeMcp() would. Pinned because it is the reason the package does not
 * try to lint "miscased" method names.
 */
#[AgentAction(
    name: 'miscased-gate',
    description: 'Gates MCP with an unconventionally cased method name.',
    readOnly: true,
    surfaces: [Surface::Mcp],
)]
class MiscasedGateAction
{
    public function authorizeMCP(): bool
    {
        return false;
    }

    public function handle(PingInput $input): string
    {
        return 'miscased '.$input->message;
    }
}
