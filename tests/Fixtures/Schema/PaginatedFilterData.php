<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Schema;

use Gtapps\LaravelAgentic\Pagination\PaginatedInput;

class PaginatedFilterData extends PaginatedInput
{
    public function __construct(public string $status) {}
}
