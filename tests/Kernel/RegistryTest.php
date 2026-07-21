<?php

use Gtapps\LaravelAgentic\Facades\Agentic;
use Gtapps\LaravelAgentic\Kernel\Registry;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\AuditedReadAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\BadFallbackAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\IncoherentCompactAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\NoAuditAction;
use Gtapps\LaravelAgentic\Tests\Fixtures\Actions\PackagePing;
use Gtapps\LaravelAgentic\Tests\Fixtures\ScanActions\AppPing;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

afterEach(function () {
    app(Registry::class)->clearCache();
});

it('lets app-scanned actions override package-registered ones by name', function () {
    config(['agentic.discovery.paths' => [realpath(__DIR__.'/../Fixtures/ScanActions')]]);
    Agentic::register([PackagePing::class]);

    expect(app(Registry::class)->find('ping')->handler)->toBe(AppPing::class);
});

it('logs a warning and skips a syntactically broken action file without crashing', function () {
    $dir = sys_get_temp_dir().'/agentic-broken-'.uniqid();
    File::makeDirectory($dir);
    File::put($dir.'/BrokenAction.php', "<?php\n\nclass BrokenAction".uniqid()." {\n    public function handle(");

    config(['agentic.discovery.paths' => [$dir, realpath(__DIR__.'/../Fixtures/ScanActions')]]);

    Log::spy();

    $definitions = app(Registry::class)->definitions();

    expect($definitions)->toHaveKey('ping');

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'BrokenAction'))->once();

    File::deleteDirectory($dir);
});

it('skips Fallback actions without outputFallback() at registration', function () {
    Log::spy();
    Agentic::register([BadFallbackAction::class]);

    expect(app(Registry::class)->find('bad-fallback'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'outputFallback'))
        ->once();
});

it('skips actions whose compact schema misses required full-schema fields', function () {
    Log::spy();
    Agentic::register([IncoherentCompactAction::class]);

    expect(app(Registry::class)->find('incoherent-compact'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'missing required field'))
        ->once();
});

it('lists actions with agentic:list', function () {
    config(['agentic.discovery.paths' => [realpath(__DIR__.'/../../workbench/app/Actions')]]);

    $this->artisan('agentic:list')
        ->expectsOutputToContain('refund-invoice')
        ->assertSuccessful();
});

it('shows the effective Audit column per action', function () {
    config(['agentic.discovery.paths' => []]);
    Agentic::register([AuditedReadAction::class, NoAuditAction::class]);

    $this->artisan('agentic:list')
        ->expectsTable(
            ['Name', 'Surfaces', 'Read-only', 'Needs approval', 'Audit'],
            [
                ['audited-read', 'mcp, ai-tool, http, cli, job', 'yes', 'no', 'yes'],
                ['no-audit', 'mcp, ai-tool, http, cli, job', 'no', 'no', 'no'],
            ]
        )
        ->assertSuccessful();
});

it('reports Audit as no for every action when the global switch is off', function () {
    config(['agentic.discovery.paths' => [], 'agentic.audit.enabled' => false]);
    Agentic::register([AuditedReadAction::class]);

    // The per-action policy still resolves true...
    expect(app(Registry::class)->find('audited-read')->audit)->toBeTrue();

    // ...but the effective column the list command shows folds in the global switch.
    $this->artisan('agentic:list')
        ->expectsTable(
            ['Name', 'Surfaces', 'Read-only', 'Needs approval', 'Audit'],
            [['audited-read', 'mcp, ai-tool, http, cli, job', 'yes', 'no', 'no']]
        )
        ->assertSuccessful();
});

it('caches and clears the manifest like route:cache', function () {
    config(['agentic.discovery.paths' => [realpath(__DIR__.'/../../workbench/app/Actions')]]);

    $registry = app(Registry::class);

    $this->artisan('agentic:cache')->assertSuccessful();

    expect(is_file($registry->cachePath()))->toBeTrue();

    // A fresh registry with no scan paths must load from the cache file.
    config(['agentic.discovery.paths' => []]);
    app()->forgetInstance(Registry::class);

    $cached = app(Registry::class);

    expect($cached->find('refund-invoice'))->not->toBeNull()
        ->and($cached->find('refund-invoice')->definitionHash)
        ->toBe($registry->find('refund-invoice')->definitionHash);

    $this->artisan('agentic:clear')->assertSuccessful();

    expect(is_file($registry->cachePath()))->toBeFalse();

    app()->forgetInstance(Registry::class);

    expect(app(Registry::class)->definitions())->toBe([]);
});
