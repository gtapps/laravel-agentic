<?php

namespace Gtapps\LaravelAgentic;

use Gtapps\LaravelAgentic\Kernel\Registry;
use Gtapps\LaravelAgentic\Schema\SchemaCompiler;
use Gtapps\LaravelAgentic\Schema\SpatieDataCompiler;
use Gtapps\LaravelAgentic\Surfaces\Cli\ActionCommand;
use Gtapps\LaravelAgentic\Surfaces\Cli\ApproveCommand;
use Gtapps\LaravelAgentic\Surfaces\Cli\CacheCommand;
use Gtapps\LaravelAgentic\Surfaces\Cli\ClearCommand;
use Gtapps\LaravelAgentic\Surfaces\Cli\DenyCommand;
use Gtapps\LaravelAgentic\Surfaces\Cli\ListCommand;
use Gtapps\LaravelAgentic\Surfaces\Cli\MakeActionCommand;
use Illuminate\Support\ServiceProvider;

class AgenticServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agentic.php', 'agentic');

        $this->app->singleton(SchemaCompiler::class, SpatieDataCompiler::class);
        $this->app->singleton(Registry::class);
        $this->app->singleton(AgenticManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app['config']->get('agentic.http.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/agentic.php');
        }

        // Only the clear direction: `optimize:clear` is the operator reflex that
        // fixes every other stale Laravel cache, and skipping agentic.php there
        // leaves a stale manifest behind with no obvious cause. `agentic:cache`
        // is deliberately NOT wired into `optimize` — it fails on zero actions,
        // which would break the deploy of an app that hasn't written one yet.
        $this->optimizes(clear: 'agentic:clear', key: 'agentic');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/agentic.php' => config_path('agentic.php'),
            ], 'agentic-config');

            $this->publishes([
                __DIR__.'/../stubs/AGENTS.md' => base_path('AGENTS.md'),
            ], 'agentic-agents-md');

            $this->commands([
                ListCommand::class,
                CacheCommand::class,
                ClearCommand::class,
                ApproveCommand::class,
                DenyCommand::class,
                ActionCommand::class,
                MakeActionCommand::class,
            ]);
        }
    }
}
