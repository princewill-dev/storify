<?php

use App\Models\Business;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Build a fully authorized business owner without relying on production seed
 * data. Feature tests can override either model with the provided attributes.
 *
 * @return array{0: User, 1: Business}
 */
function createBusinessOwner(array $userAttributes = [], array $businessAttributes = []): array
{
    $user = User::factory()->create(array_merge([
        'role' => User::ROLE_BUSINESS_OWNER,
        'status' => 'active',
        'is_verified' => true,
    ], $userAttributes));

    $business = Business::create(array_merge([
        'user_id' => $user->id,
        'name' => fake()->company(),
        'status' => 'active',
    ], $businessAttributes));

    $user->update(['business_id' => $business->id]);
    $permissionSeeder = new SpatiePermissionSeeder;
    $permissionSeeder->run();
    $permissionSeeder->createRolesForBusiness($business);
    setPermissionsTeamId($business->id);

    return [$user->fresh(), $business->fresh()];
}
