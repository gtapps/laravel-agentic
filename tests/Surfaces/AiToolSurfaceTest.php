<?php

use Gtapps\LaravelAgentic\Audit\ActionLog;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Surfaces\AiTool\ActionToolAdapter;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['agentic.discovery.paths' => [realpath(__DIR__.'/../../workbench/app/Actions')]]);

    Gate::define('refund-invoice', fn (GenericUser $user, int $invoiceId) => $user->getAuthIdentifier() === 1);
});

function refundAdapter(): ActionToolAdapter
{
    foreach (Agentic::tools(['refund-invoice']) as $tool) {
        return $tool;
    }

    throw new RuntimeException('refund-invoice adapter not yielded');
}

it('yields laravel/ai tool adapters from Agentic::tools with name and description', function () {
    $names = collect(iterator_to_array(Agentic::tools(), false))
        ->map(fn (ActionToolAdapter $tool) => $tool->name());

    expect($names)->toContain('refund-invoice');

    $adapter = refundAdapter();

    expect((string) $adapter->description())->toBe('Refund an invoice to the original payment method.');
});

it('exposes the compact schema through laravel/ai\'s schema dialect faithfully', function () {
    $adapter = refundAdapter();

    // Assemble exactly like laravel/ai's gateways do.
    $schema = (new ObjectSchema($adapter->schema(new JsonSchemaTypeFactory)))->toSchema();

    expect($schema['properties'])->toEqual([
        'invoiceId' => ['type' => 'integer'],
        'amount' => ['type' => 'number'],
    ])
        ->and($schema['required'])->toEqualCanonicalizing(['invoiceId', 'amount']);
});

it('executes through the same pipeline with the running agent\'s user, auditing surface ai-tool', function () {
    auth()->setUser(new GenericUser(['id' => 1]));

    $adapter = refundAdapter();
    $args = ['invoiceId' => 42, 'amount' => 99.5];

    // Knock arrives as in-band text the model can act on.
    $knock = (string) $adapter->handle(new Request($args));

    expect($knock)->toContain("Approval required for action 'refund-invoice'");

    $this->artisan('agentic:approve', ['id' => approvalId($knock)])->assertSuccessful();

    $result = json_decode((string) $adapter->handle(new Request($args)), true);

    expect($result)->toMatchArray(['invoiceId' => 42, 'amount' => 99.5, 'status' => 'refunded']);

    expect(ActionLog::where('action', 'refund-invoice')->where('surface', 'ai-tool')->where('status', 'ok')->count())->toBe(1);
});

it('produces audit rows identical to MCP apart from surface', function () {
    auth()->setUser(new GenericUser(['id' => 1]));

    config(['agentic.approvals.ttl' => 600]);

    $adapter = refundAdapter();
    $args = ['invoiceId' => 7, 'amount' => 10.0];

    $knock = (string) $adapter->handle(new Request($args));
    $key = approvalKey($knock);
    $this->artisan('agentic:approve', ['id' => approvalId($knock)])->assertSuccessful();
    $adapter->handle(new Request($args));

    // Same call over the "job-like" direct runner path for comparison via MCP
    // is covered in McpSurfaceTest; here compare ai-tool row shape to spec.
    $row = ActionLog::where('surface', 'ai-tool')->where('status', 'ok')->firstOrFail();

    expect($row->action)->toBe('refund-invoice')
        ->and($row->user_id)->toBe('1')
        ->and($row->args_hash)->toBe($key)
        ->and($row->definition_hash)->not->toBeEmpty();
});

it('returns denials as in-band text', function () {
    auth()->setUser(new GenericUser(['id' => 2]));

    $response = (string) refundAdapter()->handle(new Request(['invoiceId' => 42, 'amount' => 5.0]));

    expect($response)->toContain('Not authorized');
});

it('runs as the explicit principal passed to Agentic::tools, not the ambient guard user', function () {
    // No ambient guard user set — a queued/background conversation has none.
    $adapter = collect(iterator_to_array(Agentic::tools(['refund-invoice'], new GenericUser(['id' => 1])), false))->sole();

    $args = ['invoiceId' => 42, 'amount' => 99.5];
    $knock = (string) $adapter->handle(new Request($args));

    $this->artisan('agentic:approve', ['id' => approvalId($knock)])->assertSuccessful();

    $adapter->handle(new Request($args));

    $row = ActionLog::where('action', 'refund-invoice')->where('surface', 'ai-tool')->where('status', 'ok')->firstOrFail();

    expect($row->user_id)->toBe('1');
});
