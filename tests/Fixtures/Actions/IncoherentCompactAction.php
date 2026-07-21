<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\AddressData;

#[AgentAction(
    name: 'incoherent-compact',
    description: 'Compact schema is missing required fields of the full schema — must be skipped.',
    agentInputSchema: PingInput::class,
)]
class IncoherentCompactAction
{
    // Full schema requires street+city; compact (PingInput) only has message.
    public function handle(AddressData $input): array
    {
        return [];
    }
}
