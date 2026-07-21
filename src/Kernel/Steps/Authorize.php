<?php

namespace Gtapps\LaravelAgentic\Kernel\Steps;

use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Gtapps\LaravelAgentic\Kernel\CallsActionMethods;
use Illuminate\Contracts\Container\Container;

/**
 * Runs BEFORE ApprovalGate — no permission escalation through approval.
 *
 * @internal
 */
class Authorize
{
    use CallsActionMethods;

    public function __construct(protected Container $container) {}

    public function __invoke(ActionCall $call): void
    {
        $call->handler = $this->container->make($call->definition->handler);

        if (! method_exists($call->handler, 'authorize')) {
            return;
        }

        if ($this->callAction($call->handler, 'authorize', $call) !== true) {
            throw ActionDenied::forAction($call->definition->name);
        }
    }
}
