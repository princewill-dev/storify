<?php

use App\Models\Business;
use App\Models\Customer;
use App\Models\KycApplication;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Plans\EntitlementRegistry;
use App\Support\Plans\Entitlements;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Str;
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
    // Pulled out before the factory sees it: `kyc` is this helper's own switch,
    // not a column on `users`.
    $kyc = $userAttributes['kyc'] ?? 'approved';
    unset($userAttributes['kyc']);

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

    // An approved KYC application, because a business that can *trade* has one.
    //
    // The platform refuses to take a customer's money for a business whose owner
    // has not been verified — see `StorePaymentGate` — and that check sits on
    // checkout, the till and invoice payment. A helper that builds a trading
    // business without it would make every payment test a test of the refusal.
    //
    // So the default is verified, and a test that is *about* verification asks
    // for the other state explicitly: `createBusinessOwner(['kyc' => 'none'])`
    // for no application at all, or `['kyc' => 'submitted']` for one awaiting
    // review. The opt-out is in `$userAttributes` because KYC is a fact about
    // the owner, not about the business.
    if ($kyc !== 'none') {
        $application = new KycApplication;

        $application->forceFill([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'status' => $kyc === 'approved'
                ? KycApplication::STATUS_APPROVED
                : KycApplication::STATUS_SUBMITTED,
            'legal_name' => 'Test Owner',
            'first_name' => 'Test',
            'last_name' => 'Owner',
            'phone_number' => '08030000000',
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'address_line' => '1 Test Street',
            'city' => 'Lagos',
            'state' => 'Lagos',
            'country' => 'Nigeria',
            'kyc_document_id' => 'NIN-123456789',
            'submitted_at' => now()->subDay(),
            'approved_at' => $kyc === 'approved' ? now()->subDay() : null,
        ])->save();
    }

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
/**
 * A verified customer of a store, and a bearer token that acts as them.
 *
 * Checkout requires an account — `storefront.checkout_requires_account` is on
 * by default and there is no product where it should not be — so a test that
 * needs an order to exist has to produce one the way the application does: as
 * somebody. These tests used to post an anonymous payload and let
 * `PlaceStorefrontOrder` fabricate a customer row, which meant the suite's
 * picture of checkout was the one path production no longer has. Signing in
 * costs a line per test and keeps them on the path that runs.
 *
 * The row is `verified` and `registered` because those are the two things the
 * gate checks, and a customer missing either is a different test — one that
 * asserts the refusal.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Customer, 1: string}
 */
function createStorefrontCustomer(Store $store, array $attributes = []): array
{
    $customer = Customer::create(array_merge([
        'business_id' => $store->business_id,
        'first_name' => 'Ada',
        'last_name' => 'Buyer',
        'email' => 'customer-'.Str::lower(Str::random(10)).'@example.test',
        'phone' => '08010000000',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
        'email_verified_at' => now(),
        'registered_at' => now(),
    ], $attributes));

    return [$customer, $customer->createToken('customer-access', ['customer'], now()->addHour())->plainTextToken];
}

/**
 * Bearer headers for a signed-in customer of the store, for the suites that
 * charge an order rather than test who may charge it.
 *
 * The storefront payment routes require a customer — an order number is public
 * (it is in the URL and in the emailed receipt), so it cannot also be the thing
 * that authorises a payment. A suite that walks the charge path therefore has to
 * be somebody, and this is the shortest way to say so.
 *
 * The customer this mints is not necessarily the order's owner: the orders these
 * suites build name nobody, and an ownerless order resolves for any signed-in
 * customer (see CheckoutRepository::scopeToCustomer() for why). Ownership itself
 * is asserted in StorefrontPartPaymentSettingTest, against orders that do name a
 * customer, so it is tested where it can actually fail.
 *
 * @return array<string, string>
 */
function storefrontCustomerHeaders(Store $store): array
{
    return ['Authorization' => 'Bearer '.createStorefrontCustomer($store)[1]];
}

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
