<?php

namespace Gtapps\LaravelAgentic\Tests;

use Gtapps\LaravelAgentic\AgenticServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            McpServiceProvider::class,
            AgenticServiceProvider::class,
        ];
    }
}
