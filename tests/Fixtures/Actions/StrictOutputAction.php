<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Enums\Mismatch;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\AddressData;

#[AgentAction(
    name: 'strict-output',
    description: 'Returns the wrong type under a strict output schema.',
    outputSchema: AddressData::class,
    outputMismatch: Mismatch::Strict,
)]
class StrictOutputAction
{
    public function handle(): array
    {
        return ['not' => 'an address'];
    }
}
