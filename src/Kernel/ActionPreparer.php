<?php

namespace Gtapps\LaravelAgentic\Kernel;

use Gtapps\LaravelAgentic\Kernel\Steps\Authorize;
use Gtapps\LaravelAgentic\Kernel\Steps\Resolve;
use Gtapps\LaravelAgentic\Kernel\Steps\ValidateAndHydrate;
use Illuminate\Contracts\Container\Container;

/**
 * Resolve → ValidateAndHydrate → Authorize: everything that must happen before
 * anyone may ask "does this need approval?", and nothing that executes.
 *
 * Shared by the Runner and the ai-tool surface's approval hook. The hook runs
 * outside the pipeline (laravel/ai calls it before handle()), and the approval
 * predicate is handed the hydrated DTO and context — so the hook cannot answer
 * honestly without this prefix, and must not be allowed to authorize less
 * strictly than the Runner does. Sharing it is what keeps the two in step.
 *
 * Execution stays exclusively in the Runner.
 *
 * @internal
 */
class ActionPreparer
{
    /** @var list<class-string> */
    protected const STEPS = [
        Resolve::class,
        ValidateAndHydrate::class,
        Authorize::class,
    ];

    public function __construct(protected Container $container) {}

    public function prepare(ActionCall $call): ActionCall
    {
        foreach (static::STEPS as $step) {
            $this->container->make($step)($call);
        }

        return $call;
    }
}
