<?php

namespace Gtapps\LaravelAgentic\Kernel\Steps;

use Gtapps\LaravelAgentic\Enums\Mismatch;
use Gtapps\LaravelAgentic\Exceptions\OutputSchemaMismatch;
use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * @internal
 */
class NormalizeResult
{
    public function __construct(protected Container $container) {}

    public function __invoke(ActionCall $call): void
    {
        $expected = $call->definition->outputSchema;

        if ($expected === null || $call->result instanceof $expected) {
            return;
        }

        match ($call->definition->outputMismatch) {
            Mismatch::Warn => Log::warning(
                "laravel-agentic: action '{$call->definition->name}' returned ".get_debug_type($call->result)
                .", expected {$expected}."
            ),
            Mismatch::Strict => throw OutputSchemaMismatch::forAction(
                $call->definition->name, $expected, $call->result
            ),
            // Presence of outputFallback() is verified at registration.
            Mismatch::Fallback => $call->result = $this->container->call([$call->handler, 'outputFallback']),
        };
    }
}
