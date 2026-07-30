<?php

namespace Gtapps\LaravelAgentic\Kernel\Steps;

use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Gtapps\LaravelAgentic\Kernel\CallsActionMethods;
use Gtapps\LaravelAgentic\Kernel\GateResolver;
use Illuminate\Auth\Access\AuthorizationException;
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

    public function __construct(
        protected Container $container,
        protected GateResolver $gates,
    ) {}

    public function __invoke(ActionCall $call): void
    {
        $call->handler = $this->container->make($call->definition->handler);

        $method = $this->methodFor($call->handler, $call->context->caller());

        if ($method === null) {
            return;
        }

        try {
            $decision = $this->callAction($call->handler, $method, $call);
        } catch (AuthorizationException $e) {
            // Gate::authorize() and Response::authorize() throw rather than
            // return. Same denial, same reason, same status — so it becomes the
            // same ActionDenied, or the trail would file a refusal as an error
            // and MCP would answer with a fault instead of an in-band denial.
            $decision = $e->toResponse();
        }

        if ($decision === true || ($decision instanceof Response && $decision->allowed())) {
            return;
        }

        throw $decision instanceof Response
            ? ActionDenied::fromResponse($call->definition->name, $decision)
            : ActionDenied::forAction($call->definition->name);
    }

    /**
     * A gate the container cannot call is reported, never skipped — see
     * GateResolver for why visibility is checked here rather than there.
     */
    protected function methodFor(object $handler, Surface $surface): ?string
    {
        $reflection = new ReflectionClass($handler);

        $method = $this->gates->methodFor($reflection, $surface);

        if ($method !== null && ! $method->isPublic()) {
            throw new RuntimeException(sprintf(
                '%s::%s() must be public to authorize %s calls.',
                $reflection->getName(),
                $method->getName(),
                $surface->value,
            ));
        }

        return $method?->getName();
    }
}
