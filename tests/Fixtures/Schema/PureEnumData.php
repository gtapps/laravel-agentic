<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class PureEnumData extends Data
{
    public function __construct(
        public PureSuit $suit,
    ) {}
}
