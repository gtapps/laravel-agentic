<?php

namespace Gtapps\LaravelAgentic\Exceptions;

use Illuminate\Auth\Access\Response;
use RuntimeException;

/**
 * Carries three things surfaces need to keep apart: the message the caller is
 * told, the reason the audit row records, and the HTTP status the denial
 * presents as. They differ only for a concealed denial, where the caller is
 * told nothing and the audit row keeps the real reason.
 */
class ActionDenied extends RuntimeException
{
    protected function __construct(
        string $message,
        public readonly string $auditReason,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function forAction(string $name): self
    {
        $message = self::messageFor($name);

        return new self($message, $message, 403);
    }

    /**
     * A denial the policy described, via bool-or-Response authorization.
     *
     * Response::denyAsNotFound() (status 404) hides the action rather than
     * refusing it, so the caller gets the unknown-action wording verbatim and
     * the policy's own message is withheld — returning it would disclose
     * exactly what the 404 exists to conceal. Laravel permits a message there
     * (Response::denyAsNotFound($message)), so this is a deliberate exception
     * to "reasons reach the caller"; the reason still reaches the audit row.
     */
    public static function fromResponse(string $name, Response $response): self
    {
        $message = $response->message();
        $reason = ($message === null || $message === '') ? self::messageFor($name) : $message;
        $status = $response->status() ?? 403;

        return $status === 404
            ? new self(ActionNotFound::messageFor($name), $reason, 404)
            : new self($reason, $reason, $status);
    }

    protected static function messageFor(string $name): string
    {
        return "Not authorized to run action '{$name}'.";
    }
}
