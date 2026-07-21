<?php

use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Exceptions\ActionNotFound;
use Gtapps\LaravelAgentic\Exceptions\OutputSchemaMismatch;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\CliOnlyAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\FallbackOutputAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\StrictOutputAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\AddressData;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Workbench\App\Actions\RefundResult;

uses(RefreshDatabase::class);

function contextFor(Surface $surface, ?GenericUser $user = null)
{
    return app(ContextFactory::class)->make($surface, $user);
}

beforeEach(function () {
    config(['agentic.discovery.paths' => [realpath(__DIR__.'/../../workbench/app/Actions')]]);

    Agentic::register([
        CliOnlyAction::class,
        StrictOutputAction::class,
        FallbackOutputAction::class,
    ]);

    Gate::define('refund-invoice', fn (GenericUser $user, int $invoiceId) => $user->getAuthIdentifier() === 1);
});

it('runs the workbench action end-to-end with authorize honored', function () {
    $ctx = contextFor(Surface::Cli, new GenericUser(['id' => 1]));

    try {
        Agentic::run('refund-invoice', ['invoiceId' => 42, 'amount' => 99.5], $ctx);
        $this->fail('Expected an approval knock');
    } catch (ApprovalRequiredException $e) {
        $this->artisan('agentic:approve', ['key' => $e->key])->assertSuccessful();
    }

    $result = Agentic::run('refund-invoice', ['invoiceId' => 42, 'amount' => 99.5], $ctx);

    expect($result->value)->toBeInstanceOf(RefundResult::class)
        ->and($result->value->invoiceId)->toBe(42)
        ->and($result->value->status)->toBe('refunded');
});

it('throws typed ActionDenied on gate denial', function () {
    Agentic::run(
        'refund-invoice',
        ['invoiceId' => 42, 'amount' => 99.5],
        contextFor(Surface::Cli, new GenericUser(['id' => 2])),
    );
})->throws(ActionDenied::class);

it('throws ActionNotFound for unknown actions', function () {
    Agentic::run('does-not-exist', [], contextFor(Surface::Cli));
})->throws(ActionNotFound::class);

it('hides actions from surfaces they are not exposed to', function () {
    Agentic::run('cli-only', ['message' => 'hi'], contextFor(Surface::Http));
})->throws(ActionNotFound::class);

it('invokes handle via container call: free param order, DI, context bound by type', function () {
    $result = Agentic::run('cli-only', ['message' => 'hi'], contextFor(Surface::Cli));

    expect($result->value)->toBe([
        'echo' => 'hi',
        'caller' => 'cli',
        'env' => config('app.env'),
    ]);
});

it('rejects invalid args with field errors before hydration', function () {
    Agentic::run('refund-invoice', ['invoiceId' => 42], contextFor(Surface::Cli, new GenericUser(['id' => 1])));
})->throws(ValidationException::class);

it('throws under outputMismatch strict when the result type mismatches', function () {
    Agentic::run('strict-output', [], contextFor(Surface::Cli));
})->throws(OutputSchemaMismatch::class);

it('replaces mismatched results via outputFallback under outputMismatch fallback', function () {
    $result = Agentic::run('fallback-output', [], contextFor(Surface::Cli));

    expect($result->value)->toBeInstanceOf(AddressData::class)
        ->and($result->value->street)->toBe('fallback street');
});
