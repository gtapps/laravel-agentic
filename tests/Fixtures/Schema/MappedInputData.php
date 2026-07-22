<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
class MappedInputData extends Data
{
    public function __construct(
        public int $whitelabelId,
        public string $displayName = 'anon',
    ) {}
}
