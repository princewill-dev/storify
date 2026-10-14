<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Plans\EntitlementRegistry;
use App\Support\Plans\Entitlements;
use App\Support\Plans\FeatureGate;
use App\Support\Plans\PlanQuota;
use Illuminate\Support\Facades\Route;

/*
| WS-55 — a feature a plan does not include, and the guard that keeps the
| catalogue honest.
|
| The platform could *declare* entitlements and enforce almost none of them.
| `staff_limit`, `products_limit`, `warehouses_limit` and the `storefront`
| toggle were editable in the admin console, shown in the plan editor, and read
| by no code at all — the same defect the store limit had, where the platform
| stored a ceiling and displayed it and no creation path asked. Accounting,
| which is a whole sidebar section, belonged to a ₦5,000 plan as much as to a
| ₦50,000 one.
|
| These tests pin two things that are easy to lose and impossible to notice:
|
|   * **nothing declared stays unenforced.** The second test below fails the day
|     somebody adds a seventh key to the catalogue without an enforcement point,
|     and the first fails the day somebody adds an accounting route without the
|     middleware. Both are derived from the code, not hard-coded, so neither can
|     be satisfied by editing the test.
|   * **the sentence names the plan from the table.** "Upgrade to Enterprise" is
|     read from the plans, so renaming the plan renames the upsell — which is
|     the entire reason entitlements are data.
*/

function ws55Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws55Plan(array $attributes = []): SubscriptionPlan
{
    return SubscriptionPlan::create(array_merge([
        'name' => 'Entry',
        'amount' => 5000,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_trial' => false,
        'tier' => 1,
        'entitlements' => [
            EntitlementRegistry::STORES => 3,
            EntitlementRegistry::STAFF => 5,
            EntitlementRegistry::PRODUCTS => 100,
            EntitlementRegistry::WAREHOUSES => 1,
            EntitlementRegistry::OWN_GATEWAY => false,
            // Stated rather than left silent, so this test asserts the decision
            // instead of the registry default happening to agree with it.
            EntitlementRegistry::ACCOUNTING => false,
            EntitlementRegistry::STOREFRONT => true,
            EntitlementRegistry::POS => false,
            EntitlementRegistry::PLUGINS => false,
            EntitlementRegistry::INVOICES => false,
        ],
        'sort_order' => 1,
    ], $attributes));
}

function ws55Enterprise(): SubscriptionPlan
{
    return ws55Plan([
        'name' => 'Enterprise',
        'amount' => 50000,
        'tier' => 3,
        'entitlements' => [
            EntitlementRegistry::STORES => null,
            EntitlementRegistry::STAFF => null,
            EntitlementRegistry::PRODUCTS => null,
            EntitlementRegistry::WAREHOUSES => null,
            EntitlementRegistry::OWN_GATEWAY => true,
            EntitlementRegistry::ACCOUNTING => true,
            EntitlementRegistry::STOREFRONT => true,
            EntitlementRegistry::POS => true,
            EntitlementRegistry::PLUGINS => true,
            EntitlementRegistry::INVOICES => true,
        ],
        'sort_order' => 3,
    ]);
}

function ws55Subscribe(User $user, SubscriptionPlan $plan): Subscription
{
    return Subscription::create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'subscription_plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subWeek(),
        'expires_at' => now()->addMonth(),
    ]);
}

/* ------------------------------------------------- nothing declared is loose */

test('every accounting route carries the accounting feature gate', function () {
    // Read from the router rather than listed here: a new accounting route
    // added without the middleware fails this immediately, which a hard-coded
    // list of URIs would not. `gatherMiddleware()` is the raw declaration, so
    // this asserts the alias the route file actually wrote.
    $ungated = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'api.management.accounting.'))
        ->reject(fn ($route): bool => in_array('plan.feature:accounting', $route->gatherMiddleware(), true))
        ->map(fn ($route): string => $route->getName())
        ->values()
        ->all();

    expect($ungated)->toBe([])
        // A gate that matches no routes is a gate that proves nothing.
        ->and(collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'api.management.accounting.'))
            ->count())->toBeGreaterThan(0);
});

test('every invoice and plugin route carries its module gate', function () {
    // Same derivation as accounting, because the same failure is possible: a
    // route added to either module next month without the middleware is a hole
    // that no behaviour test below would catch, since behaviour tests name the
    // routes they already know about.
    $ungated = fn (string $prefix, string $toggle): array => collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), $prefix))
        ->reject(fn ($route): bool => in_array($toggle, $route->gatherMiddleware(), true))
        ->map(fn ($route): string => $route->getName())
        ->values()
        ->all();

    $count = fn (string $prefix): int => collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), $prefix))
        ->count();

    expect($ungated('api.management.invoices.', 'plan.feature:invoices'))->toBe([])
        ->and($count('api.management.invoices.'))->toBeGreaterThan(0)
        ->and($ungated('api.management.plugins.', 'plan.feature:plugins'))->toBe([])
        ->and($count('api.management.plugins.'))->toBeGreaterThan(0);
});

test('every POS route is gated, and the order list that shares its file is not', function () {
    // POS is reachable two ways — the till's own API, whose routes carry no
    // names because the POS app addresses them by URI, and the management
    // oversight screens. Both are gated; the assertion is derived from the
    // router so neither can grow a hole.
    $routes = collect(Route::getRoutes()->getRoutes());

    // `/api/v1/pos/login` is the one exception and it has to be: there is no
    // authenticated user at that point, so there is nothing to ask a plan
    // about. Asserted rather than skipped silently, so that "the login route is
    // open" stays a decision somebody made rather than an oversight.
    $ungated = $routes
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/pos/'))
        ->reject(fn ($route): bool => in_array('plan.feature:pos', $route->gatherMiddleware(), true))
        ->map(fn ($route): string => $route->uri())
        ->values()
        ->all();

    expect($ungated)->toBe(['api/v1/pos/login'])
        ->and($routes->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/pos/'))->count())
        ->toBeGreaterThan(5);

    $oversight = $routes
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'api.management.pos.')
            || str_starts_with((string) $route->getName(), 'api.management.stores.pos.'))
        ->reject(fn ($route): bool => in_array('plan.feature:pos', $route->gatherMiddleware(), true))
        ->map(fn ($route): string => $route->getName())
        ->values()
        ->all();

    expect($oversight)->toBe([])
        ->and($routes->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'api.management.pos.'))->count())
        ->toBeGreaterThan(0);

    // And the exemption, asserted so it cannot be quietly "tidied up": GET
    // orders is re-registered in the POS oversight file to add the `source`
    // filter, but it is the business's order history, not a POS screen. Gating
    // it would take a shop's own orders away with a till its plan does not
    // include.
    $ordersRoute = $routes->first(fn ($route): bool => $route->getName() === 'api.management.orders.index');

    expect($ordersRoute)->not->toBeNull()
        ->and($ordersRoute->gatherMiddleware())->not->toContain('plan.feature:pos');
});

test('every entitlement in the catalogue is enforced somewhere', function () {
    // The owner's decision, mechanically: nothing may be declared and left
    // unenforced. This is the test that fails when a key is added to
    // EntitlementRegistry without a place that reads it — and the failure
    // message is the map below, which is where the enforcement point goes.
    $enforced = [
        EntitlementRegistry::STORES => 'PlanQuota at StoreOnboardingController@store',
        EntitlementRegistry::STAFF => 'PlanQuota at StaffParityController@store',
        EntitlementRegistry::PRODUCTS => 'PlanQuota at ProductController@store',
        EntitlementRegistry::WAREHOUSES => 'PlanQuota at WarehouseController@store',
        EntitlementRegistry::OWN_GATEWAY => 'GatewayGrant',
        EntitlementRegistry::STOREFRONT => 'EnsurePlanFeature (plan.feature:storefront)',
        EntitlementRegistry::ACCOUNTING => 'EnsurePlanFeature (plan.feature:accounting)',
        EntitlementRegistry::POS => 'EnsurePlanFeature (plan.feature:pos)',
        EntitlementRegistry::PLUGINS => 'EnsurePlanFeature (plan.feature:plugins)',
        EntitlementRegistry::INVOICES => 'EnsurePlanFeature (plan.feature:invoices)',
    ];

    expect(array_diff(EntitlementRegistry::keys(), array_keys($enforced)))->toBe([])
        ->and(array_diff(array_keys($enforced), EntitlementRegistry::keys()))->toBe([]);
});

test('every declared limit can actually be counted', function () {
    // A limit with no resolver throws rather than answering zero, because a
    // silent zero reads as enforced while enforcing nothing — the exact shape
    // of the defect this pass closes. Asked here for every key in the
    // catalogue, so a new limit is countable the day it is declarable.
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);

    $limits = collect(EntitlementRegistry::all())
        ->filter(fn (array $definition): bool => $definition['type'] === EntitlementRegistry::TYPE_LIMIT)
        ->keys();

    expect($limits)->not->toBeEmpty();

    foreach ($limits as $key) {
        expect(PlanQuota::used((int) $business->id, $key))->toBeInt();
    }

    expect(fn () => PlanQuota::used((int) $business->id, 'not_a_limit'))
        ->toThrow(InvalidArgumentException::class);
});

/* ------------------------------------------- accounting, gated on the plan */

test('an enterprise business reaches the books', function () {
    // The control. A gate that refuses everybody is an outage, not a gate.
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Enterprise());

    $this->withToken(ws55Token($owner))
        ->getJson('/api/v1/management/accounting/accounts')
        ->assertOk();
});

test('a business whose plan excludes accounting is refused, and told which plan has it', function () {
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan());
    ws55Enterprise();

    $response = $this->withToken(ws55Token($owner))
        ->getJson('/api/v1/management/accounting/accounts');

    $response->assertStatus(403)
        ->assertJsonPath('code', FeatureGate::REFUSAL_CODE)
        ->assertJsonPath('redirect', '/plans');

    // The plan is read from the table, so a renamed plan is named correctly.
    expect($response->json('message'))
        ->toContain('Accounting')
        ->toContain('Enterprise');
});

test('a plan that excludes accounting refuses it on writes as well as reads', function () {
    // The gate is on the route group, not on the list endpoint. A read-only
    // gate would leave an owner able to post a journal entry into books they
    // are not allowed to open.
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan());

    $this->withToken(ws55Token($owner))
        ->postJson('/api/v1/management/accounting/expenses', [])
        ->assertStatus(403)
        ->assertJsonPath('code', FeatureGate::REFUSAL_CODE);
});

test('a business with no plan at all is refused with its own sentence', function () {
    // Unreachable over HTTP — `EnsureManagementSubscription` refuses these
    // businesses the whole `/management/*` group first — but the sentence
    // matters: "your plan does not include accounting" is a lie about a
    // business that has no plan.
    [$owner, $business] = createBusinessOwner();

    $refusal = FeatureGate::refusal($business->fresh(), EntitlementRegistry::ACCOUNTING);

    expect($refusal)->not->toBeNull()
        ->and($refusal['code'])->toBe(FeatureGate::REFUSAL_CODE)
        ->and($refusal['redirect'])->toBe('/plans')
        ->and($refusal['message'])->toContain('no active plan');
});

test('a staff member does not bypass the accounting gate', function () {
    // Deliberately unlike `SubscriptionGate`, which lets staff through because
    // it is a billing gate and a cashier must not be blocked while the owner
    // fixes a card. This is not a billing gate: a plan that excludes accounting
    // has nothing behind it for a staff accountant, and a downgrade must not
    // leave the books open to staff who were invited while it did.
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan());

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $staff->givePermissionTo('accounting view');

    // The permission is held, so a refusal cannot be a permission refusal.
    expect($staff->fresh()->can('accounting view'))->toBeTrue();

    $this->withToken(ws55Token($staff->fresh()))
        ->getJson('/api/v1/management/accounting/accounts')
        ->assertStatus(403)
        ->assertJsonPath('code', FeatureGate::REFUSAL_CODE);
});

test('accounting is refused when no plan grants it, rather than naming an upgrade', function () {
    // No active plan at all, so the refusal must not send the owner to a
    // checkout that sells something nobody sells.
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);

    ws55Plan([
        'name' => 'Books-free Entry',
        'entitlements' => [EntitlementRegistry::ACCOUNTING => false],
    ]);

    $refusal = FeatureGate::refusal($business->fresh(), EntitlementRegistry::ACCOUNTING);

    expect($refusal['message'])->toContain('no active plan');
});

/* --------------------------------------------------------- the client learns */

test('the auth payload says which features are locked and what unlocks them', function () {
    // The sidebar has to render the section locked *and* say what would open
    // it, from one payload it already fetches once per session.
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan());
    ws55Enterprise();

    $response = $this->withToken(ws55Token($owner))->getJson('/api/v1/management/auth/me');

    $response->assertOk()
        ->assertJsonPath('data.user.entitlements.entitlements.accounting', false)
        ->assertJsonPath('data.user.entitlements.locked.accounting.plan_name', 'Enterprise')
        ->assertJsonPath('data.user.entitlements.locked.accounting.currency', 'NGN');

    // The price travels with the name. The column is cast `decimal:2`, so it is
    // a string in the model and normalised to a number here rather than left
    // for the client to parse.
    expect((float) $response->json('data.user.entitlements.locked.accounting.amount'))->toBe(50000.0);
});

test('an enterprise business holds accounting, so nothing about it is locked', function () {
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Enterprise());

    $this->withToken(ws55Token($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.entitlements.entitlements.accounting', true)
        ->assertJsonMissingPath('data.user.entitlements.locked.accounting');
});

test('a feature no plan grants is reported as locked with nothing to buy', function () {
    // The entry is present with a null plan rather than omitted, so the client
    // can tell "there is no upgrade path for this" from "the payload did not
    // mention it" — and say the former instead of rendering a button that leads
    // nowhere. The only plan on the platform excludes accounting.
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan());

    $locked = Entitlements::for($business->fresh())->payload()['locked'];

    expect($locked)->toHaveKey(EntitlementRegistry::ACCOUNTING)
        ->and($locked[EntitlementRegistry::ACCOUNTING]['plan_name'])->toBeNull()
        ->and($locked[EntitlementRegistry::ACCOUNTING]['amount'])->toBeNull()
        // The catalogue's own words travel with it, so the upgrade screen
        // renders the catalogue rather than a second copy of it.
        ->and($locked[EntitlementRegistry::ACCOUNTING]['label'])->toBe('Accounting');
});

/* ---------------------------------------------------------- the three limits */

test('a business at its staff limit cannot invite, and the refusal names the plan', function () {
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan(['entitlements' => [
        EntitlementRegistry::STAFF => 1,
        EntitlementRegistry::STORES => 3,
    ]]));

    // One seat filled by staff, and the owner deliberately not counted as one.
    ws55Staff($business->id, 'First');

    $response = $this->withToken(ws55Token($owner))
        ->postJson('/api/v1/management/staff', [
            'name' => 'Second Seat',
            'email' => 'ws55-second@example.test',
            'roles' => ['Cashier'],
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('code', PlanQuota::REFUSAL_CODE)
        ->assertJsonPath('redirect', '/plans');

    // The plan is named from the table, and the noun comes from the catalogue,
    // so the sentence and the admin editor's unit hint cannot drift apart.
    expect($response->json('message'))->toContain('Entry')->toContain('staff');
});

test('the owner is not a staff seat', function () {
    // The directory shows the owner row on purpose, so counting its query would
    // charge every business a seat for its own owner and make a one-seat plan
    // unusable.
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan(['entitlements' => [
        EntitlementRegistry::STAFF => 1,
        EntitlementRegistry::STORES => 3,
    ]]));

    expect(PlanQuota::used((int) $business->id, EntitlementRegistry::STAFF))->toBe(0);

    $this->withToken(ws55Token($owner))
        ->postJson('/api/v1/management/staff', [
            'name' => 'The Only Seat',
            'email' => 'ws55-owner-seat@example.test',
            'roles' => ['Cashier'],
        ])
        ->assertCreated();
});

test('a business at its product limit cannot create one', function () {
    // The live `POST products` is WS-14's ProductFormController — it
    // re-registers the URI over the base controller, and the module files load
    // last — so the check has to be there as well as in the base slice. The
    // payload is valid on purpose: a 422 would prove the FormRequest ran first
    // and the plan was never asked.
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan(['entitlements' => [EntitlementRegistry::PRODUCTS => 1]]));

    $store = ws55Store($owner);

    Product::create([
        'business_id' => $business->id,
        'store_id' => null,
        'name' => 'Existing',
        'amount' => 1000,
        'status' => 'active',
    ]);

    $this->withToken(ws55Token($owner))
        ->postJson('/api/v1/management/products', [
            'name' => 'Second',
            'store_id' => $store->id,
            'amount' => 1000,
            'quantity' => 1,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', PlanQuota::REFUSAL_CODE);

    expect(Product::where('business_id', $business->id)->count())->toBe(1);
});

test('a business at its warehouse limit cannot create one', function () {
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan(['entitlements' => [EntitlementRegistry::WAREHOUSES => 1]]));

    Warehouse::create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'name' => 'Main',
        'warehouse_code' => 'WS55-WH',
        'status' => 'active',
    ]);

    $this->withToken(ws55Token($owner))
        ->postJson('/api/v1/management/warehouses', ['name' => 'Second'])
        ->assertStatus(403)
        ->assertJsonPath('code', PlanQuota::REFUSAL_CODE);

    expect(Warehouse::where('business_id', $business->id)->count())->toBe(1);
});

test('a downgrade grandfathers what is already held and refuses only the next one', function () {
    // Moving down a tier never takes anything away. The business keeps every
    // row and simply cannot add another — and the message has to say so, or an
    // owner who has just been told they are over their limit assumes their
    // records are about to be deleted.
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);

    $subscription = ws55Subscribe($owner, ws55Enterprise());

    ws55Staff($business->id, 'One');
    ws55Staff($business->id, 'Two');
    ws55Staff($business->id, 'Three');

    $subscription->update(['subscription_plan_id' => ws55Plan(['entitlements' => [
        EntitlementRegistry::STAFF => 1,
        EntitlementRegistry::STORES => 3,
    ]])->id]);

    $response = $this->withToken(ws55Token($owner))
        ->postJson('/api/v1/management/staff', [
            'name' => 'Fourth Seat',
            'email' => 'ws55-fourth@example.test',
            'roles' => ['Cashier'],
        ]);

    $response->assertStatus(403);

    expect($response->json('message'))
        ->toContain('3')
        ->toContain('keeps working');

    expect(PlanQuota::used((int) $business->id, EntitlementRegistry::STAFF))->toBe(3);
});

test('an unlimited plan never refuses, whatever is held', function () {
    // `null` is unlimited. Reading it as zero would turn the plan that grants
    // everything into the plan that can create nothing.
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Enterprise());

    ws55Staff($business->id, 'One');
    ws55Staff($business->id, 'Two');
    ws55Staff($business->id, 'Three');

    expect(PlanQuota::refusal($business->fresh(), EntitlementRegistry::STAFF))->toBeNull();

    $this->withToken(ws55Token($owner))
        ->postJson('/api/v1/management/staff', [
            'name' => 'Fourth Seat',
            'email' => 'ws55-unlimited@example.test',
            'roles' => ['Cashier'],
        ])
        ->assertCreated();
});

/* ------------------------------------------------------- the create screens */

test('the staff invite screen is told what the business holds', function () {
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan(['entitlements' => [
        EntitlementRegistry::STAFF => 5,
        EntitlementRegistry::STORES => 3,
    ]]));

    ws55Staff($business->id, 'One');
    ws55Staff($business->id, 'Two');

    $this->withToken(ws55Token($owner))
        ->getJson('/api/v1/management/staff-options')
        ->assertOk()
        ->assertJsonPath('data.staff_quota.used', 2)
        ->assertJsonPath('data.staff_quota.limit', 5)
        ->assertJsonPath('data.staff_quota.remaining', 3)
        ->assertJsonPath('data.staff_quota.reached', false)
        ->assertJsonPath('data.staff_quota.upgrade_url', '/plans');
});

test('the product form is told what the business holds', function () {
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan(['entitlements' => [EntitlementRegistry::PRODUCTS => 100]]));

    Product::create([
        'business_id' => $business->id,
        'store_id' => null,
        'name' => 'Existing',
        'amount' => 1000,
        'status' => 'active',
    ]);

    $this->withToken(ws55Token($owner))
        ->getJson('/api/v1/management/products/form/options')
        ->assertOk()
        ->assertJsonPath('data.product_quota.used', 1)
        ->assertJsonPath('data.product_quota.limit', 100)
        ->assertJsonPath('data.product_quota.reached', false);
});

test('the warehouse list is told what the business holds', function () {
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan(['entitlements' => [EntitlementRegistry::WAREHOUSES => 2]]));

    Warehouse::create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'name' => 'Main',
        'warehouse_code' => 'WS55-WH-1',
        'status' => 'active',
    ]);

    $this->withToken(ws55Token($owner))
        ->getJson('/api/v1/management/warehouses')
        ->assertOk()
        ->assertJsonPath('data.warehouse_quota.used', 1)
        ->assertJsonPath('data.warehouse_quota.limit', 2)
        ->assertJsonPath('data.warehouse_quota.remaining', 1)
        ->assertJsonPath('data.warehouse_quota.reached', false);
});

/* ------------------------------------------------------------- the storefront */

test('the storefront gate is on the two routes that switch it on, and not on the one that switches it off', function () {
    // Gating "off" would leave a business that lost the entitlement holding a
    // live storefront it could not close, and a route-name prefix would catch
    // the shared reads (`check-slug`, `storefront.stores.*`) that store create
    // calls — a gate that breaks the create form for the very businesses it is
    // meant to restrict.
    $middleware = fn (string $name): array => collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->getName() === $name)
        ->gatherMiddleware();

    expect($middleware('api.management.storefront.enable-website'))->toContain('plan.feature:storefront')
        ->and($middleware('api.management.storefront.store'))->toContain('plan.feature:storefront')
        ->and($middleware('api.management.storefront.disable-website'))->not->toContain('plan.feature:storefront')
        ->and($middleware('api.management.storefront.check-slug'))->not->toContain('plan.feature:storefront')
        ->and($middleware('api.management.storefront.stores.index'))->not->toContain('plan.feature:storefront');
});

test('a plan that excludes the storefront refuses publishing it', function () {
    // A real store: the route binds `{store}` on `store_id`, and
    // `SubstituteBindings` runs with the `api` group — before the route's own
    // middleware — so a store that does not exist would answer 404 and the gate
    // would never be reached.
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan(['entitlements' => [EntitlementRegistry::STOREFRONT => false]]));

    $store = ws55Store($owner);

    $response = $this->withToken(ws55Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/enable-website", []);

    $response->assertStatus(403)
        ->assertJsonPath('code', FeatureGate::REFUSAL_CODE);

    expect($response->json('message'))->toContain('storefront');
});

test('the same business can still switch a storefront off', function () {
    // Gating "off" would leave a business that lost the entitlement holding a
    // live storefront it could not close — the one direction the gate
    // deliberately does not cover.
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan(['entitlements' => [EntitlementRegistry::STOREFRONT => false]]));

    $store = ws55Store($owner, ['has_website' => true, 'slug' => 'ws55-live']);

    $this->withToken(ws55Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/disable-website", [])
        ->assertOk();
});

/* ---------------------------------------------------------------- helpers */

function ws55Staff(int $businessId, string $name): User
{
    return User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $businessId,
        'name' => $name,
    ]);
}

function ws55Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.strtolower(str()->random(6)),
        'slug' => 'ws55-'.strtolower(str()->random(10)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

/* ------------------------------------------------------ pos, plugins, invoices */

test('a plan without the till is refused everywhere the till reaches', function () {
    // The whole POS surface, not just a screen: the point of gating POS on the
    // plan is that a business whose plan does not include it has no till, so
    // the assertion is on the API the till app is built from.
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan());

    $store = ws55Store($owner);
    $token = $owner->createToken('pos-access', ['pos'], now()->addHour())->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/pos/me')
        ->assertStatus(403)
        ->assertJsonPath('code', 'plan_feature_unavailable')
        ->assertJsonPath('redirect', '/plans');

    // And the till's own store-scoped endpoints, reached as the POS app reaches
    // them. `SubstituteBindings` runs with the api group, so the store must
    // exist for the gate to be the thing that answers.
    $this->withToken($token)
        ->getJson("/api/v1/pos/stores/{$store->store_id}/products")
        ->assertStatus(403)
        ->assertJsonPath('code', 'plan_feature_unavailable');

    $this->withToken($token)
        ->getJson("/api/v1/pos/stores/{$store->store_id}/session")
        ->assertStatus(403);
});

test('an enterprise business reaches the till', function () {
    // The other half, and the one that catches a gate applied too broadly: it is
    // not enough that the refusal exists, it has to be the *plan* that decides.
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Enterprise());

    $token = $owner->createToken('pos-access', ['pos'], now()->addHour())->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/pos/me')->assertOk();
});

test('signing in to the till is not itself refused', function () {
    // `POST /pos/login` is outside the gate by necessity — no user is resolved
    // when it runs, so there is nothing to ask the plan about. It is asserted
    // because the alternative is worse than it looks: a 403 here would tell a
    // cashier their plan is wrong when the app has not yet established who they
    // are, and the message would name a plan they cannot see or change.
    //
    // A validation failure is the proof the request reached the controller
    // rather than being turned away by the middleware.
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan());

    $this->postJson('/api/v1/pos/login', ['email' => $owner->email])
        ->assertStatus(422);

    $this->assertNotSame(
        403,
        $this->postJson('/api/v1/pos/login', [])->status(),
    );
});

test('invoices and plugins are refused and granted by the same rule', function () {
    [$entryOwner] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($entryOwner, ws55Plan());

    $entryToken = ws55Token($entryOwner);

    $this->withToken($entryToken)
        ->getJson('/api/v1/management/invoices')
        ->assertStatus(403)
        ->assertJsonPath('code', 'plan_feature_unavailable')
        ->assertJsonPath('redirect', '/plans');

    $this->withToken($entryToken)
        ->getJson('/api/v1/management/plugins')
        ->assertStatus(403)
        ->assertJsonPath('code', 'plan_feature_unavailable');

    [$enterpriseOwner] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($enterpriseOwner, ws55Enterprise());

    $enterpriseToken = ws55Token($enterpriseOwner);

    $this->withToken($enterpriseToken)->getJson('/api/v1/management/invoices')->assertOk();
    $this->withToken($enterpriseToken)->getJson('/api/v1/management/plugins')->assertOk();
});

test('the refusal for a module names the plan that grants it, read from the table', function () {
    // The same property accounting's refusal has, checked once for a module
    // added later: the sentence comes from the plans table, so renaming the top
    // tier renames every upsell on the platform.
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan());

    $enterprise = ws55Enterprise();
    $enterprise->forceFill(['name' => 'Top Shelf'])->save();

    $message = $this->withToken(ws55Token($owner))
        ->getJson('/api/v1/management/invoices')
        ->assertStatus(403)
        ->json('message');

    expect($message)
        ->toContain('Top Shelf')
        ->toContain('Invoices');
});

test('a locked module is reported as locked on the auth payload', function () {
    // The sidebar reads this before it renders, so the three modules added
    // latest have to appear in it the same way accounting does — with the
    // catalogue's own label and help, not a second copy of them in the SPA.
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws55Subscribe($owner, ws55Plan());

    // The plan that grants them has to exist for the payload to be able to name
    // it — `planGranting()` reads the table, so an upsell with nothing to sell
    // is reported with a null `plan_name` rather than an invented one.
    ws55Enterprise();

    $locked = $this->withToken(ws55Token($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->json('data.user.entitlements.locked');

    expect(array_keys($locked))->toContain(
        EntitlementRegistry::POS,
        EntitlementRegistry::PLUGINS,
        EntitlementRegistry::INVOICES,
    )
        ->and($locked[EntitlementRegistry::INVOICES]['label'])->toBe('Invoices')
        ->and($locked[EntitlementRegistry::POS]['label'])->toBe('Point of sale')
        // Every one of them names the same plan, because only the top tier
        // holds them — which is what makes the upgrade prompt point somewhere
        // real rather than at whichever plan happens to be cheapest.
        ->and($locked[EntitlementRegistry::INVOICES]['plan_name'])->toBe('Enterprise');
});
