<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Spatie\LaravelData\Data;

class CardData extends Data
{
    public function __construct(
        public string $holder,
        public string $secret,
    ) {}
}
