<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Spatie\LaravelData\Data;

class PingInput extends Data
{
    public function __construct(
        public string $message,
    ) {}
}
