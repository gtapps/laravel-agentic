<?php

namespace Gtapps\LaravelAgentic\Exceptions;

use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use RuntimeException;

class OutputSchemaMismatch extends RuntimeException
{
    public static function forAction(string $name, string $expected, mixed $actual): self
    {
        $type = get_debug_type($actual);

        $hint = ($actual instanceof AbstractPaginator || $actual instanceof AbstractCursorPaginator)
            ? " Its items could not be hydrated into {$expected}; return items shaped like {$expected} (or {$expected}::collect(\$paginator))."
            : '';

        return new self("Action '{$name}' returned {$type}; expected {$expected} (outputMismatch: strict).{$hint}");
    }
}
