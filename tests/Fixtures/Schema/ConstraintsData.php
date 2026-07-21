<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

class ConstraintsData extends Data
{
    public function __construct(
        #[Min(1), Max(100)]
        public int $quantity,
        #[Max(50)]
        public string $title,
        #[Regex('/^[A-Z]+$/')]
        public string $code,
        #[Email]
        public string $email,
        #[Between(2, 8)]
        public string $slug,
    ) {}
}
