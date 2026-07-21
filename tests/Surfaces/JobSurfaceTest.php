<?php

use Gtapps\LaravelAgentic\Approvals\Approval;
use Gtapps\LaravelAgentic\Audit\ActionLog;
use Gtapps\LaravelAgentic\Exceptions\RequestedUserNotFound;
use Gtapps\LaravelAgentic\Surfaces\Jobs\RunAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['agentic.discovery.paths' => [realpath(__DIR__.'/../../workbench/app/Actions')]]);

    useUsersTable();

    User::create(['id' => 1, 'name' => 'Approver']);

    Gate::define('refund-invoice', fn ($user, int $invoiceId) => $user->getAuthIdentifier() === 1);
});

it('knocks from a queued job, records approval_required, and fails without retry', function () {
    try {
        RunAction::dispatchSync('refund-invoice', ['invoiceId' => 42, 'amount' => 99.5], 1);
    } catch (Throwable) {
        // Sync driver surfaces the failure; a real queue marks the job failed.
    }

    expect(Approval::where('status', 'pending')->count())->toBe(1)
        ->and(ActionLog::where('surface', 'job')->where('status', 'approval_required')->count())->toBe(1);
});

it('executes after approval when re-dispatched, rebuilding context from the stored user id', function () {
    try {
        RunAction::dispatchSync('refund-invoice', ['invoiceId' => 42, 'amount' => 99.5], 1);
    } catch (Throwable) {
    }

    $key = Approval::where('status', 'pending')->value('args_hash');
    $this->artisan('agentic:approve', ['key' => $key])->assertSuccessful();

    RunAction::dispatchSync('refund-invoice', ['invoiceId' => 42, 'amount' => 99.5], 1);

    $row = ActionLog::where('surface', 'job')->where('status', 'ok')->firstOrFail();

    expect($row->user_id)->toBe('1')
        ->and($row->action)->toBe('refund-invoice');
});

it('fails closed instead of running anonymously when the requested user id does not resolve', function () {
    expect(fn () => RunAction::dispatchSync('refund-invoice', ['invoiceId' => 42, 'amount' => 99.5], 999))
        ->toThrow(RequestedUserNotFound::class);

    expect(ActionLog::where('action', 'refund-invoice')->exists())->toBeFalse();
});
