<?php

namespace Gtapps\LaravelAgentic\Kernel;

use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Enums\Surface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * @internal
 */
final class Context implements ActionContext
{
    public function __construct(
        protected Surface $caller,
        protected ?Authenticatable $user = null,
        protected ?string $requestId = null,
    ) {}

    public function user(): ?Authenticatable
    {
        return $this->user;
    }

    public function caller(): Surface
    {
        return $this->caller;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }
}
