<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class AddressData extends Data
{
    public function __construct(
        public string $street,
        public string $city,
    ) {}
}
