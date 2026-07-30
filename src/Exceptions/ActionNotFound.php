<?php

namespace Gtapps\LaravelAgentic\Exceptions;

use RuntimeException;

class ActionNotFound extends RuntimeException
{
    public static function named(string $name): self
    {
        return new self(self::messageFor($name));
    }

    /**
     * Shared so a concealed denial (Response::denyAsNotFound()) can word itself
     * identically. If the two drifted, the wording would tell a caller which of
     * the two it hit — the one thing concealment must not do.
     */
    public static function messageFor(string $name): string
    {
        return "Unknown action '{$name}'. It does not exist or is not exposed on this surface.";
    }
}
