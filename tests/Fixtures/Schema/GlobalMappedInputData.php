<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class GlobalMappedInputData extends Data
{
    public function __construct(
        public int $whitelabelId,
    ) {}
}
