<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class MapArrayData extends Data
{
    public function __construct(
        /** @var array<string, bool> */
        public array $flags = [],
    ) {}
}
