<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Illuminate\Pagination\LengthAwarePaginator;

#[AgentAction(
    name: 'paginated-no-schema',
    description: 'Returns a paginator with no outputSchema declared; must pass through untouched.',
    readOnly: true,
)]
class PaginatedNoSchemaAction
{
    public function handle(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: [new CardData('alice', 'shh')],
            total: 1,
            perPage: 2,
            currentPage: 1,
        );
    }
}
