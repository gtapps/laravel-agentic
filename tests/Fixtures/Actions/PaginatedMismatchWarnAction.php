<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Illuminate\Pagination\LengthAwarePaginator;

#[AgentAction(
    name: 'paginated-mismatch-warn',
    description: 'Returns a paginator of items that are not the declared outputSchema, under the default Warn.',
    readOnly: true,
    outputSchema: CardData::class,
)]
class PaginatedMismatchWarnAction
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
