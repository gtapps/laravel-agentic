<?php

namespace Gtapps\LaravelAgentic\Kernel\Steps;

use Gtapps\LaravelAgentic\Enums\Mismatch;
use Gtapps\LaravelAgentic\Exceptions\OutputSchemaMismatch;
use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Illuminate\Contracts\Container\Container;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\PaginatedDataCollection;

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

        $envelope = $this->normalizePaginated($call->result, $expected);

        if ($envelope !== null) {
            $call->result = $envelope;

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

    /**
     * Delegate paginated results to spatie/laravel-data's own envelope
     * (TransformedDataCollectableResolver::transformPaginator) instead of
     * building one ourselves. Returns null when the result isn't a paginated
     * collection of $expected items, so the caller falls through to the
     * mismatch policy. The paginator path is pinned to '/' so link URLs are
     * deterministic across every surface.
     *
     * @return array<string, mixed>|null
     */
    protected function normalizePaginated(mixed $result, string $expected): ?array
    {
        if ($result instanceof PaginatedDataCollection || $result instanceof CursorPaginatedDataCollection) {
            if (! is_a($result->dataClass, $expected, true)) {
                return null;
            }

            $result->items()->withPath('/');

            return $result->toArray();
        }

        if ($result instanceof AbstractPaginator || $result instanceof AbstractCursorPaginator) {
            foreach ($result->items() as $item) {
                if (! $item instanceof $expected) {
                    return null;
                }
            }

            $result->withPath('/');

            $into = $result instanceof AbstractCursorPaginator
                ? CursorPaginatedDataCollection::class
                : PaginatedDataCollection::class;

            return $expected::collect($result, $into)->toArray();
        }

        return null;
    }
}
