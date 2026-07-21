<?php

use Gtapps\LaravelAgentic\Approvals\Approval;
use Gtapps\LaravelAgentic\Audit\ActionLog;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Schema\SchemaCompiler;
use Gtapps\LaravelAgentic\Surfaces\Mcp\AgenticServer;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\PredicateAction;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Facades\Mcp;
use Workbench\App\Actions\CompactRefundInput;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['agentic.discovery.paths' => [realpath(__DIR__.'/../../workbench/app/Actions')]]);

    Agentic::register([PredicateAction::class]);

    Gate::define('refund-invoice', fn (GenericUser $user, int $invoiceId) => $user->getAuthIdentifier() === 1);

    Mcp::web('/mcp', AgenticServer::class);
});

function mcpCall(string $method, array $params = [], ?GenericUser $user = null)
{
    $test = test();

    if ($user !== null) {
        $test->actingAs($user);
    }

    return $test->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => (object) $params,
    ]);
}

it('lists actions over a real MCP handshake with compiled-schema fidelity', function () {
    $response = mcpCall('tools/list', user: new GenericUser(['id' => 1]));

    $tools = collect($response->json('result.tools'));

    $refund = $tools->firstWhere('name', 'refund-invoice');

    expect($refund)->not->toBeNull()
        ->and($refund['description'])->toBe('Refund an invoice to the original payment method.')
        ->and($refund['inputSchema'])->toBe(app(SchemaCompiler::class)->compile(CompactRefundInput::class));
});

it('restricts the unauthenticated tier to the allowlist, in tools/list and tools/call', function () {
    config(['agentic.mcp.tiers.unauthenticated' => ['predicate-refund']]);

    $response = mcpCall('tools/list');

    expect(collect($response->json('result.tools'))->pluck('name')->all())->toBe(['predicate-refund']);

    // tools/call is gated by the same filter: the hidden tool is not callable.
    $call = mcpCall('tools/call', ['name' => 'refund-invoice', 'arguments' => ['invoiceId' => 1, 'amount' => 5.0]]);

    expect($call->json('error.message'))->toContain('not found');

    // The allowlisted one IS callable without auth.
    $allowed = mcpCall('tools/call', ['name' => 'predicate-refund', 'arguments' => ['amount' => 10.0]]);

    expect($allowed->json('result.isError'))->toBeFalse()
        ->and($allowed->json('result.content.0.text'))->toContain('executed 10');
});

it('hard-excludes actions from every tier, in tools/list and tools/call', function () {
    config(['agentic.mcp.exclude' => ['refund-invoice']]);

    $response = mcpCall('tools/list', user: new GenericUser(['id' => 1]));

    expect(collect($response->json('result.tools'))->pluck('name'))->not->toContain('refund-invoice');

    $call = mcpCall('tools/call', ['name' => 'refund-invoice', 'arguments' => ['invoiceId' => 1, 'amount' => 5.0]], new GenericUser(['id' => 1]));

    expect($call->json('error.message'))->toContain('not found');
});

it('round-trips the approval flow over MCP: in-band knock, approve, identical retry executes', function () {
    $user = new GenericUser(['id' => 1]);
    $args = ['name' => 'refund-invoice', 'arguments' => ['invoiceId' => 42, 'amount' => 99.5]];

    $knock = mcpCall('tools/call', $args, $user);

    expect($knock->json('result.isError'))->toBeTrue();

    $text = $knock->json('result.content.0.text');
    expect($text)->toContain("Approval required for action 'refund-invoice'")
        ->toContain('retry this exact call unchanged');

    $this->artisan('agentic:approve', ['key' => approvalKey($text)])->assertSuccessful();

    $retry = mcpCall('tools/call', $args, $user);

    expect($retry->json('result.isError'))->toBeFalse()
        ->and(json_decode($retry->json('result.content.0.text'), true))->toMatchArray([
            'invoiceId' => 42,
            'status' => 'refunded',
        ]);

    expect(ActionLog::where('action', 'refund-invoice')->where('surface', 'mcp')->where('status', 'ok')->count())->toBe(1)
        ->and(Approval::where('status', 'consumed')->count())->toBe(1);
});

it('maps validation failures to in-band field errors', function () {
    $call = mcpCall('tools/call', ['name' => 'refund-invoice', 'arguments' => ['invoiceId' => 42]], new GenericUser(['id' => 1]));

    expect($call->json('result.isError'))->toBeTrue()
        ->and($call->json('result.content.0.text'))->toContain('amount');
});

it('maps authorization denials to in-band errors', function () {
    $call = mcpCall('tools/call', ['name' => 'refund-invoice', 'arguments' => ['invoiceId' => 42, 'amount' => 5.0]], new GenericUser(['id' => 2]));

    expect($call->json('result.isError'))->toBeTrue()
        ->and($call->json('result.content.0.text'))->toContain('Not authorized');
});
