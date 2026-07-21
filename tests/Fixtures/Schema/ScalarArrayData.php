<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class ScalarArrayData extends Data
{
    public function __construct(
        /** @var int[] */
        public array $ids = [],
        /** @var string[] */
        public array $tags = [],
    ) {}
}
