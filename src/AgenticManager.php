<?php

namespace Gtapps\LaravelAgentic;

use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Kernel\ActionResult;
use Gtapps\LaravelAgentic\Kernel\Registry;
use Gtapps\LaravelAgentic\Kernel\Runner;
use Gtapps\LaravelAgentic\Surfaces\AiTool\ActionToolAdapter;
use Gtapps\LaravelAgentic\Surfaces\AiTool\PendingApprovalMapper;
use Gtapps\LaravelAgentic\Testing\AgenticFake;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;

class AgenticManager
{
    public function __construct(
        protected Container $container,
        protected Registry $registry,
    ) {}

    /**
     * @param  list<class-string>  $classes
     */
    public function register(array $classes): void
    {
        $this->registry->register($classes);
    }

    /**
     * Thin passthrough to the Runner — used by tests and the Jobs surface.
     * Resolved per-call so Agentic::fake() can swap the binding.
     */
    public function run(string $name, array $args, ActionContext $context): ActionResult
    {
        return $this->container->make(Runner::class)->run($name, $args, $context);
    }

    /**
     * Swap the Runner for a recording fake; subsequent runs on any surface
     * are captured instead of executed.
     */
    public function fake(): AgenticFake
    {
        $fake = new AgenticFake($this->registry, $this->container->make(Repository::class));

        $this->container->instance(Runner::class, $fake);

        return $fake;
    }

    /**
     * The decisions laravel/ai needs to resume a run it paused for approval,
     * read from the broker so consent given once — over CLI, or any channel
     * wired to ApprovalRequested — is what releases the call.
     *
     * Returns null while any gated call is still unanswered; resume only once
     * it returns a map:
     *
     *     if ($response->hasPendingApprovals()) {
     *         $decisions = Agentic::approvalDecisions($response->pendingApprovals, $user);
     *
     *         if ($decisions !== null) {
     *             $response = $agent->continue($response->conversationId, $user)->prompt($decisions);
     *         }
     *     }
     *
     * @param  iterable<PendingApproval>  $pendingApprovals
     */
    public function approvalDecisions(iterable $pendingApprovals, ?Authenticatable $as = null): ?Decisions
    {
        return $this->container->make(PendingApprovalMapper::class)->decisions($pendingApprovals, $as);
    }

    /**
     * laravel/ai tool adapters for the registered actions, for use in any
     * agent's tools() iterable.
     *
     * @param  list<string>|null  $only  restrict to these action names
     * @param  ?Authenticatable  $as  explicit principal for the running
     *                                conversation; falls back to the ambient
     *                                guard when omitted
     * @return iterable<ActionToolAdapter>
     */
    public function tools(?array $only = null, ?Authenticatable $as = null): iterable
    {
        foreach ($this->registry->definitions() as $definition) {
            if (! $definition->exposedTo(Surface::AiTool)) {
                continue;
            }

            if ($only !== null && ! in_array($definition->name, $only, true)) {
                continue;
            }

            yield $this->container->make(ActionToolAdapter::class, [
                'definition' => $definition,
                'principal' => $as,
            ]);
        }
    }
}
