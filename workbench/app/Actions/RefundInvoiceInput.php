<?php

namespace Workbench\App\Actions;

use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

class RefundInvoiceInput extends Data
{
    public function __construct(
        public int $invoiceId,
        #[Min(0.01)]
        public float $amount,
        public string $reason = 'requested_by_customer',
        public bool $notify = true,
    ) {}
}
