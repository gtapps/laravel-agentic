<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Enums\Surface;

/**
 * The generic gate comes from the base class; only the CLI override is local.
 * Reflection sees inherited methods, so a shared base or trait can supply
 * either half.
 */
#[AgentAction(
    name: 'inherited-gate',
    description: 'Inherits its generic gate and overrides the CLI.',
    readOnly: true,
    surfaces: [Surface::Cli, Surface::Mcp],
)]
class InheritedGateAction extends BaseGatedAction
{
    public function authorizeCli(): bool
    {
        return true;
    }

    public function handle(PingInput $input): string
    {
        return 'inherited '.$input->message;
    }
}
