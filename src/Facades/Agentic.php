<?php

namespace Gtapps\LaravelAgentic\Facades;

use Gtapps\LaravelAgentic\AgenticManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void register(array $classes)
 * @method static \Gtapps\LaravelAgentic\Kernel\ActionResult run(string $name, array $args, \Gtapps\LaravelAgentic\Contracts\ActionContext $context)
 * @method static iterable tools(?array $only = null, ?\Illuminate\Contracts\Auth\Authenticatable $as = null)
 * @method static \Gtapps\LaravelAgentic\Testing\AgenticFake fake()
 *
 * @see AgenticManager
 */
class Agentic extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AgenticManager::class;
    }
}
