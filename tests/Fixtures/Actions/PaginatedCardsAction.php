<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Illuminate\Pagination\LengthAwarePaginator;

#[AgentAction(
    name: 'paginated-cards',
    description: 'Returns a raw LengthAwarePaginator of already-hydrated CardData items.',
    readOnly: true,
    outputSchema: CardData::class,
)]
class PaginatedCardsAction
{
    public function handle(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: [new CardData('alice', 'shh'), new CardData('bob', 'psst')],
            total: 5,
            perPage: 2,
            currentPage: 2,
        );
    }
}
