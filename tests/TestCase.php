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

    /**
     * DB_CONNECTION selects the driver under test (defaults to Testbench's
     * in-memory sqlite "testing" connection); CI's cross-database matrix
     * points this at mysql/pgsql service containers to prove the approvals
     * unique index and guarded updates hold on real RDBMSes.
     */
    protected function getEnvironmentSetUp($app): void
    {
        $connection = env('DB_CONNECTION', 'testing');

        $app['config']->set('database.default', $connection);

        foreach (['mysql', 'pgsql'] as $driver) {
            if ($connection !== $driver) {
                continue;
            }

            $app['config']->set("database.connections.{$driver}", [
                'driver' => $driver,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', $driver === 'mysql' ? '3306' : '5432'),
                'database' => env('DB_DATABASE', 'agentic_test'),
                'username' => env('DB_USERNAME', $driver === 'mysql' ? 'root' : 'postgres'),
                'password' => env('DB_PASSWORD', ''),
            ]);
        }
    }
}
