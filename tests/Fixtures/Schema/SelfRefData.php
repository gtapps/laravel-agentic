<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class SelfRefData extends Data
{
    public function __construct(
        public string $name,
        public ?SelfRefData $parent,
    ) {}
}
