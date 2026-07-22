<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\PaginatedDataCollection;

#[AgentAction(
    name: 'paginated-data-collection-cards',
    description: 'Returns an already-built spatie PaginatedDataCollection of CardData items.',
    readOnly: true,
    outputSchema: CardData::class,
)]
class PaginatedDataCollectionCardsAction
{
    public function handle(): PaginatedDataCollection
    {
        $paginator = new LengthAwarePaginator(
            items: [new CardData('alice', 'shh'), new CardData('bob', 'psst')],
            total: 5,
            perPage: 2,
            currentPage: 2,
        );

        return CardData::collect($paginator, PaginatedDataCollection::class);
    }
}
