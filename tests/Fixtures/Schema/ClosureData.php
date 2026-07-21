<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Closure;
use Spatie\LaravelData\Data;

class ClosureData extends Data
{
    public function __construct(
        public Closure $callback,
    ) {}
}
