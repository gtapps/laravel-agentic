<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use RuntimeException;

class ThrowingPredicate
{
    public function __invoke(): bool
    {
        throw new RuntimeException('predicate blew up');
    }
}
