<?php

use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Exceptions\ActionNotFound;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\InheritedGateAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\MiscasedGateAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\ProtectedGateAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\ResponseGateAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\SurfaceOnlyGateAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\SurfaceOverrideAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\TruthyGateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

// ResponseGateAction opts into audit, so its allowed run writes a row.
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['agentic.discovery.paths' => []]);

    Agentic::register([
        SurfaceOverrideAction::class,
        SurfaceOnlyGateAction::class,
        ResponseGateAction::class,
        MiscasedGateAction::class,
        ProtectedGateAction::class,
        InheritedGateAction::class,
        TruthyGateAction::class,
    ]);
});

function runOn(string $action, Surface $surface): mixed
{
    return Agentic::run($action, ['message' => 'hi'], app(ContextFactory::class)->make($surface));
}

it('lets a surface method override the generic gate for its own surface', function () {
    expect(runOn('surface-override', Surface::Cli)->value)->toBe('override hi');
});

it('keeps the generic gate in force on every surface the action does not name', function () {
    expect(fn () => runOn('surface-override', Surface::Http))->toThrow(ActionDenied::class)
        ->and(fn () => runOn('surface-override', Surface::Mcp))->toThrow(ActionDenied::class);
});

it('leaves a surface ungated when no method names it and there is no generic gate', function () {
    // The decided rule: unnamed means ungated, exactly as an action with no
    // authorize() at all is callable everywhere.
    expect(runOn('surface-only-gate', Surface::Cli)->value)->toBe('only hi')
        ->and(fn () => runOn('surface-only-gate', Surface::Mcp))->toThrow(ActionDenied::class);
});

it('resolves a generic gate inherited from a base class', function () {
    expect(runOn('inherited-gate', Surface::Cli)->value)->toBe('inherited hi')
        ->and(fn () => runOn('inherited-gate', Surface::Mcp))->toThrow(ActionDenied::class);
});

it('resolves a surface method whatever its casing, because PHP lookup is case-insensitive', function () {
    expect(fn () => runOn('miscased-gate', Surface::Mcp))->toThrow(ActionDenied::class);
});

it('reports a non-public authorize method instead of running the action ungated', function () {
    expect(fn () => runOn('protected-gate', Surface::Cli))
        ->toThrow(RuntimeException::class, 'authorizeCli() must be public');
});

it('denies on a truthy value that is not boolean true', function () {
    expect(fn () => runOn('truthy-gate', Surface::Cli))->toThrow(ActionDenied::class);
});

it('allows on an allowed Gate response', function () {
    expect(runOn('response-gate', Surface::Mcp)->value)->toBe('response hi');
});

it('surfaces the policy message from a denied Gate response', function () {
    expect(fn () => runOn('response-gate', Surface::Cli))
        ->toThrow(ActionDenied::class, 'Refunds are disabled during close.');
});

it('keeps the default wording when a denied response carries no message', function () {
    expect(fn () => runOn('response-gate', Surface::Job))
        ->toThrow(ActionDenied::class, "Not authorized to run action 'response-gate'.");
});

it('conceals a denyAsNotFound denial behind the unknown-action wording', function () {
    // Byte-identical to a genuine miss, so the wording cannot tell a caller
    // which of the two it hit — and the policy's reason never goes out.
    expect(fn () => runOn('response-gate', Surface::Http))
        ->toThrow(ActionDenied::class, ActionNotFound::messageFor('response-gate'));

    try {
        runOn('response-gate', Surface::Http);
    } catch (ActionDenied $e) {
        expect($e->status)->toBe(404)
            ->and($e->getMessage())->not->toContain('another tenant')
            ->and($e->auditReason)->toBe('invoice 42 belongs to another tenant');
    }
});

it('presents an ordinary denial as 403 and carries its reason to the audit trail', function () {
    try {
        runOn('response-gate', Surface::Cli);
    } catch (ActionDenied $e) {
        expect($e->status)->toBe(403)
            ->and($e->auditReason)->toBe('Refunds are disabled during close.');
    }
});
