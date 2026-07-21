<?php

namespace Gtapps\LaravelAgentic\Kernel;

use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Enums\Surface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * The one place contexts are built. Surfaces pass the identity they verified
 * themselves (token user, --as user, job's stored user id) — the kernel never
 * reads ambient session state, so token callers can't inherit cookie scope.
 *
 * @internal
 */
class ContextFactory
{
    public function make(
        Surface $caller,
        ?Authenticatable $user = null,
        ?string $requestId = null,
        ?string $idempotencyKey = null,
    ): ActionContext {
        return new Context($caller, $user, $requestId ?? (string) Str::ulid(), $idempotencyKey);
    }
}
