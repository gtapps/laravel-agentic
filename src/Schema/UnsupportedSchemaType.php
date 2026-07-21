<?php

namespace Gtapps\LaravelAgentic\Schema;

use RuntimeException;

class UnsupportedSchemaType extends RuntimeException
{
    public static function forProperty(string $dtoClass, string $property, string $reason): self
    {
        return new self("Cannot compile schema for {$dtoClass}::\${$property}: {$reason}");
    }

    public static function forClass(string $dtoClass, string $reason): self
    {
        return new self("Cannot compile schema for {$dtoClass}: {$reason}");
    }
}
