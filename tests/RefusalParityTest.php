<?php

use Gtapps\LaravelAgentic\Audit\ActionLog;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Exceptions\ActionRefused;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Surfaces\Jobs\RunAction;
use Gtapps\LaravelAgentic\Surfaces\Mcp\AgenticServer;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\RefusingAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\ReservedContextRefusingAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Facades\Mcp;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/**
 * A domain refusal reaches the caller with its message on every surface and
 * is audited as `refused`, never as an internal error.
 */
beforeEach(function () {
    config(['agentic.http.middleware' => []]);

    Agentic::register([RefusingAction::class, ReservedContextRefusingAction::class]);

    useUsersTable();

    $this->user = User::create(['id' => 1, 'name' => 'Caller']);

    Mcp::web('/mcp', AgenticServer::class);
});

const REFUSAL_MESSAGE = 'Version conflict: retry with baseVersion 2.';
const REFUSAL_ARGS = ['message' => 'save'];

it('renders a refusal identically through MCP, ai-tool, HTTP, CLI, and job', function () {
    // ── MCP (production: app.debug off) ────────────────────────────────
    config(['app.debug' => false]);

    $mcp = $this->actingAs($this->user)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'refusing', 'arguments' => REFUSAL_ARGS],
    ]);

    expect($mcp->json('result.isError'))->toBeTrue()
        ->and($mcp->json('result.content.0.text'))->toBe(REFUSAL_MESSAGE);

    // ── laravel/ai tool ────────────────────────────────────────────────
    auth()->setUser($this->user);

    expect((string) adapterFor('refusing')->handle(new Request(REFUSAL_ARGS)))->toBe(REFUSAL_MESSAGE);

    // ── HTTP ───────────────────────────────────────────────────────────
    $this->actingAs($this->user)
        ->postJson('/agentic/actions/refusing', REFUSAL_ARGS)
        ->assertStatus(409)
        ->assertExactJson([
            'currentVersion' => 2,
            'status' => 'refused',
            'error' => 'conflict',
            'message' => REFUSAL_MESSAGE,
        ]);

    // ── CLI ────────────────────────────────────────────────────────────
    $exitCode = Artisan::call('agentic:action', [
        'name' => 'refusing',
        'json' => json_encode(REFUSAL_ARGS),
        '--as' => 1,
    ]);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain(REFUSAL_MESSAGE);

    // ── Job (run with no queue job attached, the refusal is rethrown) ──
    expect(fn () => app()->call([new RunAction('refusing', REFUSAL_ARGS, 1), 'handle']))
        ->toThrow(ActionRefused::class, REFUSAL_MESSAGE);

    // ── Audit ──────────────────────────────────────────────────────────
    $rows = ActionLog::where('action', 'refusing')->get();

    expect($rows)->toHaveCount(5)
        ->and($rows->pluck('status')->unique()->all())->toBe(['refused'])
        ->and($rows->pluck('error')->unique()->all())->toBe([REFUSAL_MESSAGE])
        ->and($rows->pluck('surface')->sort()->values()->all())
        ->toBe(collect(Surface::values(Surface::cases()))->sort()->values()->all());
});

it('keeps the fixed HTTP keys when the refusal context collides with them', function () {
    $this->actingAs($this->user)
        ->postJson('/agentic/actions/reserved-context-refusing', REFUSAL_ARGS)
        ->assertStatus(413)
        ->assertExactJson([
            'limit' => 10,
            'status' => 'refused',
            'error' => 'too_large',
            'message' => 'Payload too large.',
        ]);
});
