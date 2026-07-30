<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Enums\Surface;

/**
 * Authorization is strict: only boolean true or an allowed Gate response opens
 * the gate. A truthy value is a denial, not an approval.
 */
#[AgentAction(
    name: 'truthy-gate',
    description: 'Returns a truthy non-true value from its gate.',
    readOnly: true,
    surfaces: [Surface::Cli],
)]
class TruthyGateAction
{
    public function authorize(): mixed
    {
        return 1;
    }

    public function handle(PingInput $input): string
    {
        return 'truthy '.$input->message;
    }
}
