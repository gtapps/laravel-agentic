<?php

namespace Gtapps\LaravelAgentic\Exceptions;

use RuntimeException;

class ActionNotFound extends RuntimeException
{
    public static function named(string $name): self
    {
        return new self("Unknown action '{$name}'. It does not exist or is not exposed on this surface.");
    }
}
