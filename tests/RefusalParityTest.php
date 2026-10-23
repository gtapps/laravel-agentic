<?php

use Gtapps\LaravelAgentic\Audit\ActionLog;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Exceptions\ActionRefused;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Surfaces\Jobs\RunAction;
use Gtapps\LaravelAgentic\Surfaces\Mcp\AgenticServer;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\AuditReasonRefusingAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\RefusingAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\ReservedContextRefusingAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\SubclassRefusingAction;
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

    Agentic::register([RefusingAction::class, ReservedContextRefusingAction::class, AuditReasonRefusingAction::class, SubclassRefusingAction::class]);

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

    // ── Job (propagates; retry policy stays with the queue) ────────────
    expect(fn () => RunAction::dispatchSync('refusing', REFUSAL_ARGS, 1))
        ->toThrow(ActionRefused::class, REFUSAL_MESSAGE);

    // ── Audit ──────────────────────────────────────────────────────────
    $rows = ActionLog::where('action', 'refusing')->get();

    expect($rows)->toHaveCount(5)
        ->and($rows->pluck('status')->unique()->all())->toBe(['refused'])
        ->and($rows->pluck('error')->unique()->all())->toBe([REFUSAL_MESSAGE])
        ->and($rows->pluck('surface')->sort()->values()->all())
        ->toBe(collect(Surface::values(Surface::cases()))->sort()->values()->all());
});

it('leaves a queued refusal to the queue retry policy', function () {
    $job = (new RunAction('refusing', REFUSAL_ARGS, 1))->withFakeQueueInteractions();

    expect(fn () => app()->call([$job, 'handle']))->toThrow(ActionRefused::class, REFUSAL_MESSAGE);

    $job->assertNotFailed();
});

it('audits the audit reason, not the caller message, when one is given', function () {
    $this->actingAs($this->user)
        ->postJson('/agentic/actions/audit-reason-refusing', REFUSAL_ARGS)
        ->assertStatus(409)
        ->assertJsonPath('message', "Version conflict. The newer version follows:\nsecret body");

    expect(ActionLog::where('action', 'audit-reason-refusing')->sole())
        ->status->toBe('refused')
        ->error->toBe('Version conflict.');
});

it('renders an app subclass that calls the parent constructor', function () {
    config(['app.debug' => false]);

    $mcp = $this->actingAs($this->user)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'subclass-refusing', 'arguments' => REFUSAL_ARGS],
    ]);

    expect($mcp->json('result.isError'))->toBeTrue()
        ->and($mcp->json('result.content.0.text'))->toBe('Stale: the current version is 3.');

    $this->actingAs($this->user)
        ->postJson('/agentic/actions/subclass-refusing', REFUSAL_ARGS)
        ->assertStatus(409)
        ->assertExactJson([
            'currentVersion' => 3,
            'status' => 'refused',
            'error' => 'conflict',
            'message' => 'Stale: the current version is 3.',
        ]);

    $rows = ActionLog::where('action', 'subclass-refusing')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->unique()->all())->toBe(['refused'])
        ->and($rows->pluck('error')->unique()->all())->toBe(['Stale version.']);
});

it('refuses a status outside 4xx', function (int $status) {
    expect(fn () => new ActionRefused('x', status: $status))->toThrow(InvalidArgumentException::class);
})->with([0, 200, 399, 500, 999]);

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
