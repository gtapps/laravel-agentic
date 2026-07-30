<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

/**
 * Supplies the generic gate that InheritedGateAction inherits.
 */
abstract class BaseGatedAction
{
    public function authorize(): bool
    {
        return false;
    }
}
