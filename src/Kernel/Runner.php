<?php

namespace Gtapps\LaravelAgentic\Kernel;

use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Audit\Recorder;
use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Kernel\Steps\ApprovalGate;
use Gtapps\LaravelAgentic\Kernel\Steps\Execute;
use Gtapps\LaravelAgentic\Kernel\Steps\NormalizeResult;
use Illuminate\Contracts\Container\Container;

/**
 * The one chokepoint: every surface funnels into run(), and all
 * guarantees live inside it. Fixed step order, not user-configurable.
 * Audit wraps the pipeline so denials, knocks, and failures are recorded
 * alongside successes.
 *
 * The two record() calls treat a failing Recorder differently, on purpose.
 * On the error path a recorder failure is logged and swallowed, because the
 * caller is owed the exception the pipeline actually raised — masking it with
 * an audit-infrastructure error loses the real cause. On the success path a
 * recorder failure propagates: an audited action that completed with no row
 * written is an audit-integrity hole, and the caller must hear about it rather
 * than receive a success that the trail has no record of.
 */
class Runner
{
    /**
     * The steps that run after ActionPreparer's Resolve → ValidateAndHydrate →
     * Authorize prefix. The full order is unchanged and still not configurable;
     * only the prefix moved, so the ai-tool approval hook can reuse it without
     * gaining the ability to execute anything.
     *
     * @var list<class-string>
     */
    protected const STEPS = [
        ApprovalGate::class,
        Execute::class,
        NormalizeResult::class,
    ];

    public function __construct(
        protected Container $container,
        protected Recorder $recorder,
        protected ActionPreparer $preparer,
    ) {}

    public function run(string $name, array $rawArgs, ActionContext $context): ActionResult
    {
        $call = new ActionCall($name, $rawArgs, $context);

        try {
            $this->preparer->prepare($call);

            foreach (static::STEPS as $step) {
                $this->container->make($step)($call);
            }
        } catch (\Throwable $e) {
            $status = match (true) {
                $e instanceof ApprovalRequiredException => 'approval_required',
                $e instanceof ActionDenied => 'denied',
                default => 'error',
            };

            $this->recorder->recordSafely($call, $status, $e->getMessage());

            throw $e;
        }

        $this->recorder->record($call, 'ok');

        return new ActionResult($call->result);
    }
}
