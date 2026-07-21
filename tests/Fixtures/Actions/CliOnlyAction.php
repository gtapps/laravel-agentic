<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Enums\Surface;
use Illuminate\Contracts\Config\Repository;

#[AgentAction(
    name: 'cli-only',
    description: 'Echoes a message. Exposed on the CLI surface only.',
    readOnly: true,
    surfaces: [Surface::Cli],
)]
class CliOnlyAction
{
    public function handle(PingInput $input, ActionContext $ctx, Repository $config): array
    {
        // $config is here to prove method-injection DI works alongside
        // the type-matched ActionContext + input DTO bindings.
        return [
            'echo' => $input->message,
            'caller' => $ctx->caller()->value,
            'env' => $config->get('app.env'),
        ];
    }
}
