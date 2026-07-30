<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Enums\Surface;

#[AgentAction(
    name: 'surface-override',
    description: 'Denied everywhere except the CLI, which overrides the generic gate.',
    readOnly: true,
    surfaces: [Surface::Cli, Surface::Http, Surface::Mcp],
)]
class SurfaceOverrideAction
{
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Proves the override widens, not just narrows: the generic gate denies
     * every surface, and this one still runs.
     */
    public function authorizeCli(ActionContext $ctx, PingInput $input): bool
    {
        // Typed params here prove a surface method gets the same container-call
        // bindings as authorize() — context and input by type, order free.
        return $ctx->caller() === Surface::Cli && $input->message !== '';
    }

    public function handle(PingInput $input): string
    {
        return 'override '.$input->message;
    }
}
