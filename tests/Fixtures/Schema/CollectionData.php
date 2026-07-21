<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class CollectionData extends Data
{
    public function __construct(
        #[DataCollectionOf(AddressData::class)]
        public array $addresses,
    ) {}
}
