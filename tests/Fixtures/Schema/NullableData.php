<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class NullableData extends Data
{
    public function __construct(
        public ?string $note,
        public ?Suit $suit = null,
    ) {}
}
