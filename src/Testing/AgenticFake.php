<?php

namespace Gtapps\LaravelAgentic\Testing;

use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Kernel\ActionResult;
use Gtapps\LaravelAgentic\Kernel\Registry;
use Gtapps\LaravelAgentic\Kernel\Runner;
use PHPUnit\Framework\Assert;

/**
 * Swapped in for the Runner by Agentic::fake(): records calls instead of
 * executing them, returns configurable results, and can simulate knocks.
 */
class AgenticFake extends Runner
{
    /** @var list<array{name: string, args: array, context: ActionContext}> */
    protected array $ran = [];

    /** @var list<string> */
    protected array $approvalsRequested = [];

    /** @var array<string, mixed> */
    protected array $results = [];

    /** @var list<string> */
    protected array $requireApproval = [];

    public function __construct(protected Registry $registry) {}

    /**
     * Configure the value the fake returns for an action.
     */
    public function result(string $name, mixed $value): static
    {
        $this->results[$name] = $value;

        return $this;
    }

    /**
     * Make calls to this action knock instead of running.
     */
    public function requireApprovalFor(string $name): static
    {
        $this->requireApproval[] = $name;

        return $this;
    }

    public function run(string $name, array $rawArgs, ActionContext $context): ActionResult
    {
        if (in_array($name, $this->requireApproval, true)) {
            $this->approvalsRequested[] = $name;

            throw new ApprovalRequiredException($name, hash('sha256', $name."\0".json_encode($rawArgs)));
        }

        $this->ran[] = ['name' => $name, 'args' => $rawArgs, 'context' => $context];

        return new ActionResult($this->results[$name] ?? null);
    }

    /**
     * @param  callable(array): bool|null  $argsCallback
     */
    public function assertRan(string $name, ?callable $argsCallback = null): void
    {
        $matching = array_filter(
            $this->ran,
            fn (array $run) => $run['name'] === $name
                && ($argsCallback === null || $argsCallback($run['args']))
        );

        Assert::assertNotEmpty($matching, "Expected action [{$name}] to have run, but it did not.");
    }

    public function assertNothingRan(): void
    {
        Assert::assertSame([], $this->ran, 'Expected no actions to have run.');
    }

    public function assertApprovalRequested(string $name): void
    {
        Assert::assertContains($name, $this->approvalsRequested, "Expected an approval knock for [{$name}].");
    }

    /**
     * The fake never writes audit rows; audited-here means the action ran
     * AND its real definition resolved to audit — non-readOnly by default,
     * readOnly only with #[AgentAction(audit: true)].
     */
    public function assertAudited(string $name): void
    {
        $this->assertRan($name);

        $definition = $this->registry->find($name);

        Assert::assertNotNull($definition, "Action [{$name}] is not registered.");
        Assert::assertTrue($definition->audit, "Action [{$name}] is not audited.");
    }
}
