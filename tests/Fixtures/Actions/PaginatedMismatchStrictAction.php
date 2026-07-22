<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Enums\Mismatch;
use Illuminate\Pagination\LengthAwarePaginator;

#[AgentAction(
    name: 'paginated-mismatch-strict',
    description: 'Returns a paginator of items that are not the declared outputSchema, under Strict.',
    readOnly: true,
    outputSchema: CardData::class,
    outputMismatch: Mismatch::Strict,
)]
class PaginatedMismatchStrictAction
{
    public function handle(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: [['not' => 'a card']],
            total: 1,
            perPage: 2,
            currentPage: 1,
        );
    }
}
