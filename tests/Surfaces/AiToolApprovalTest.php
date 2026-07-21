<?php

use Gtapps\LaravelAgentic\Approvals\Approval;
use Gtapps\LaravelAgentic\Approvals\ApprovalBroker;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Audit\ActionLog;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\ReadOnlyLookupAction;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['agentic.discovery.paths' => [realpath(__DIR__.'/../../workbench/app/Actions')]]);

    Agentic::register([ReadOnlyLookupAction::class]);

    Gate::define('refund-invoice', fn (GenericUser $user, int $invoiceId) => $user->getAuthIdentifier() === 1);
});

function toolRequest(array $args = ['invoiceId' => 42, 'amount' => 99.5], string $id = 'toolu_1'): Request
{
    return new Request($args, $id);
}

/**
 * What laravel/ai does before it hands an app a paused run: ask the Approvable
 * hook about each tool call it is about to make. That ask is what raises the
 * knocks, so a test about reading decisions has to go through it rather than
 * conjuring pending calls the hook never saw.
 *
 * @param  list<PendingApproval>  $pending
 */
function pauseRun(array $pending, ?GenericUser $as = null): void
{
    foreach ($pending as $call) {
        adapterFor($call->tool, $as)->shouldRequestApproval(new Request($call->arguments, $call->id));
    }
}

it('gates a mutating action and raises the knock the human answers', function () {
    // The hook is the only place that has run Resolve → ValidateAndHydrate →
    // Authorize before answering, so it is the only place that can raise a
    // knock without risking a pending row for a call the pipeline would refuse.
    $approval = adapterFor(as: new GenericUser(['id' => 1]))->shouldRequestApproval(toolRequest());

    $row = Approval::sole();

    expect($approval)->not->toBeNull()
        ->and($approval->reason)->toContain("Approval required for action 'refund-invoice'")
        ->and($approval->reason)->toContain($row->id)
        ->and($row->status)->toBe('pending')
        ->and(ActionLog::where('status', 'approval_required')->sole()->idempotency_key)->toBe('toolu_1');
});

it('gives the same answer before and after a human decides, and knocks only once', function () {
    // The answer decides which tool calls MUST carry a decision on resume. If a
    // grant flipped it to "ungated", laravel/ai would execute the call without
    // the app ever deciding it; if it flipped the other way, the app's decision
    // map would be rejected as incomplete. laravel/ai re-asks on resume, so the
    // second ask must reuse the first ask's row rather than raise a second.
    $adapter = adapterFor(as: new GenericUser(['id' => 1]));

    expect($adapter->shouldRequestApproval(toolRequest()))->not->toBeNull();

    app(ApprovalBroker::class)->decideViaArtisan(Approval::whereStatus('pending')->sole()->id, approve: true);

    expect($adapter->shouldRequestApproval(toolRequest()))->not->toBeNull()
        ->and(Approval::whereStatus('granted')->count())->toBe(1)
        ->and(Approval::count())->toBe(1)
        // The repeat ask is the same knock, so it must not audit a second one.
        ->and(ActionLog::where('status', 'approval_required')->count())->toBe(1);
});

it('does not gate a readOnly action', function () {
    expect(adapterFor('lookup', new GenericUser(['id' => 1]))->shouldRequestApproval(new Request(['message' => 'hi'], 'toolu_1')))
        ->toBeNull();
});

it('leaves an unauthorized call to the pipeline rather than pausing it', function () {
    // Pausing a call the principal may not make would put a knock in front of a
    // human for something authorization already refuses.
    $adapter = adapterFor(as: new GenericUser(['id' => 2]));

    expect($adapter->shouldRequestApproval(toolRequest()))->toBeNull()
        ->and((string) $adapter->handle(toolRequest()))->toContain('Not authorized')
        ->and(Approval::count())->toBe(0);
});

it('leaves invalid arguments to the pipeline rather than pausing them', function () {
    $adapter = adapterFor(as: new GenericUser(['id' => 1]));

    expect($adapter->shouldRequestApproval(new Request(['invoiceId' => 42], 'toolu_1')))->toBeNull()
        ->and((string) $adapter->handle(new Request(['invoiceId' => 42], 'toolu_1')))->toContain('Invalid arguments');
});

it('keeps one approval per tool call when the model repeats identical arguments', function () {
    $adapter = adapterFor(as: new GenericUser(['id' => 1]));

    foreach (['toolu_1', 'toolu_2'] as $id) {
        expect($adapter->shouldRequestApproval(toolRequest(id: $id)))->not->toBeNull();

        $adapter->handle(toolRequest(id: $id));
    }

    expect(Approval::whereStatus('pending')->count())->toBe(2);

    // Granting one releases only that call.
    app(ApprovalBroker::class)->decideViaArtisan(Approval::whereStatus('pending')->orderBy('id')->first()->id, approve: true);

    expect(json_decode((string) $adapter->handle(toolRequest(id: 'toolu_1')), true))
        ->toMatchArray(['invoiceId' => 42, 'status' => 'refunded'])
        ->and((string) $adapter->handle(toolRequest(id: 'toolu_2')))
        ->toContain('Approval required');
});

it('withholds decisions until every gated call has an answer', function () {
    // laravel/ai rejects a decision map that leaves any gated call unanswered,
    // so a half-answered batch must read as "keep waiting", not as a partial
    // resume that throws.
    $user = new GenericUser(['id' => 1]);
    $pending = [
        new PendingApproval('toolu_1', 'refund-invoice', ['invoiceId' => 42, 'amount' => 99.5]),
        new PendingApproval('toolu_2', 'refund-invoice', ['invoiceId' => 7, 'amount' => 10.0]),
    ];

    pauseRun($pending, $user);

    // Nothing is answered yet.
    expect(Agentic::approvalDecisions($pending, $user))->toBeNull()
        ->and(Approval::whereStatus('pending')->count())->toBe(2);

    // Still incomplete with only one answered.
    app(ApprovalBroker::class)->decideViaArtisan(Approval::whereStatus('pending')->orderBy('id')->first()->id, approve: true);

    expect(Agentic::approvalDecisions($pending, $user))->toBeNull();

    // Both answered: a complete map, mixing the grant with the refusal.
    app(ApprovalBroker::class)->decideViaArtisan(Approval::whereStatus('pending')->sole()->id, approve: false);

    $decisions = Agentic::approvalDecisions($pending, $user);

    expect($decisions)->not->toBeNull()
        ->and($decisions->get('toolu_1')->isApproved())->toBeTrue()
        ->and($decisions->get('toolu_2')->isRejected())->toBeTrue();
});

it('writes nothing while the map is rebuilt, however often the caller polls', function () {
    // Reading a paused run must stay a read. An app polling every few seconds
    // while a human decides would otherwise re-run the whole knock path per
    // tick, and any drift in it would show up as duplicate rows.
    $user = new GenericUser(['id' => 1]);
    $pending = [new PendingApproval('toolu_1', 'refund-invoice', ['invoiceId' => 42, 'amount' => 99.5])];

    pauseRun($pending, $user);

    Agentic::approvalDecisions($pending, $user);
    Agentic::approvalDecisions($pending, $user);
    Agentic::approvalDecisions($pending, $user);

    expect(Approval::count())->toBe(1)
        ->and(ActionLog::where('status', 'approval_required')->count())->toBe(1);
});

it('refuses a paused call it has no knock for rather than knocking on its behalf', function () {
    // Only the hook knocks, and only after Authorize. A reader that cannot find
    // the row is looking under the wrong principal — the grant it would create
    // could never be consumed by the run, so it says so instead of hanging.
    $decisions = Agentic::approvalDecisions(
        [new PendingApproval('toolu_1', 'refund-invoice', ['invoiceId' => 42, 'amount' => 99.5])],
        new GenericUser(['id' => 1]),
    );

    expect($decisions->get('toolu_1')->isRejected())->toBeTrue()
        ->and(Approval::count())->toBe(0);
});

it('expires an unanswered knock to a refusal instead of waiting forever', function () {
    // Nothing on the native path reads by args_hash, so the invocation is the
    // only scope that can reach the expiry rule. Without it a paused run polls
    // a permanently 'pending' row and never resumes — approvals stop expiring
    // to deny, which is the one thing the TTL exists to guarantee.
    $user = new GenericUser(['id' => 1]);
    $pending = [new PendingApproval('toolu_1', 'refund-invoice', ['invoiceId' => 42, 'amount' => 99.5])];

    pauseRun($pending, $user);

    expect(Agentic::approvalDecisions($pending, $user))->toBeNull();

    $this->travel(20)->minutes();

    expect(Agentic::approvalDecisions($pending, $user)->get('toolu_1')->isRejected())->toBeTrue()
        ->and(Approval::sole()->status)->toBe('expired');
});

it('reads as the ambient guard user when no principal is passed, like the adapter knocks', function () {
    // The knock, the read, and the execution that rides the grant must all
    // agree on who is asking: a reader defaulting to null while the tool runs
    // as the logged-in user looks for a row bound to a different principal,
    // finds none, and refuses consent a human really gave.
    auth()->setUser(new GenericUser(['id' => 1]));

    $adapter = adapterFor();
    $args = ['invoiceId' => 42, 'amount' => 99.5];

    $adapter->shouldRequestApproval(toolRequest($args));

    expect(Agentic::approvalDecisions([new PendingApproval('toolu_1', 'refund-invoice', $args)]))->toBeNull();

    app(ApprovalBroker::class)->decideViaArtisan(Approval::whereStatus('pending')->sole()->id, approve: true);

    expect(json_decode((string) $adapter->handle(toolRequest($args)), true))
        ->toMatchArray(['invoiceId' => 42, 'status' => 'refunded'])
        ->and(Approval::count())->toBe(1);
});

it('skips tool calls this package does not own', function () {
    // Null alone would also describe "still awaiting an answer". An owned call
    // always resolves to a decision — a state, or the refusal above when no
    // knock exists — so null here can only mean the call was never ours.
    expect(Agentic::approvalDecisions([
        new PendingApproval('toolu_1', 'some-other-agents-tool', []),
    ], new GenericUser(['id' => 1])))->toBeNull()
        ->and(Approval::count())->toBe(0);
});

it('refuses a call whose action changed after the human was asked', function () {
    $user = new GenericUser(['id' => 1]);
    $pending = [new PendingApproval('toolu_1', 'refund-invoice', ['invoiceId' => 42, 'amount' => 99.5])];

    pauseRun($pending, $user);
    app(ApprovalBroker::class)->decideViaArtisan(Approval::whereStatus('pending')->sole()->id, approve: true);

    Approval::query()->update(['definition_hash' => str_repeat('0', 64)]);

    expect(Agentic::approvalDecisions($pending, $user)->get('toolu_1')->isRejected())->toBeTrue();
});

it('records the tool-call id on the audit row, and the executed arguments', function () {
    $adapter = adapterFor(as: new GenericUser(['id' => 1]));

    $adapter->handle(toolRequest());
    app(ApprovalBroker::class)->decideViaArtisan(Approval::whereStatus('pending')->sole()->id, approve: true);
    $adapter->handle(toolRequest());

    $row = ActionLog::where('status', 'ok')->sole();

    expect($row->idempotency_key)->toBe('toolu_1')
        ->and($row->args)->toMatchArray(['invoiceId' => 42, 'amount' => 99.5])
        ->and(ActionLog::where('status', 'approval_required')->sole()->idempotency_key)->toBe('toolu_1');
});

it('leaves the idempotency key null on surfaces that have no tool-call id', function () {
    $cli = app(ContextFactory::class)->make(Surface::Cli, new GenericUser(['id' => 1]));

    try {
        Agentic::run('refund-invoice', ['invoiceId' => 42, 'amount' => 99.5], $cli);
    } catch (ApprovalRequiredException) {
        // The knock is enough — it is audited like any other outcome.
    }

    expect(ActionLog::sole())
        ->idempotency_key->toBeNull()
        ->surface->toBe('cli');
});
