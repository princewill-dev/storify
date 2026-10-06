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

    /**
     * Set the bearer token for subsequent requests.
     *
     * Laravel's Sanctum guard caches the user it resolved on the previous
     * request for the whole life of the test, so switching tokens mid-test
     * without clearing the guards silently keeps the OLD identity — a test
     * that authenticates as staff and then as the owner would still be
     * asserting as staff. Clearing the guards here makes every token switch
     * take effect, which is what the caller obviously intends.
     */
    public function withToken($token, $type = 'Bearer')
    {
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }
}
