<?php

namespace Workbench\App\Actions;

use Spatie\LaravelData\Data;

class RefundResult extends Data
{
    public function __construct(
        public int $invoiceId,
        public float $amount,
        public string $status,
    ) {}
}
