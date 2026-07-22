<?php

namespace Workbench\App\Actions;

use Spatie\LaravelData\Data;

class InvoiceSummary extends Data
{
    public function __construct(
        public int $id,
        public string $customer,
        public float $total,
    ) {}
}
