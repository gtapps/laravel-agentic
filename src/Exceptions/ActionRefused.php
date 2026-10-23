<?php

namespace Gtapps\LaravelAgentic\Exceptions;

use InvalidArgumentException;
use RuntimeException;

/**
 * A domain refusal the caller should read and act on: a version conflict, an
 * over-limit payload. The message is written for a model and reaches the
 * caller verbatim on every surface, whatever app.debug is. HTTP also carries
 * the error code, the status and the context; the other surfaces carry the
 * message alone. Extend it for app-specific refusals; a subclass that defines
 * its own constructor must call parent::__construct(), which sets every
 * property the surfaces and the audit read.
 *
 * The audit row records the audit reason, which defaults to the message. Pass
 * a separate one when the message carries something that must not land in the
 * audit trail, such as content the caller needs but the log should not keep.
 */
class ActionRefused extends RuntimeException
{
    public readonly string $auditReason;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'refused',
        public readonly int $status = 409,
        public readonly array $context = [],
        ?string $auditReason = null,
    ) {
        if ($status < 400 || $status > 499) {
            throw new InvalidArgumentException("A refusal answers with a 4xx status, not {$status}.");
        }

        parent::__construct($message);

        $this->auditReason = $auditReason ?? $message;
    }
}
