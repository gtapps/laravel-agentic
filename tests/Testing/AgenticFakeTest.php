<?php

use Gtapps\LaravelAgentic\Approvals\Approval;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Audit\ActionLog;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\ReadOnlyLookupAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['agentic.discovery.paths' => [realpath(__DIR__.'/../../workbench/app/Actions')]]);
});

function fakeCtx()
{
    return app(ContextFactory::class)->make(Surface::Cli);
}

it('records runs instead of executing and returns configured results', function () {
    $fake = Agentic::fake();
    $fake->result('refund-invoice', ['stubbed' => true]);

    $result = Agentic::run('refund-invoice', ['invoiceId' => 1, 'amount' => 5.0], fakeCtx());

    expect($result->value)->toBe(['stubbed' => true]);

    $fake->assertRan('refund-invoice');
    $fake->assertRan('refund-invoice', fn (array $args) => $args['invoiceId'] === 1);

    // Nothing real happened: no validation, no approvals, no audit rows.
    expect(ActionLog::count())->toBe(0)
        ->and(Approval::count())->toBe(0);
});

it('asserts nothing ran', function () {
    $fake = Agentic::fake();

    $fake->assertNothingRan();
});

it('simulates approval knocks', function () {
    $fake = Agentic::fake()->requireApprovalFor('refund-invoice');

    expect(fn () => Agentic::run('refund-invoice', ['invoiceId' => 1, 'amount' => 5.0], fakeCtx()))
        ->toThrow(ApprovalRequiredException::class);

    $fake->assertApprovalRequested('refund-invoice');
    $fake->assertNothingRan();
});

it('asserts audited based on the real definition flags', function () {
    $fake = Agentic::fake();

    Agentic::run('refund-invoice', ['invoiceId' => 1, 'amount' => 5.0], fakeCtx());

    $fake->assertAudited('refund-invoice');
});

it('fails assertAudited for readOnly or opted-out actions', function () {
    Agentic::register([ReadOnlyLookupAction::class]);

    $fake = Agentic::fake();

    Agentic::run('lookup', ['message' => 'x'], fakeCtx());

    expect(fn () => $fake->assertAudited('lookup'))
        ->toThrow(AssertionFailedError::class);
});
