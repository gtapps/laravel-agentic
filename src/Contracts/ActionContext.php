<?php

namespace Gtapps\LaravelAgentic\Contracts;

use Gtapps\LaravelAgentic\Enums\Surface;
use Illuminate\Contracts\Auth\Authenticatable;

interface ActionContext
{
    public function user(): ?Authenticatable;

    public function caller(): Surface;

    public function requestId(): ?string;
}
