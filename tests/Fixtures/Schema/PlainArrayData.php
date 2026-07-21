<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class PlainArrayData extends Data
{
    public function __construct(
        public array $tags,
    ) {}
}
