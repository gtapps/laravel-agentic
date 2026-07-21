<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class DefaultsData extends Data
{
    public function __construct(
        public string $name = 'anonymous',
        public int $limit = 10,
        public Suit $suit = Suit::Hearts,
    ) {}
}
