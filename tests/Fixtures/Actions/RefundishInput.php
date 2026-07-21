<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Spatie\LaravelData\Data;

class RefundishInput extends Data
{
    public function __construct(
        public float $amount,
        public string $note = 'none',
    ) {}
}
