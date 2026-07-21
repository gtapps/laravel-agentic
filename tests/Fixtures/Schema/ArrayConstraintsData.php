<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class ArrayConstraintsData extends Data
{
    public function __construct(
        #[Max(2)]
        /** @var int[] */
        public array $ids = [],
        #[Between(1, 3)]
        /** @var string[] */
        public array $tags = [],
    ) {}
}
