<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class UnionData extends Data
{
    public function __construct(
        public string|int $value,
    ) {}
}
