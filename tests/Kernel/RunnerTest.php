<?php

use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Exceptions\ActionNotFound;
use Gtapps\LaravelAgentic\Exceptions\OutputSchemaMismatch;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\CardData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\CliOnlyAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\FallbackOutputAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\PaginatedCardsAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\PaginatedDataCollectionCardsAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\PaginatedMismatchStrictAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\PaginatedMismatchWarnAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\PaginatedNoSchemaAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\PaginatedRawArrayCardsAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\SimplePaginatedCardsAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\StrictOutputAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\AddressData;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
        $this->artisan('agentic:approve', ['id' => $e->approvalId])->assertSuccessful();
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

it('normalizes a raw paginator of matching Data items into spatie\'s pagination envelope', function () {
    Agentic::register([PaginatedCardsAction::class]);

    $result = Agentic::run('paginated-cards', [], contextFor(Surface::Cli));

    expect($result->value)->toBeArray()
        ->and($result->value['data'])->toHaveCount(2)
        ->and($result->value['data'][0])->toBe(['holder' => 'alice', 'secret' => 'shh'])
        ->and($result->value['meta']['current_page'])->toBe(2)
        ->and($result->value['meta']['per_page'])->toBe(2)
        ->and($result->value['meta']['last_page'])->toBe(3)
        ->and($result->value['meta']['total'])->toBe(5)
        ->and($result->value['meta']['path'])->toBe('/');
});

it('hydrates a raw paginator of plain arrays into the outputSchema and normalizes it', function () {
    Agentic::register([PaginatedRawArrayCardsAction::class]);

    $result = Agentic::run('paginated-raw-array-cards', [], contextFor(Surface::Cli));

    expect($result->value)->toBeArray()
        ->and($result->value['data'])->toHaveCount(2)
        ->and($result->value['data'][0])->toBe(['holder' => 'alice', 'secret' => 'shh'])
        ->and($result->value['meta']['current_page'])->toBe(2)
        ->and($result->value['meta']['per_page'])->toBe(2)
        ->and($result->value['meta']['total'])->toBe(5)
        ->and($result->value['meta']['path'])->toBe('/');
});

it('normalizes an already-built PaginatedDataCollection the same way', function () {
    Agentic::register([PaginatedDataCollectionCardsAction::class]);

    $result = Agentic::run('paginated-data-collection-cards', [], contextFor(Surface::Cli));

    expect($result->value)->toBeArray()
        ->and($result->value['data'])->toHaveCount(2)
        ->and($result->value['data'][0])->toBe(['holder' => 'alice', 'secret' => 'shh'])
        ->and($result->value['meta']['current_page'])->toBe(2)
        ->and($result->value['meta']['total'])->toBe(5);
});

it('normalizes a simple (non-length-aware) paginator into the envelope too', function () {
    Agentic::register([SimplePaginatedCardsAction::class]);

    $result = Agentic::run('simple-paginated-cards', [], contextFor(Surface::Cli));

    expect($result->value)->toBeArray()
        ->and($result->value['data'])->toHaveCount(2)
        ->and($result->value['data'][0])->toBe(['holder' => 'alice', 'secret' => 'shh'])
        ->and($result->value['meta']['current_page'])->toBe(1)
        ->and($result->value['meta']['per_page'])->toBe(2);
});

it('throws under outputMismatch strict when a paginator holds items of the wrong type', function () {
    Agentic::register([PaginatedMismatchStrictAction::class]);

    Agentic::run('paginated-mismatch-strict', [], contextFor(Surface::Cli));
})->throws(OutputSchemaMismatch::class);

it('warns and passes the raw paginator through under outputMismatch warn when items are the wrong type', function () {
    Log::spy();

    Agentic::register([PaginatedMismatchWarnAction::class]);

    $result = Agentic::run('paginated-mismatch-warn', [], contextFor(Surface::Cli));

    expect($result->value)->toBeInstanceOf(LengthAwarePaginator::class);

    Log::shouldHaveReceived('warning')->once();
});

it('leaves a paginator untouched when no outputSchema is declared', function () {
    Agentic::register([PaginatedNoSchemaAction::class]);

    $result = Agentic::run('paginated-no-schema', [], contextFor(Surface::Cli));

    expect($result->value)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($result->value->items()[0])->toBeInstanceOf(CardData::class);
});
