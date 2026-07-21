<?php

namespace Gtapps\LaravelAgentic\Tests;

/**
 * The http.enabled guard runs at provider boot, before beforeEach() —
 * config set there is too late. Tests that exercise the HTTP surface use
 * this instead of the base TestCase to enable it before boot.
 */
abstract class HttpEnabledTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('agentic.http.enabled', true);
    }
}
