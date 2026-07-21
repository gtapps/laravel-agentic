<?php

namespace Gtapps\LaravelAgentic\Kernel\Steps;

use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Gtapps\LaravelAgentic\Kernel\CallsActionMethods;
use Illuminate\Contracts\Container\Container;

/**
 * @internal
 */
class Execute
{
    use CallsActionMethods;

    public function __construct(protected Container $container) {}

    public function __invoke(ActionCall $call): void
    {
        $call->result = $this->callAction($call->handler, 'handle', $call);
    }
}
