<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Contracts\ActionContext;

class CheapCallPredicate
{
    /**
     * Approval only for big amounts — proves predicates get the typed
     * input DTO and context like any action method.
     */
    public function __invoke(RefundishInput $input, ActionContext $ctx): bool
    {
        return $input->amount > 100;
    }
}
