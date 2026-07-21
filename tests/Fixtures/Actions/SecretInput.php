<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Spatie\LaravelData\Data;

class SecretInput extends Data
{
    public function __construct(
        public string $username,
        public string $password,
        public CardData $card,
    ) {}
}
