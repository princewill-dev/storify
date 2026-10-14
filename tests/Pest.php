<?php

use App\Models\Business;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Plans\EntitlementRegistry;
use App\Support\Plans\Entitlements;
use Database\Seeders\SpatiePermissionSeeder;
use Tests\Support\SeedsPlatformFixturesOnce;
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
    ->use(SeedsPlatformFixturesOnce::class)
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
 * The permission catalogue and the platform roles are already in the database by
 * the time this runs — `Tests\Support\SeedsPlatformFixturesOnce` commits them
 * once per test process. All that is left for a test to do is this business's own
 * roles, which is the part that genuinely differs test to test.
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

    // `welcome_seen_at` is set here for the same reason the migration backfills
    // it: a business that already exists has already been onboarded, and this
    // helper builds nothing else. Without it every test that reads `next` —
    // and the auth payload is where almost all of them read it — would be told
    // to send an established shop with orders to the signup interstitial. A
    // test that is *about* the interstitial asks for it explicitly, by passing
    // `['welcome_seen_at' => null]` in `$businessAttributes`.
    $business = Business::create(array_merge([
        'user_id' => $user->id,
        'name' => fake()->company(),
        'status' => 'active',
        'welcome_seen_at' => now(),
    ], $businessAttributes));

    $user->update(['business_id' => $business->id]);
    (new SpatiePermissionSeeder)->createRolesForBusiness($business);
    setPermissionsTeamId($business->id);

    return [$user->fresh(), $business->fresh()];
}

/**
 * The platform's default plan, holding every module with no limit.
 *
 * Module APIs are gated by the business's plan (WS-55 — `plan.feature:*`:
 * accounting, POS, plugins and invoices), and a suite that exercises a module is
 * about that module rather than about the gate. Those suites build their
 * businesses with `createBusinessOwner(['trial_ends_at' => now()->addWeek()])` —
 * a live trial with `selected_plan_id` unset, which is the ordinary state of a
 * business in its first week, and which {@see Entitlements::for()} resolves to
 * the platform's default plan. The test database has no plan rows at all
 * (`SeedsPlatformFixturesOnce` seeds the permission catalogue and nothing else),
 * so without a default plan a trial resolves down to the *registry* defaults —
 * where all four module toggles are `false` — and their routes answer
 * `403 plan_feature_unavailable`.
 *
 * So a suite that means "this business can use this module" has to say so, and
 * this is the smallest honest way to say it: one plan row, installed per test
 * inside the transaction the suite already rolls back, which the existing call
 * sites resolve to without any of them changing.
 *
 * **Why the trial resolves here rather than a subscription being taken out.** A
 * trial is a trial *of a plan*, and this is that plan. It is also what the
 * product does: a real install has a default plan row, so a trialling business
 * resolves to it the moment the owner has not yet picked a tier. Making the
 * suites exercise that path keeps them testing the arrangement the platform
 * actually runs on, instead of one that only exists in tests.
 *
 * **Why every toggle is `true` and every limit `null`.** These suites are not
 * making a claim about what a plan should cost or contain; they are clearing the
 * gate out of the way so the module underneath can be tested. A restricted plan
 * here would refuse a creation in a test that is about a journal entry or a till
 * sale — the same class of mistake as the one this helper fixes, wearing a
 * different hat. It is deliberately the shape of the seeded `Enterprise` tier
 * ({@see SubscriptionPlanSeeder::TIERS}), because that is the plan a business
 * that can do everything is on.
 */
function defaultPlanIncludingEveryModule(): SubscriptionPlan
{
    return SubscriptionPlan::create([
        'name' => 'Suite Default',
        'description' => 'The plan a suite that exercises a module puts on the platform.',
        'amount' => 50000,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_default' => true,
        'is_trial' => false,
        'tier' => 3,
        'sort_order' => 1,
        'entitlements' => [
            EntitlementRegistry::STORES => null,
            EntitlementRegistry::STAFF => null,
            EntitlementRegistry::PRODUCTS => null,
            EntitlementRegistry::WAREHOUSES => null,
            EntitlementRegistry::OWN_GATEWAY => true,
            EntitlementRegistry::STOREFRONT => true,
            EntitlementRegistry::ACCOUNTING => true,
            EntitlementRegistry::POS => true,
            EntitlementRegistry::PLUGINS => true,
            EntitlementRegistry::INVOICES => true,
        ],
    ]);
}
