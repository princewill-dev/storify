<?php

namespace Tests\Support;

use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase, plus the platform-wide seeding every feature test needs.
 *
 * Three facts about how the suite runs decide the shape of this:
 *
 * 1. `RefreshDatabase` migrates the test database **once per process** and then
 *    wraps each test in a transaction that is rolled back afterwards. Anything a
 *    test writes is gone by the next test.
 * 2. The permission catalogue and the platform roles are not test data. They are
 *    identical in every test and no test changes them, so they belong outside the
 *    per-test transaction, not inside it.
 * 3. There is exactly one moment that satisfies both: after `migrate:fresh` has
 *    built the schema and before the first test's transaction begins. That is
 *    `migrateDatabases()`, and it is why this trait overrides it.
 *
 * Seeding there commits the catalogue once, so it is visible to every test in the
 * run. Doing it per test instead — which is what `createBusinessOwner()` used to
 * do — meant running the entire seeder, including its loop over every business in
 * the database, over a thousand times. That was roughly three quarters of the
 * suite's wall clock.
 *
 * Note the ordering that makes this correct without a second seeder: at this
 * point the database has just been dropped, so it holds no businesses, and the
 * seeder's per-business loop has nothing to do. What lands is exactly the platform
 * catalogue. Businesses created by a test still get their own roles, per test,
 * from `createRolesForBusiness()` — that part is genuinely per-test work.
 */
trait SeedsPlatformFixturesOnce
{
    use RefreshDatabase;

    /**
     * Build the schema, then commit the platform fixtures above the transaction
     * boundary that every test will run inside.
     *
     * The migration command is spelled out rather than delegated because a trait
     * cannot call the sibling trait's implementation it is overriding — PHP
     * resolves `parent::` to the parent *class*, and this method lives in a trait.
     * `migrateFreshUsing()` comes from the same `CanConfigureMigrationCommands`
     * trait `RefreshDatabase` itself uses, so the flags stay in step with it.
     */
    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());

        $this->seed(SpatiePermissionSeeder::class);
    }
}
