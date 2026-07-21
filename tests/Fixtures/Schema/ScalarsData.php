<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class ScalarsData extends Data
{
    public function __construct(
        public string $name,
        public int $count,
        public float $ratio,
        public bool $active,
    ) {}
}
