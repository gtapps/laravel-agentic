<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Illuminate\Pagination\LengthAwarePaginator;

#[AgentAction(
    name: 'paginated-raw-array-cards',
    description: 'Returns a paginator of plain arrays (not CardData instances) to be hydrated into the outputSchema.',
    readOnly: true,
    outputSchema: CardData::class,
)]
class PaginatedRawArrayCardsAction
{
    public function handle(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: [
                ['holder' => 'alice', 'secret' => 'shh'],
                ['holder' => 'bob', 'secret' => 'psst'],
            ],
            total: 5,
            perPage: 2,
            currentPage: 2,
        );
    }
}
