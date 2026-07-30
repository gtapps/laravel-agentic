<?php

namespace Gtapps\LaravelAgentic\Kernel;

use Gtapps\LaravelAgentic\Enums\Surface;
use ReflectionClass;
use ReflectionMethod;

/**
 * The single place the rule "which method gates this surface" lives. Authorize
 * enforces it; agentic:list reports it. Two copies drifted once already, and
 * the report was the copy that lied — it counted methods the pipeline would
 * never call.
 *
 * @internal
 */
class GateResolver
{
    protected const GENERIC = 'authorize';

    /**
     * The surface's own method wins over the generic one; a surface neither
     * names is ungated, exactly like an action with no authorize() at all.
     *
     * Visibility is deliberately not filtered here. Treating a non-public
     * method as absent would fall through to the generic gate — or to no gate —
     * and run an action whose author believed it was gated. Callers report it
     * instead: the pipeline throws, the list column marks it broken.
     */
    public function methodFor(ReflectionClass $handler, Surface $surface): ?ReflectionMethod
    {
        foreach ([$surface->authorizeMethod(), static::GENERIC] as $method) {
            if ($handler->hasMethod($method)) {
                return $handler->getMethod($method);
            }
        }

        return null;
    }
}
