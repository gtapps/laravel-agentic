<?php

namespace Gtapps\LaravelAgentic\Kernel;

use Gtapps\LaravelAgentic\Contracts\ActionContext;

/**
 * Mutable envelope threaded through the Runner pipeline steps.
 *
 * @internal
 */
final class ActionCall
{
    public ?ActionDefinition $definition = null;

    public ?object $input = null;

    public ?object $handler = null;

    public mixed $result = null;

    public ?string $approvalId = null;

    public readonly float $startedAt;

    public function __construct(
        public readonly string $name,
        public readonly array $rawArgs,
        public readonly ActionContext $context,
    ) {
        $this->startedAt = microtime(true);
    }
}
