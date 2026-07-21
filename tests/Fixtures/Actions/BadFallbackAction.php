<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Enums\Mismatch;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\AddressData;

#[AgentAction(
    name: 'bad-fallback',
    description: 'Declares Fallback but defines no outputFallback() — must be skipped at registration.',
    outputSchema: AddressData::class,
    outputMismatch: Mismatch::Fallback,
)]
class BadFallbackAction
{
    public function handle(): array
    {
        return [];
    }
}
