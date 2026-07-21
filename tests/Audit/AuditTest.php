<?php

use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Audit\ActionLog;
use Gtapps\LaravelAgentic\Audit\Recorder;
use Gtapps\LaravelAgentic\Audit\Redactor;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Events\ActionExecuted;
use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\AuditedReadAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\CliOnlyAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\FailingAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\NoAuditAction;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['agentic.discovery.paths' => [realpath(__DIR__.'/../../workbench/app/Actions')]]);

    Agentic::register([CliOnlyAction::class, FailingAction::class, NoAuditAction::class, AuditedReadAction::class]);

    Gate::define('refund-invoice', fn (GenericUser $user, int $invoiceId) => $user->getAuthIdentifier() === 1);
});

function auditCtx(int $userId = 1)
{
    return app(ContextFactory::class)->make(Surface::Cli, new GenericUser(['id' => $userId]));
}

/**
 * Binds a Recorder whose every write fails, to exercise the Runner's
 * deliberately asymmetric handling of audit-infrastructure failures.
 */
function bindFailingRecorder(): void
{
    app()->instance(Recorder::class, new class(app(Redactor::class), app('config')) extends Recorder
    {
        public function record(ActionCall $call, string $status, ?string $error = null): void
        {
            throw new RuntimeException('audit write failed');
        }
    });
}

it('writes ok rows for successful non-readOnly runs, with redaction and forensics fields', function () {
    config(['agentic.redact' => ['reason']]);

    Event::fake([ActionExecuted::class]);

    $key = null;

    try {
        Agentic::run('refund-invoice', ['invoiceId' => 7, 'amount' => 10.0], auditCtx());
    } catch (ApprovalRequiredException $e) {
        $key = $e->key;
    }

    $this->artisan('agentic:approve', ['key' => $key])->assertSuccessful();

    Agentic::run('refund-invoice', ['invoiceId' => 7, 'amount' => 10.0], auditCtx());

    $rows = ActionLog::where('action', 'refund-invoice')->orderBy('created_at')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->status)->toBe('approval_required')
        ->and($rows[0]->approval_id)->not->toBeNull()
        ->and($rows[1]->status)->toBe('ok');

    $ok = $rows[1];

    expect($ok->surface)->toBe('cli')
        ->and($ok->user_id)->toBe('1')
        ->and($ok->args)->toEqual([
            'invoiceId' => 7,
            'amount' => 10,
            'reason' => '[redacted]',
            'notify' => true,
        ])
        ->and($ok->args_hash)->toBe($key)
        ->and($ok->definition_hash)->not->toBeEmpty()
        ->and($ok->request_id)->not->toBeEmpty()
        ->and($ok->duration_ms)->toBeGreaterThanOrEqual(0);

    Event::assertDispatchedTimes(ActionExecuted::class, 1);
});

it('writes a denied row on authorization failure', function () {
    try {
        Agentic::run('refund-invoice', ['invoiceId' => 7, 'amount' => 10.0], auditCtx(userId: 9));
        $this->fail('Expected ActionDenied');
    } catch (ActionDenied) {
    }

    expect(ActionLog::where('action', 'refund-invoice')->where('status', 'denied')->count())->toBe(1);
});

it('writes an error row when the handler throws', function () {
    try {
        Agentic::run('failing', ['message' => 'boom'], auditCtx());
        $this->fail('Expected RuntimeException');
    } catch (RuntimeException) {
    }

    $row = ActionLog::where('action', 'failing')->firstOrFail();

    expect($row->status)->toBe('error')
        ->and($row->error)->toContain('handler exploded');
});

it('preserves the original exception when error-path audit recording fails', function () {
    bindFailingRecorder();

    Log::spy();

    expect(fn () => Agentic::run('failing', ['message' => 'boom'], auditCtx()))
        ->toThrow(RuntimeException::class, 'handler exploded');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (...$args) => str_contains($args[0], 'failing')
            && str_contains($args[0], 'audit write failed')
    );
});

it('fails loud when success-path audit recording fails', function () {
    bindFailingRecorder();

    expect(fn () => Agentic::run('audited-read', ['message' => 'hi'], auditCtx()))
        ->toThrow(RuntimeException::class, 'audit write failed');
});

it('skips audit for readOnly actions and per-action opt-out', function () {
    Agentic::run('cli-only', ['message' => 'hi'], auditCtx());
    Agentic::run('no-audit', ['message' => 'psst'], auditCtx());

    expect(ActionLog::count())->toBe(0);
});

it('skips audit entirely when the master switch is off', function () {
    config(['agentic.audit.enabled' => false]);

    try {
        Agentic::run('failing', ['message' => 'boom'], auditCtx());
    } catch (RuntimeException) {
    }

    expect(ActionLog::count())->toBe(0);
});

it('writes an ok row for a readOnly action that opts into audit', function () {
    Agentic::run('audited-read', ['message' => 'hi'], auditCtx());

    $rows = ActionLog::where('action', 'audited-read')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->status)->toBe('ok')
        ->and($rows[0]->surface)->toBe('cli');
});

it('resolves the connection from agentic.audit.connection, defaulting to null', function () {
    expect((new ActionLog)->getConnectionName())->toBeNull();

    config(['agentic.audit.connection' => 'sqlite']);

    expect((new ActionLog)->getConnectionName())->toBe('sqlite');
});
