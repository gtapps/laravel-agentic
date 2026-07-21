<?php

namespace Gtapps\LaravelAgentic\Events;

use Gtapps\LaravelAgentic\Audit\ActionLog;

class ActionExecuted
{
    public function __construct(
        public readonly ActionLog $log,
    ) {}
}
