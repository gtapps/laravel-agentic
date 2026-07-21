<?php

namespace Gtapps\LaravelAgentic\Kernel;

use Gtapps\LaravelAgentic\Contracts\ActionContext;

/**
 * Container-call, type-matched invocation of action methods:
 * ActionContext and the input DTO are bound by type, parameter order is free,
 * params may be omitted, and remaining dependencies get method-injection DI.
 *
 * @internal
 */
trait CallsActionMethods
{
    protected function callAction(object $instance, string $method, ActionCall $call): mixed
    {
        $parameters = [ActionContext::class => $call->context];

        if ($call->input !== null) {
            $parameters[get_class($call->input)] = $call->input;
        }

        return $this->container->call([$instance, $method], $parameters);
    }
}
