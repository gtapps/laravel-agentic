<?php

use Gtapps\LaravelAgentic\Approvals\Approval;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Surfaces\AiTool\ActionToolAdapter;
use Gtapps\LaravelAgentic\Tests\HttpEnabledTestCase;
use Gtapps\LaravelAgentic\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\User;

// ParityTest and HttpSurfaceTest exercise the HTTP surface, which is
// disabled at provider boot by default — too early for beforeEach() config.
// They get HttpEnabledTestCase (enables it in getEnvironmentSetUp, before
// boot); everything else uses the base TestCase. Pest allows only one real
// TestCase class per file, so these two are excluded from the directory-wide
// default below rather than overridden per-file.
uses(HttpEnabledTestCase::class)->in(
    __DIR__.'/ParityTest.php',
    __DIR__.'/Surfaces/HttpSurfaceTest.php',
);

uses(TestCase::class)->in(
    __DIR__.'/Approvals',
    __DIR__.'/Audit',
    __DIR__.'/Kernel',
    __DIR__.'/Schema',
    __DIR__.'/Testing',
    __DIR__.'/SkeletonTest.php',
    __DIR__.'/Surfaces/AiToolApprovalTest.php',
    __DIR__.'/Surfaces/AiToolSurfaceTest.php',
    __DIR__.'/Surfaces/CliSurfaceTest.php',
    __DIR__.'/Surfaces/HttpRegistrationTest.php',
    __DIR__.'/Surfaces/JobSurfaceTest.php',
    __DIR__.'/Surfaces/McpSurfaceTest.php',
);

function approvalKey(string $text): string
{
    preg_match('/key ([0-9a-f]{64})/', $text, $matches);

    return $matches[1];
}

function approvalId(string $text): string
{
    preg_match('/agentic:approve (\w{26})/', $text, $matches);

    return $matches[1];
}

/**
 * Runs a call expected to knock and returns the approval row it created —
 * needed whenever a test must approve/deny by id (the decision identity)
 * while still asserting against args_hash separately.
 */
function knockApproval(callable $call): Approval
{
    try {
        $call();
    } catch (ApprovalRequiredException $e) {
        return Approval::findOrFail($e->approvalId);
    }

    throw new RuntimeException('Expected an approval knock');
}

/**
 * The first ai-tool adapter Agentic::tools() yields for an action, optionally
 * bound to an explicit principal.
 */
function adapterFor(string $action = 'refund-invoice', ?Authenticatable $as = null): ActionToolAdapter
{
    foreach (Agentic::tools([$action], $as) as $tool) {
        return $tool;
    }

    throw new RuntimeException("{$action} adapter not yielded");
}

function useUsersTable(): void
{
    config(['auth.providers.users.model' => User::class]);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
