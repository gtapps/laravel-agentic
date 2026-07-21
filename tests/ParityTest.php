<?php

use Gtapps\LaravelAgentic\Approvals\Approval;
use Gtapps\LaravelAgentic\Audit\ActionLog;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Surfaces\Jobs\RunAction;
use Gtapps\LaravelAgentic\Surfaces\Mcp\AgenticServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Facades\Mcp;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/**
 * Invoking one action definition through all five surfaces produces
 * identical behavior: the same knock, approval key, result, and audit
 * row apart from `surface`.
 */
beforeEach(function () {
    config([
        'agentic.discovery.paths' => [realpath(__DIR__.'/../workbench/app/Actions')],
        'agentic.http.middleware' => [],
    ]);

    useUsersTable();

    $this->user = User::create(['id' => 1, 'name' => 'Approver']);

    Gate::define('refund-invoice', fn ($user, int $invoiceId) => $user->getAuthIdentifier() === 1);

    Mcp::web('/mcp', AgenticServer::class);
});

const PARITY_ARGS = ['invoiceId' => 42, 'amount' => 99.5];

it('runs the same action definition identically through MCP, ai-tool, HTTP, CLI, and job', function () {
    $expectedResult = ['invoiceId' => 42, 'amount' => 99.5, 'status' => 'refunded'];
    $results = [];

    $approvePending = function (): void {
        $id = Approval::where('status', 'pending')->value('id');
        expect($id)->not->toBeNull();
        $this->artisan('agentic:approve', ['id' => $id])->assertSuccessful();
    };

    // ── MCP ────────────────────────────────────────────────────────────
    $mcp = fn () => $this->actingAs($this->user)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'refund-invoice', 'arguments' => PARITY_ARGS],
    ]);

    expect($mcp()->json('result.isError'))->toBeTrue();
    $approvePending();
    $results['mcp'] = json_decode($mcp()->json('result.content.0.text'), true);

    // ── laravel/ai tool ────────────────────────────────────────────────
    auth()->setUser($this->user);
    $adapter = collect(iterator_to_array(Agentic::tools(['refund-invoice']), false))->sole();

    expect((string) $adapter->handle(new Request(PARITY_ARGS)))->toContain('Approval required');
    $approvePending();
    $results['ai-tool'] = json_decode((string) $adapter->handle(new Request(PARITY_ARGS)), true);

    // ── HTTP ───────────────────────────────────────────────────────────
    $http = fn () => $this->actingAs($this->user)->postJson('/agentic/actions/refund-invoice', PARITY_ARGS);

    expect($http()->status())->toBe(409);
    $approvePending();
    $results['http'] = $http()->assertOk()->json('result');

    // ── CLI ────────────────────────────────────────────────────────────
    $cli = fn () => $this->artisan('agentic:action', [
        'name' => 'refund-invoice',
        'json' => json_encode(PARITY_ARGS),
        '--as' => 1,
    ]);

    $cli()->assertFailed();
    $approvePending();
    $cli()->assertSuccessful();

    // ── Job ────────────────────────────────────────────────────────────
    try {
        RunAction::dispatchSync('refund-invoice', PARITY_ARGS, 1);
    } catch (Throwable) {
    }

    $approvePending();
    RunAction::dispatchSync('refund-invoice', PARITY_ARGS, 1);

    // ── Parity assertions ──────────────────────────────────────────────
    foreach (['mcp', 'ai-tool', 'http'] as $surface) {
        expect($results[$surface])->toEqual($expectedResult);
    }

    $rows = ActionLog::where('status', 'ok')->get();

    expect($rows)->toHaveCount(5)
        ->and($rows->pluck('surface')->sort()->values()->all())
        ->toBe(collect(Surface::values(Surface::cases()))->sort()->values()->all())
        ->and($rows->pluck('args_hash')->unique())->toHaveCount(1)
        ->and($rows->pluck('definition_hash')->unique())->toHaveCount(1)
        ->and($rows->pluck('user_id')->unique()->all())->toBe(['1'])
        ->and($rows->pluck('action')->unique()->all())->toBe(['refund-invoice']);

    // Ten knocks+grants total: each surface knocked once and consumed once.
    expect(ActionLog::where('status', 'approval_required')->count())->toBe(5)
        ->and(Approval::where('status', 'consumed')->count())->toBe(5);
});
