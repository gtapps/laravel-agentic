<?php

use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\CliOnlyAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\ReadOnlyLookupAction;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'agentic.discovery.paths' => [realpath(__DIR__.'/../../workbench/app/Actions')],
        'agentic.http.middleware' => [],
    ]);

    Agentic::register([ReadOnlyLookupAction::class]);

    Gate::define('refund-invoice', fn (GenericUser $user, int $invoiceId) => $user->getAuthIdentifier() === 1);
});

it('runs the approval flow over HTTP: 409 knock with key, approve, identical retry returns 200', function () {
    $user = new GenericUser(['id' => 1]);
    $args = ['invoiceId' => 42, 'amount' => 99.5];

    $knock = $this->actingAs($user)->postJson('/agentic/actions/refund-invoice', $args);

    $knock->assertStatus(409)
        ->assertJsonPath('status', 'approval_required')
        ->assertJsonPath('retry', 'identical call after approval');

    $this->artisan('agentic:approve', ['id' => $knock->json('approvalId')])->assertSuccessful();

    $this->actingAs($user)->postJson('/agentic/actions/refund-invoice', $args)
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('result.invoiceId', 42)
        ->assertJsonPath('result.status', 'refunded');
});

it('maps validation failures to 422 with field errors from the compiler', function () {
    $this->actingAs(new GenericUser(['id' => 1]))
        ->postJson('/agentic/actions/refund-invoice', ['invoiceId' => 42])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

it('maps authorization denial to 403', function () {
    $this->actingAs(new GenericUser(['id' => 2]))
        ->postJson('/agentic/actions/refund-invoice', ['invoiceId' => 42, 'amount' => 5.0])
        ->assertStatus(403);
});

it('returns 404 for unknown or hidden actions', function () {
    $this->actingAs(new GenericUser(['id' => 1]))
        ->postJson('/agentic/actions/nope', [])
        ->assertStatus(404);

    // cli-only is real but not exposed to HTTP — indistinguishable from unknown.
    Agentic::register([CliOnlyAction::class]);

    $this->actingAs(new GenericUser(['id' => 1]))
        ->postJson('/agentic/actions/cli-only', ['message' => 'hi'])
        ->assertStatus(404);
});

it('allows GET only for readOnly actions', function () {
    $this->getJson('/agentic/actions/lookup?message=hello')
        ->assertOk()
        ->assertJsonPath('result.found', 'hello');

    $this->actingAs(new GenericUser(['id' => 1]))
        ->getJson('/agentic/actions/refund-invoice?invoiceId=1&amount=2')
        ->assertStatus(405);
});
