<?php

namespace Gtapps\LaravelAgentic\Kernel;

use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Audit\Recorder;
use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Kernel\Steps\ApprovalGate;
use Gtapps\LaravelAgentic\Kernel\Steps\Authorize;
use Gtapps\LaravelAgentic\Kernel\Steps\Execute;
use Gtapps\LaravelAgentic\Kernel\Steps\NormalizeResult;
use Gtapps\LaravelAgentic\Kernel\Steps\Resolve;
use Gtapps\LaravelAgentic\Kernel\Steps\ValidateAndHydrate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * The one chokepoint: every surface funnels into run(), and all
 * guarantees live inside it. Fixed step order, not user-configurable.
 * Audit wraps the pipeline so denials, knocks, and failures are recorded
 * alongside successes.
 */
class Runner
{
    /** @var list<class-string> */
    protected const STEPS = [
        Resolve::class,
        ValidateAndHydrate::class,
        Authorize::class,
        ApprovalGate::class,
        Execute::class,
        NormalizeResult::class,
    ];

    public function __construct(
        protected Container $container,
        protected Recorder $recorder,
    ) {}

    public function run(string $name, array $rawArgs, ActionContext $context): ActionResult
    {
        $call = new ActionCall($name, $rawArgs, $context);

        try {
            foreach (static::STEPS as $step) {
                $this->container->make($step)($call);
            }
        } catch (\Throwable $e) {
            try {
                $this->recorder->record($call, match (true) {
                    $e instanceof ApprovalRequiredException => 'approval_required',
                    $e instanceof ActionDenied => 'denied',
                    default => 'error',
                }, $e->getMessage());
            } catch (\Throwable $recorderError) {
                Log::warning("laravel-agentic: audit recording failed: {$recorderError->getMessage()}");
            }

            throw $e;
        }

        $this->recorder->record($call, 'ok');

        return new ActionResult($call->result);
    }
}
