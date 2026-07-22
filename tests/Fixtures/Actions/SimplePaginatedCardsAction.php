<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Illuminate\Pagination\Paginator;

#[AgentAction(
    name: 'simple-paginated-cards',
    description: 'Returns a simple (non-length-aware) Paginator of CardData items.',
    readOnly: true,
    outputSchema: CardData::class,
)]
class SimplePaginatedCardsAction
{
    public function handle(): Paginator
    {
        return new Paginator(
            items: [new CardData('alice', 'shh'), new CardData('bob', 'psst')],
            perPage: 2,
            currentPage: 1,
        );
    }
}
