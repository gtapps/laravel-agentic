<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config(['agentic.discovery.paths' => [__DIR__.'/../Actions']]);
    }
}
