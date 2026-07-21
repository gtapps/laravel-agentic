<?php

use Gtapps\LaravelAgentic\Approvals\Approval;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\PredicateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['agentic.discovery.paths' => [realpath(__DIR__.'/../../workbench/app/Actions')]]);

    Agentic::register([PredicateAction::class]);

    useUsersTable();

    User::create(['id' => 1, 'name' => 'Approver']);

    Gate::define('refund-invoice', fn ($user, int $invoiceId) => $user->getAuthIdentifier() === 1);
});

it('runs an action from the CLI and prints the result', function () {
    $this->artisan('agentic:action', ['name' => 'predicate-refund', 'json' => '{"amount": 50}'])
        ->expectsOutputToContain('executed 50')
        ->assertSuccessful();
});

it('prints the approve command on knock and executes after approval, impersonating via --as', function () {
    $this->artisan('agentic:action', [
        'name' => 'refund-invoice',
        'json' => '{"invoiceId": 42, "amount": 99.5}',
        '--as' => 1,
    ])
        ->expectsOutputToContain('php artisan agentic:approve')
        ->assertFailed();

    $id = Approval::where('status', 'pending')->value('id');

    $this->artisan('agentic:approve', ['id' => $id])->assertSuccessful();

    $this->artisan('agentic:action', [
        'name' => 'refund-invoice',
        'json' => '{"invoiceId": 42, "amount": 99.5}',
        '--as' => 1,
    ])
        ->expectsOutputToContain('"status": "refunded"')
        ->assertSuccessful();
});

it('fails cleanly on malformed JSON and unknown actions', function () {
    $this->artisan('agentic:action', ['name' => 'predicate-refund', 'json' => 'not-json'])
        ->expectsOutputToContain('JSON object')
        ->assertFailed();

    $this->artisan('agentic:action', ['name' => 'nope'])
        ->expectsOutputToContain('Unknown action')
        ->assertFailed();
});
