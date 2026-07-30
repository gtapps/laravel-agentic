<?php

namespace Gtapps\LaravelAgentic\Kernel\Steps;

use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Gtapps\LaravelAgentic\Kernel\CallsActionMethods;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use RuntimeException;

/**
 * Runs BEFORE ApprovalGate — no permission escalation through approval.
 *
 * @internal
 */
class Authorize
{
    use CallsActionMethods;

    protected const GENERIC = 'authorize';

    public function __construct(protected Container $container) {}

    public function __invoke(ActionCall $call): void
    {
        $call->handler = $this->container->make($call->definition->handler);

        $method = $this->methodFor($call->handler, $call->context->caller());

        if ($method === null) {
            return;
        }

        $decision = $this->callAction($call->handler, $method, $call);

        if ($decision === true || ($decision instanceof Response && $decision->allowed())) {
            return;
        }

        throw $decision instanceof Response
            ? ActionDenied::fromResponse($call->definition->name, $decision)
            : ActionDenied::forAction($call->definition->name);
    }

    /**
     * The surface's own method wins over the generic one; a surface neither
     * names is ungated, exactly like an action with no authorize() at all.
     *
     * Detection ignores visibility on purpose. Treating a non-public method as
     * absent would fall through to the generic gate — or to no gate — and run
     * an action whose author believed it was gated, so it is reported instead.
     */
    protected function methodFor(object $handler, Surface $surface): ?string
    {
        $reflection = new ReflectionClass($handler);

        foreach ([$surface->authorizeMethod(), static::GENERIC] as $method) {
            if (! $reflection->hasMethod($method)) {
                continue;
            }

            if (! $reflection->getMethod($method)->isPublic()) {
                throw new RuntimeException(sprintf(
                    '%s::%s() must be public to authorize %s calls.',
                    $reflection->getName(),
                    $reflection->getMethod($method)->getName(),
                    $surface->value,
                ));
            }

            return $method;
        }

        return null;
    }
}
