<?php

namespace Gtapps\LaravelAgentic\Kernel\Steps;

use Gtapps\LaravelAgentic\Exceptions\ActionNotFound;
use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Gtapps\LaravelAgentic\Kernel\Registry;

/**
 * @internal
 */
class Resolve
{
    public function __construct(protected Registry $registry) {}

    public function __invoke(ActionCall $call): void
    {
        $definition = $this->registry->find($call->name);

        // Not-exposed reads as not-found: don't leak existence to a surface
        // the action was never meant for.
        if ($definition === null || ! $definition->exposedTo($call->context->caller())) {
            throw ActionNotFound::named($call->name);
        }

        $call->definition = $definition;
    }
}
