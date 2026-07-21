<?php

use Gtapps\LaravelAgentic\Approvals\Approval;
use Gtapps\LaravelAgentic\Approvals\ApprovalBroker;
use Gtapps\LaravelAgentic\Facades\Agentic;
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

it('gates a mutating action without writing any approval state', function () {
    // laravel/ai asks this before it has anywhere to put a knock, and asks
    // again on resume. Any row written here would be duplicated by that second
    // ask, for consent a human may already have given.
    $approval = adapterFor(as: new GenericUser(['id' => 1]))->shouldRequestApproval(toolRequest());

    expect($approval)->not->toBeNull()
        ->and($approval->reason)->toContain("Approval required for action 'refund-invoice'")
        ->and(Approval::count())->toBe(0);
});

it('gives the same answer before and after a human decides', function () {
    // The answer decides which tool calls MUST carry a decision on resume. If a
    // grant flipped it to "ungated", laravel/ai would execute the call without
    // the app ever deciding it; if it flipped the other way, the app's decision
    // map would be rejected as incomplete.
    $adapter = adapterFor(as: new GenericUser(['id' => 1]));

    expect($adapter->shouldRequestApproval(toolRequest()))->not->toBeNull();

    // Knock, then grant, exactly as the execution path would.
    $adapter->handle(toolRequest());

    app(ApprovalBroker::class)->decideViaArtisan(Approval::whereStatus('pending')->sole()->id, approve: true);

    expect($adapter->shouldRequestApproval(toolRequest()))->not->toBeNull()
        ->and(Approval::whereStatus('granted')->count())->toBe(1);
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

    // First pass raises the knocks and answers nothing.
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

it('raises each knock once, however often the map is rebuilt while waiting', function () {
    $user = new GenericUser(['id' => 1]);
    $pending = [new PendingApproval('toolu_1', 'refund-invoice', ['invoiceId' => 42, 'amount' => 99.5])];

    Agentic::approvalDecisions($pending, $user);
    Agentic::approvalDecisions($pending, $user);
    Agentic::approvalDecisions($pending, $user);

    expect(Approval::count())->toBe(1);
});

it('skips tool calls this package does not own', function () {
    // Null alone would also describe "still awaiting an answer", so the row
    // count is the discriminator: an owned, gated call always leaves a knock
    // behind even while undecided, so zero rows proves it was never ours.
    expect(Agentic::approvalDecisions([
        new PendingApproval('toolu_1', 'some-other-agents-tool', []),
    ], new GenericUser(['id' => 1])))->toBeNull()
        ->and(Approval::count())->toBe(0);
});

it('refuses a call whose action changed after the human was asked', function () {
    $user = new GenericUser(['id' => 1]);
    $pending = [new PendingApproval('toolu_1', 'refund-invoice', ['invoiceId' => 42, 'amount' => 99.5])];

    Agentic::approvalDecisions($pending, $user);
    app(ApprovalBroker::class)->decideViaArtisan(Approval::whereStatus('pending')->sole()->id, approve: true);

    Approval::query()->update(['definition_hash' => str_repeat('0', 64)]);

    expect(Agentic::approvalDecisions($pending, $user)->get('toolu_1')->isRejected())->toBeTrue();
});
