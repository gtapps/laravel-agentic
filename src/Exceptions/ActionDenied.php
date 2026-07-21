<?php

namespace Gtapps\LaravelAgentic\Exceptions;

use RuntimeException;

class ActionDenied extends RuntimeException
{
    public static function forAction(string $name): self
    {
        return new self("Not authorized to run action '{$name}'.");
    }
}
