<?php

namespace Gtapps\LaravelAgentic\Exceptions;

use RuntimeException;

class OutputSchemaMismatch extends RuntimeException
{
    public static function forAction(string $name, string $expected, mixed $actual): self
    {
        $type = get_debug_type($actual);

        return new self("Action '{$name}' returned {$type}; expected {$expected} (outputMismatch: strict).");
    }
}
