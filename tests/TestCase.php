<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Bootstrap the application only when it is configured for an isolated
     * test database. This check runs before RefreshDatabase can migrate or
     * truncate anything.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (! $app->environment('testing')) {
            throw new RuntimeException('Tests may only run with APP_ENV=testing.');
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $isIsolatedDatabase = $connection === 'sqlite'
            ? $database === ':memory:' || str_ends_with($database, '_test.sqlite')
            : str_ends_with($database, '_test');

        if (! $isIsolatedDatabase) {
            throw new RuntimeException(
                "Unsafe test database [{$database}] on connection [{$connection}]. "
                .'Use a database ending in _test or an in-memory SQLite database.'
            );
        }

        return $app;
    }
}
