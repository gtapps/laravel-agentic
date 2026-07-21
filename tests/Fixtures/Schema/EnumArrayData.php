<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class EnumArrayData extends Data
{
    public function __construct(
        /** @var Suit[] */
        public array $suits = [],
    ) {}
}
