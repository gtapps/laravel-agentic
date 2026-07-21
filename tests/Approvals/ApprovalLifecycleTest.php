<?php

use Gtapps\LaravelAgentic\Approvals\Approval;
use Gtapps\LaravelAgentic\Approvals\ApprovalBroker;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Events\ApprovalRequested;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\FailClosedAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\PredicateAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\SecretAction;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Actions\RefundResult;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['agentic.discovery.paths' => [realpath(__DIR__.'/../../workbench/app/Actions')]]);

    Agentic::register([FailClosedAction::class, PredicateAction::class, SecretAction::class]);

    Gate::define('refund-invoice', fn (GenericUser $user, int $invoiceId) => in_array($user->getAuthIdentifier(), [1, 2]));
});

function ctx(int $userId = 1)
{
    return app(ContextFactory::class)->make(Surface::Cli, new GenericUser(['id' => $userId]));
}

function runRefund(int $userId = 1, array $args = ['invoiceId' => 42, 'amount' => 99.5])
{
    return Agentic::run('refund-invoice', $args, ctx($userId));
}

function knockKey(callable $call): string
{
    try {
        $call();
    } catch (ApprovalRequiredException $e) {
        return $e->key;
    }

    throw new RuntimeException('Expected an approval knock');
}

it('walks the full lifecycle: knock → approve → identical retry executes → grant consumed → re-knock', function () {
    // Knock: agent-legible message carries the key and the retry protocol.
    $key = null;

    try {
        runRefund();
        $this->fail('Expected ApprovalRequiredException');
    } catch (ApprovalRequiredException $e) {
        $key = $e->key;
        expect($e->getMessage())
            ->toContain("Approval required for action 'refund-invoice'")
            ->toContain($key)
            ->toContain("agentic:approve {$key}")
            ->toContain('retry this exact call unchanged');
    }

    expect(Approval::where('args_hash', $key)->where('status', 'pending')->count())->toBe(1);

    // Re-knock while pending is idempotent — no duplicate rows.
    try {
        runRefund();
    } catch (ApprovalRequiredException) {
    }

    expect(Approval::where('args_hash', $key)->count())->toBe(1);

    $this->artisan('agentic:approve', ['key' => $key])->assertSuccessful();

    // Identical retry executes; the grant is consumed.
    $result = runRefund();

    expect($result->value)->toBeInstanceOf(RefundResult::class)
        ->and(Approval::where('args_hash', $key)->where('status', 'consumed')->count())->toBe(1);

    // Third identical call knocks again.
    expect(fn () => runRefund())->toThrow(ApprovalRequiredException::class);
});

it('keys approvals on canonical args: different args knock separately, key order does not matter', function () {
    $key1 = knockKey(fn () => runRefund(args: ['invoiceId' => 42, 'amount' => 99.5]));
    $key2 = knockKey(fn () => runRefund(args: ['invoiceId' => 42, 'amount' => 50.0]));

    expect($key2)->not->toBe($key1);

    // Same args in different key order and with defaults spelled out → same key.
    $key3 = knockKey(fn () => runRefund(args: ['amount' => 99.5, 'invoiceId' => 42, 'reason' => 'requested_by_customer', 'notify' => true]));

    expect($key3)->toBe($key1);
});

it('binds grants to the requesting principal: another user with identical args knocks separately', function () {
    $key = knockKey(fn () => runRefund(userId: 1));
    $this->artisan('agentic:approve', ['key' => $key])->assertSuccessful();

    // User 2, identical args: same key, but no grant for them — they knock.
    expect(fn () => runRefund(userId: 2))->toThrow(ApprovalRequiredException::class);

    expect(Approval::where('args_hash', $key)->where('requested_user_id', 2)->where('status', 'pending')->count())->toBe(1)
        ->and(Approval::where('args_hash', $key)->where('requested_user_id', 1)->where('status', 'granted')->count())->toBe(1);

    // User 1's grant is intact and still consumable by user 1 only.
    expect(runRefund(userId: 1)->value)->toBeInstanceOf(RefundResult::class);
});

it('expires unanswered knocks to deny', function () {
    $key = knockKey(fn () => runRefund());

    $this->travel(11)->minutes();

    // Approving after expiry finds nothing.
    $this->artisan('agentic:approve', ['key' => $key])->assertFailed();

    // Retry re-knocks with a fresh pending row.
    expect(fn () => runRefund())->toThrow(ApprovalRequiredException::class);

    expect(Approval::where('args_hash', $key)->where('status', 'expired')->count())->toBe(1)
        ->and(Approval::where('args_hash', $key)->where('status', 'pending')->count())->toBe(1);
});

it('expires unconsumed grants to deny', function () {
    $key = knockKey(fn () => runRefund());
    $this->artisan('agentic:approve', ['key' => $key])->assertSuccessful();

    $this->travel(11)->minutes();

    expect(fn () => runRefund())->toThrow(ApprovalRequiredException::class);

    expect(Approval::where('args_hash', $key)->where('status', 'expired')->count())->toBe(1);
});

it('fails closed: a throwing predicate means approval required', function () {
    Agentic::run('fail-closed', ['amount' => 1.0], ctx());
})->throws(ApprovalRequiredException::class);

it('lets predicate actions skip approval when the predicate says no', function () {
    $ctx = ctx();

    expect(Agentic::run('predicate-refund', ['amount' => 50.0], $ctx)->value)->toBe('executed 50')
        ->and(fn () => Agentic::run('predicate-refund', ['amount' => 150.0], $ctx))
        ->toThrow(ApprovalRequiredException::class);
});

it('verifies the capability token on programmatic decide, timing-safe', function () {
    Event::fake([ApprovalRequested::class]);

    $key = knockKey(fn () => runRefund());

    $token = null;
    Event::assertDispatched(ApprovalRequested::class, function (ApprovalRequested $event) use (&$token) {
        $token = $event->token;

        return true;
    });

    $broker = app(ApprovalBroker::class);

    expect($broker->decide($key, 'wrong-token', true))->toBeFalse()
        ->and(Approval::where('args_hash', $key)->value('status'))->toBe('pending')
        ->and($broker->decide($key, $token, true, 'ops@example.com'))->toBeTrue()
        ->and(Approval::where('args_hash', $key)->value('status'))->toBe('granted')
        ->and(Approval::where('args_hash', $key)->value('decided_by'))->toBe('ops@example.com');
});

it('voids grants when the action definition drifted since approval', function () {
    $key = knockKey(fn () => runRefund());
    $this->artisan('agentic:approve', ['key' => $key])->assertSuccessful();

    Approval::where('args_hash', $key)->update(['definition_hash' => 'stale-hash']);

    // The drifted grant is never consumable — the call knocks again.
    expect(fn () => runRefund())->toThrow(ApprovalRequiredException::class);

    expect(Approval::where('args_hash', $key)->where('status', 'granted')->count())->toBe(1)
        ->and(Approval::where('args_hash', $key)->where('status', 'pending')->count())->toBe(1);
});

it('denies via agentic:deny and the agent knocks again on retry', function () {
    $key = knockKey(fn () => runRefund());

    $this->artisan('agentic:deny', ['key' => $key])->assertSuccessful();

    expect(Approval::where('args_hash', $key)->value('status'))->toBe('denied')
        ->and(fn () => runRefund())->toThrow(ApprovalRequiredException::class);
});

it('resolves the connection from agentic.approvals.connection, defaulting to null', function () {
    expect((new Approval)->getConnectionName())->toBeNull();

    config(['agentic.approvals.connection' => 'sqlite']);

    expect((new Approval)->getConnectionName())->toBe('sqlite');
});

it('redacts configured globs from the approval payload', function () {
    config(['agentic.redact' => ['password', '*.secret']]);

    $ctx = ctx();

    try {
        Agentic::run('secret-action', [
            'username' => 'dom',
            'password' => 'hunter2',
            'card' => ['holder' => 'D. Om', 'secret' => '4242'],
        ], $ctx);
        $this->fail('Expected ApprovalRequiredException');
    } catch (ApprovalRequiredException $e) {
        $approval = Approval::where('args_hash', $e->key)->firstOrFail();

        expect($approval->args_redacted)->toBe([
            'username' => 'dom',
            'password' => '[redacted]',
            'card' => ['holder' => 'D. Om', 'secret' => '[redacted]'],
        ]);
    }
});
