<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Data;

class EnumData extends Data
{
    public function __construct(
        public Suit $suit,
        public Priority $priority,
    ) {}
}
