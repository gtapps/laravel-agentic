<?php

namespace Gtapps\LaravelAgentic\Exceptions;

use RuntimeException;

/**
 * A domain refusal the caller should read and act on: a version conflict, an
 * over-limit payload. The message is written for a model and reaches the
 * caller verbatim on every surface, whatever app.debug is. The error code,
 * HTTP status and context are the machine-readable half, rendered the same on
 * every surface that can carry them. Extend it for app-specific refusals.
 */
class ActionRefused extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'refused',
        public readonly int $status = 409,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
