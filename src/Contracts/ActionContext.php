<?php

namespace Gtapps\LaravelAgentic\Contracts;

use Gtapps\LaravelAgentic\Enums\Surface;
use Illuminate\Contracts\Auth\Authenticatable;

interface ActionContext
{
    public function user(): ?Authenticatable;

    public function caller(): Surface;

    public function requestId(): ?string;

    /**
     * A caller-supplied identifier that is stable across retries of the SAME
     * logical invocation — currently laravel/ai's tool-call id. Distinct from
     * requestId(), which is minted fresh per context and so differs between a
     * paused call and its resume. Null on surfaces that have no such id.
     */
    public function idempotencyKey(): ?string;
}
