<?php

namespace Gtapps\LaravelAgentic\Exceptions;

use RuntimeException;

class RequestedUserNotFound extends RuntimeException
{
    public static function forId(string|int $id): self
    {
        return new self("No user found for requested id '{$id}'.");
    }
}
