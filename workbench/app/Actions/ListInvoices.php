<?php

namespace Workbench\App\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Illuminate\Pagination\LengthAwarePaginator;

#[AgentAction(
    name: 'list-invoices',
    description: 'List invoices, paginated.',
    readOnly: true,
    audit: true,
    outputSchema: InvoiceSummary::class,
)]
class ListInvoices
{
    /** @var list<array{id: int, customer: string, total: float}> */
    private const INVOICES = [
        ['id' => 1, 'customer' => 'Acme Co', 'total' => 100.0],
        ['id' => 2, 'customer' => 'Globex', 'total' => 250.5],
        ['id' => 3, 'customer' => 'Initech', 'total' => 75.25],
        ['id' => 4, 'customer' => 'Umbrella', 'total' => 500.0],
        ['id' => 5, 'customer' => 'Soylent', 'total' => 30.0],
    ];

    public function handle(ListInvoicesInput $input): LengthAwarePaginator
    {
        $slice = array_slice(self::INVOICES, ($input->page - 1) * $input->perPage, $input->perPage);

        return new LengthAwarePaginator(
            items: array_map(fn (array $row) => InvoiceSummary::from($row), $slice),
            total: count(self::INVOICES),
            perPage: $input->perPage,
            currentPage: $input->page,
        );
    }
}
