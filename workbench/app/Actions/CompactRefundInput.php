<?php

namespace Workbench\App\Actions;

use Spatie\LaravelData\Data;

/**
 * Compact schema shown to models (token economy); the full
 * RefundInvoiceInput still validates every call.
 */
class CompactRefundInput extends Data
{
    public function __construct(
        public int $invoiceId,
        public float $amount,
    ) {}
}
