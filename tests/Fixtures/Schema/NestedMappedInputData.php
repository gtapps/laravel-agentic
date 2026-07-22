<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class NestedMappedInputData extends Data
{
    public function __construct(
        public MappedInputData $account,
    ) {}
}
