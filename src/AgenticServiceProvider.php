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
            ]);
        }
    }
}
