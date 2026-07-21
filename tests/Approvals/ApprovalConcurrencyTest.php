<?php

use Gtapps\LaravelAgentic\Approvals\Approval;
use Gtapps\LaravelAgentic\Approvals\ApprovalBroker;
use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Events\ApprovalGranted;
use Gtapps\LaravelAgentic\Events\ApprovalRequested;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['agentic.discovery.paths' => [realpath(__DIR__.'/../../workbench/app/Actions')]]);

    Gate::define('refund-invoice', fn (GenericUser $user, int $invoiceId) => in_array($user->getAuthIdentifier(), [1, 2]));
});

function concurrencyCtx(int $userId): ActionContext
{
    return app(ContextFactory::class)->make(Surface::Cli, new GenericUser(['id' => $userId]));
}

function concurrencyKnock(ActionContext $context, array $args = ['invoiceId' => 42, 'amount' => 99.5]): Approval
{
    return knockApproval(fn () => Agentic::run('refund-invoice', $args, $context));
}

function baseApprovalAttributes(array $overrides = []): array
{
    return array_merge([
        'action' => 'refund-invoice',
        'args_hash' => str_repeat('a', 64),
        'args_redacted' => [],
        'status' => 'pending',
        'token_hash' => str_repeat('b', 64),
        'requested_surface' => 'cli',
        'definition_hash' => str_repeat('c', 64),
        'expires_at' => now()->addMinutes(10),
    ], $overrides);
}

it('targets exactly one principal\'s row when two principals hold pending approvals for identical args', function () {
    $approval1 = concurrencyKnock(concurrencyCtx(1));
    $approval2 = concurrencyKnock(concurrencyCtx(2));

    expect($approval1->args_hash)->toBe($approval2->args_hash)
        ->and($approval1->id)->not->toBe($approval2->id);

    $this->artisan('agentic:approve', ['id' => $approval1->id])->assertSuccessful();

    expect(Approval::find($approval1->id)->status)->toBe('granted')
        ->and(Approval::find($approval2->id)->status)->toBe('pending');
});

it('token decide targets only the row matching its own id, even with two pending rows sharing a key', function () {
    Event::fake([ApprovalRequested::class]);

    $approval1 = concurrencyKnock(concurrencyCtx(1));
    $approval2 = concurrencyKnock(concurrencyCtx(2));

    $tokens = [];
    Event::assertDispatched(ApprovalRequested::class, function (ApprovalRequested $event) use (&$tokens) {
        $tokens[$event->approval->id] = $event->token;

        return true;
    });

    $broker = app(ApprovalBroker::class);

    // approval1's token cannot settle approval2's row, despite sharing a key.
    expect($broker->decide($approval2->id, $tokens[$approval1->id], true))->toBeFalse()
        ->and(Approval::find($approval2->id)->status)->toBe('pending')
        ->and($broker->decide($approval2->id, $tokens[$approval2->id], true))->toBeTrue()
        ->and(Approval::find($approval2->id)->status)->toBe('granted')
        ->and(Approval::find($approval1->id)->status)->toBe('pending');
});

it('settles at most once: a second decision on an already-settled row is a no-op with no duplicate event', function () {
    Event::fake([ApprovalGranted::class]);

    $approval = concurrencyKnock(concurrencyCtx(1));

    $broker = app(ApprovalBroker::class);

    expect($broker->decideViaArtisan($approval->id, true))->toBeTrue()
        ->and($broker->decideViaArtisan($approval->id, true))->toBeFalse()
        ->and($broker->decideViaArtisan($approval->id, false))->toBeFalse();

    Event::assertDispatchedTimes(ApprovalGranted::class, 1);
});

it('refuses to settle a pending row past its expiry, even before lazy expiry has marked it', function () {
    $approval = concurrencyKnock(concurrencyCtx(1));

    $this->travel(11)->minutes();

    $broker = app(ApprovalBroker::class);

    // Still 'pending' in the DB (expiry is lazy) but no longer grantable —
    // the guarded UPDATE's expires_at check wins the race against a stale read.
    expect(Approval::find($approval->id)->status)->toBe('pending')
        ->and($broker->decideViaArtisan($approval->id, true))->toBeFalse()
        ->and(Approval::find($approval->id)->status)->toBe('pending');
});

it('reuses the one pending row for a repeated knock, keyed on active_key alone', function () {
    $context = concurrencyCtx(1);
    $args = ['invoiceId' => 42, 'amount' => 99.5];

    $first = concurrencyKnock($context, $args);

    // The idempotency pre-check matches on active_key + status only — never
    // on args_hash — so it still recognises this row as the pending one for
    // (key, principal) even after the stored args_hash drifts underneath it.
    // NOTE: this covers the pre-check, not createOrFirst()'s unique-violation
    // refetch; that path only opens between the pre-check and the INSERT and
    // is not reachable from a single-threaded test.
    $racedHash = hash('sha256', 'raced-'.$first->args_hash);
    Approval::where('id', $first->id)->update(['args_hash' => $racedHash]);

    $winner = concurrencyKnock($context, $args);

    expect($winner->id)->toBe($first->id)
        ->and(Approval::where('args_hash', $racedHash)->count())->toBe(1);
});

it('enforces active_key uniqueness at the database layer', function () {
    Approval::create(baseApprovalAttributes(['active_key' => 'dup-key', 'args_hash' => str_repeat('a', 64)]));

    expect(fn () => Approval::create(baseApprovalAttributes(['active_key' => 'dup-key', 'args_hash' => str_repeat('b', 64)])))
        ->toThrow(QueryException::class);
});

it('allows unlimited terminal rows with a null active_key', function () {
    Approval::create(baseApprovalAttributes(['active_key' => null, 'status' => 'expired']));
    Approval::create(baseApprovalAttributes(['active_key' => null, 'status' => 'denied']));

    expect(Approval::count())->toBe(2);
});
