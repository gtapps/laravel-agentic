<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Enums\Mismatch;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\AddressData;

#[AgentAction(
    name: 'fallback-output',
    description: 'Returns the wrong type under a fallback output schema.',
    outputSchema: AddressData::class,
    outputMismatch: Mismatch::Fallback,
)]
class FallbackOutputAction
{
    public function handle(): array
    {
        return ['not' => 'an address'];
    }

    public function outputFallback(): mixed
    {
        return new AddressData(street: 'fallback street', city: 'fallback city');
    }
}
