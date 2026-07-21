<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class NestedData extends Data
{
    public function __construct(
        public string $label,
        public AddressData $address,
    ) {}
}
