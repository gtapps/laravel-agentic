<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class NestedArrayData extends Data
{
    public function __construct(
        /** @var int[][] */
        public array $matrix = [],
    ) {}
}
