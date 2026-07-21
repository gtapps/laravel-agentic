<?php

namespace Gtapps\LaravelAgentic\Kernel;

final class ActionResult
{
    public function __construct(
        public readonly mixed $value,
    ) {}
}
