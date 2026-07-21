<?php

use Gtapps\LaravelAgentic\Approvals\Approval;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Audit\ActionLog;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\AuditedReadAction;
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
        ->toThrow(AssertionFailedError::class, 'Action [lookup] is not audited.');
});

it('asserts audited for a readOnly action that opts into audit', function () {
    Agentic::register([AuditedReadAction::class]);

    $fake = Agentic::fake();

    Agentic::run('audited-read', ['message' => 'x'], fakeCtx());

    $fake->assertAudited('audited-read');
});

it('asserts not audited for readOnly or opted-out actions', function () {
    Agentic::register([ReadOnlyLookupAction::class]);

    $fake = Agentic::fake();

    Agentic::run('lookup', ['message' => 'x'], fakeCtx());

    $fake->assertNotAudited('lookup');
});

it('fails assertNotAudited for an audited action', function () {
    $fake = Agentic::fake();

    Agentic::run('refund-invoice', ['invoiceId' => 1, 'amount' => 5.0], fakeCtx());

    expect(fn () => $fake->assertNotAudited('refund-invoice'))
        ->toThrow(AssertionFailedError::class, 'Action [refund-invoice] is audited.');
});

it('assertNotAudited still requires the action to have run', function () {
    $fake = Agentic::fake();

    expect(fn () => $fake->assertNotAudited('refund-invoice'))
        ->toThrow(AssertionFailedError::class, 'Expected action [refund-invoice] to have run, but it did not.');
});

it('folds the global audit switch into both audit assertions, like Recorder does', function () {
    config(['agentic.audit.enabled' => false]);

    $fake = Agentic::fake();

    Agentic::run('refund-invoice', ['invoiceId' => 1, 'amount' => 5.0], fakeCtx());

    // A real run writes no row with the switch off, so the assertions must agree.
    $fake->assertNotAudited('refund-invoice');

    expect(fn () => $fake->assertAudited('refund-invoice'))
        ->toThrow(AssertionFailedError::class, 'Action [refund-invoice] is not audited.');
});
