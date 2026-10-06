<?php

use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-17 — Delivery routes (platform-wide, admin console)
|--------------------------------------------------------------------------
| Covers the platform route CRUD with the kobo↔NGN round-trip (including the
| kobo remainder the legacy edit form truncated), the enable/disable toggle,
| the usage-guarded delete, the state/area lookups feed, store-scope
| isolation, and the audience / platform-role / permission refusals.
*/

function ad17Token(User $user): string
{
    return $user->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad17AdminToken(User $admin): array
{
    return ['Authorization' => 'Bearer '.ad17Token($admin)];
}

function ad17SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad17PlatformAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    $user = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $user->assignRole('Platform Admin');

    return $user;
}

/**
 * A platform-wide route (store_id NULL), the only kind this screen owns.
 */
function ad17GlobalRoute(array $attributes = []): DeliveryRoute
{
    return DeliveryRoute::create(array_merge([
        'store_id' => null,
        'country' => 'Nigeria',
        'state' => 'Lagos',
        'area' => 'Lekki',
        'fee' => 500000, // ₦5,000 in kobo
        'delivery_days' => 3,
        'active' => true,
    ], $attributes));
}

function ad17Store(User $owner, array $attributes = []): Store
{
    static $sequence = 0;
    $sequence++;

    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'AD17 Store '.$sequence,
        'slug' => 'ad17-store-'.$sequence,
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ad17Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'source' => 'checkout',
        'order_number' => 'AD17-ORD-'.$sequence,
        'subtotal' => 1000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 1000,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

function ad17Customer(int $businessId, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $businessId,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ad17-'.Str::lower(Str::random(10)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
        'email_verified_at' => now(),
    ], $attributes));
}

/**
 * A valid create/update body; fee is in NGN as typed on the form.
 */
function ad17Payload(array $attributes = []): array
{
    return array_merge([
        'country' => 'Nigeria',
        'state' => 'Lagos',
        'area' => 'Lekki Phase 1',
        'fee' => 2500,
        'delivery_days' => 3,
    ], $attributes);
}

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------

test('the route list returns platform routes in legacy reading order', function () {
    $admin = ad17PlatformAdmin();

    ad17GlobalRoute(['state' => 'Lagos', 'area' => 'Lekki']);
    ad17GlobalRoute(['state' => 'Abuja', 'area' => 'Wuse']);
    ad17GlobalRoute(['state' => 'Lagos', 'area' => 'Ikeja']);

    $response = $this->getJson('/api/v1/admin/delivery-routes', ad17AdminToken($admin));

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'routes' => [['id', 'store_id', 'country', 'state', 'area', 'fee', 'fee_ngn', 'delivery_days', 'active', 'updated_at']],
                'summary' => ['total', 'active', 'inactive', 'states'],
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

    // Legacy ordered country → state → area.
    expect(array_column($response->json('data.routes'), 'area'))->toBe(['Wuse', 'Ikeja', 'Lekki'])
        ->and($response->json('meta.total'))->toBe(3);
});

test('the list excludes store-scoped routes and reports an unfiltered summary', function () {
    $admin = ad17PlatformAdmin();
    [$owner] = createBusinessOwner();
    $store = ad17Store($owner);

    ad17GlobalRoute(['state' => 'Lagos', 'area' => 'Lekki', 'active' => true]);
    ad17GlobalRoute(['state' => 'Abuja', 'area' => 'Wuse', 'active' => false]);

    $storeRoute = DeliveryRoute::create([
        'store_id' => $store->id,
        'country' => 'Nigeria',
        'state' => 'Rivers',
        'area' => 'Port Harcourt',
        'fee' => 550000,
        'delivery_days' => 4,
        'active' => true,
    ]);

    $response = $this->getJson('/api/v1/admin/delivery-routes', ad17AdminToken($admin));

    $response->assertOk();

    expect(array_column($response->json('data.routes'), 'id'))->not->toContain($storeRoute->id)
        ->and($response->json('data.summary'))->toBe(['total' => 2, 'active' => 1, 'inactive' => 1, 'states' => 2]);
});

test('search and status filters narrow the list but not the summary', function () {
    $admin = ad17PlatformAdmin();

    ad17GlobalRoute(['state' => 'Lagos', 'area' => 'Lekki', 'active' => true]);
    ad17GlobalRoute(['state' => 'Lagos', 'area' => 'Ikeja', 'active' => false]);
    ad17GlobalRoute(['state' => 'Abuja', 'area' => 'Wuse', 'active' => true]);

    $search = $this->getJson('/api/v1/admin/delivery-routes?q=Lekki', ad17AdminToken($admin));
    $search->assertOk();
    expect(array_column($search->json('data.routes'), 'area'))->toBe(['Lekki']);

    $inactive = $this->getJson('/api/v1/admin/delivery-routes?status=inactive', ad17AdminToken($admin));
    $inactive->assertOk();
    expect(array_column($inactive->json('data.routes'), 'area'))->toBe(['Ikeja'])
        ->and($inactive->json('data.summary.total'))->toBe(3);

    // An unknown status is rejected rather than silently ignored.
    $this->getJson('/api/v1/admin/delivery-routes?status=deleted', ad17AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    // An unknown sort column never reaches orderBy.
    $this->getJson('/api/v1/admin/delivery-routes?sort=store_id', ad17AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');
});

// ---------------------------------------------------------------------------
// Create / update — kobo round-trip
// ---------------------------------------------------------------------------

test('creating a route stores the fee in kobo', function () {
    $admin = ad17PlatformAdmin();

    $response = $this->postJson('/api/v1/admin/delivery-routes', ad17Payload(['fee' => 5000]), ad17AdminToken($admin));

    $response->assertCreated()
        ->assertJsonPath('message', 'Delivery route created.')
        ->assertJsonPath('data.route.fee', 500000)
        ->assertJsonPath('data.route.fee_ngn', '5000.00')
        ->assertJsonPath('data.route.delivery_days', 3)
        ->assertJsonPath('data.route.active', true)
        ->assertJsonPath('data.route.store_id', null);

    $route = DeliveryRoute::firstOrFail();

    expect($route->fee)->toBe(500000)
        ->and($route->store_id)->toBeNull()
        ->and($route->active)->toBeTrue();
});

test('a kobo remainder survives the create-edit round trip', function () {
    $admin = ad17PlatformAdmin();

    // Legacy's edit modal pre-filled (int) ($fee / 100) = 1234, so re-saving
    // wrote 123400 kobo and dropped the 57 kobo remainder.
    $created = $this->postJson('/api/v1/admin/delivery-routes', ad17Payload(['fee' => '1234.57']), ad17AdminToken($admin));

    $created->assertCreated()
        ->assertJsonPath('data.route.fee', 123457)
        ->assertJsonPath('data.route.fee_ngn', '1234.57');

    $route = DeliveryRoute::firstOrFail();

    // Editing with the exact NGN the form was handed must not truncate.
    $this->putJson("/api/v1/admin/delivery-routes/{$route->id}", ad17Payload([
        'fee' => $created->json('data.route.fee_ngn'),
    ]), ad17AdminToken($admin))->assertOk();

    expect($route->fresh()->fee)->toBe(123457);

    // Trailing-zero decimals round-trip exactly too.
    $this->postJson('/api/v1/admin/delivery-routes', ad17Payload(['area' => 'Yaba', 'fee' => '12.3']), ad17AdminToken($admin))
        ->assertCreated()
        ->assertJsonPath('data.route.fee', 1230)
        ->assertJsonPath('data.route.fee_ngn', '12.30');
});

test('route validation rejects missing and out-of-range fields', function () {
    $admin = ad17PlatformAdmin();

    $this->postJson('/api/v1/admin/delivery-routes', [], ad17AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['country', 'state', 'area', 'fee', 'delivery_days']);

    foreach ([0, 61] as $days) {
        $this->postJson('/api/v1/admin/delivery-routes', ad17Payload(['delivery_days' => $days]), ad17AdminToken($admin))
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_days');
    }

    $this->postJson('/api/v1/admin/delivery-routes', ad17Payload(['fee' => -1]), ad17AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('fee');

    // More precision than kobo allows is refused, not silently rounded.
    $this->postJson('/api/v1/admin/delivery-routes', ad17Payload(['fee' => '12.345']), ad17AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('fee');

    $this->postJson('/api/v1/admin/delivery-routes', ad17Payload(['fee' => 'free']), ad17AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('fee');

    $this->postJson('/api/v1/admin/delivery-routes', ad17Payload(['country' => str_repeat('a', 101)]), ad17AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('country');

    $this->postJson('/api/v1/admin/delivery-routes', ad17Payload(['area' => str_repeat('a', 151)]), ad17AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('area');

    expect(DeliveryRoute::count())->toBe(0);
});

test('a route can be updated', function () {
    $admin = ad17PlatformAdmin();
    $route = ad17GlobalRoute();

    $this->putJson("/api/v1/admin/delivery-routes/{$route->id}", ad17Payload([
        'state' => 'Oyo',
        'area' => 'Ibadan',
        'fee' => 4500,
        'delivery_days' => 4,
        'active' => false,
    ]), ad17AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('message', 'Delivery route updated.')
        ->assertJsonPath('data.route.state', 'Oyo')
        ->assertJsonPath('data.route.fee', 450000)
        ->assertJsonPath('data.route.delivery_days', 4)
        ->assertJsonPath('data.route.active', false);

    $fresh = $route->fresh();

    expect($fresh->state)->toBe('Oyo')
        ->and($fresh->fee)->toBe(450000)
        ->and($fresh->active)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Toggle / delete
// ---------------------------------------------------------------------------

test('toggling a route flips its active flag', function () {
    $admin = ad17PlatformAdmin();
    $route = ad17GlobalRoute(['active' => true]);

    $this->postJson("/api/v1/admin/delivery-routes/{$route->id}/toggle", [], ad17AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('message', 'Delivery route status updated.')
        ->assertJsonPath('data.route.active', false);

    $this->postJson("/api/v1/admin/delivery-routes/{$route->id}/toggle", [], ad17AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('data.route.active', true);

    expect($route->fresh()->active)->toBeTrue();
});

test('an unreferenced route can be deleted', function () {
    $admin = ad17PlatformAdmin();
    $route = ad17GlobalRoute();

    $this->deleteJson("/api/v1/admin/delivery-routes/{$route->id}", [], ad17AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('message', 'Delivery route deleted.');

    expect(DeliveryRoute::find($route->id))->toBeNull();
});

test('a route referenced by a historical order cannot be deleted', function () {
    $admin = ad17PlatformAdmin();
    [$owner] = createBusinessOwner();
    $store = ad17Store($owner);

    $route = ad17GlobalRoute();
    $order = ad17Order($store, ['delivery_route_id' => $route->id]);

    $response = $this->deleteJson("/api/v1/admin/delivery-routes/{$route->id}", [], ad17AdminToken($admin));

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('referenced')
        ->and(DeliveryRoute::find($route->id))->not->toBeNull();

    // Soft-deleted orders still hold the id, so they still block deletion.
    $order->delete();

    $this->deleteJson("/api/v1/admin/delivery-routes/{$route->id}", [], ad17AdminToken($admin))
        ->assertStatus(422);

    expect(DeliveryRoute::find($route->id))->not->toBeNull();
});

test('a route referenced by a saved address or delivery record cannot be deleted', function () {
    $admin = ad17PlatformAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad17Store($owner);

    $addressRoute = ad17GlobalRoute(['area' => 'Lekki Phase 1']);
    $customer = ad17Customer($business->id);

    DeliveryAddress::create([
        'customer_id' => $customer->id,
        'recipient_name' => $customer->full_name,
        'recipient_phone' => '08011112222',
        'street_address' => '12 Riverside',
        'city' => 'Lekki',
        'state' => 'Lagos',
        'country' => 'Nigeria',
        'delivery_route_id' => $addressRoute->id,
    ]);

    $this->deleteJson("/api/v1/admin/delivery-routes/{$addressRoute->id}", [], ad17AdminToken($admin))
        ->assertStatus(422);

    expect(DeliveryRoute::find($addressRoute->id))->not->toBeNull();

    $deliveryRoute = ad17GlobalRoute(['area' => 'Wuse']);
    $order = ad17Order($store);

    OrderDelivery::create([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'delivery_route_id' => $deliveryRoute->id,
    ]);

    $this->deleteJson("/api/v1/admin/delivery-routes/{$deliveryRoute->id}", [], ad17AdminToken($admin))
        ->assertStatus(422);

    expect(DeliveryRoute::find($deliveryRoute->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Scope + lookups
// ---------------------------------------------------------------------------

test('store-scoped routes are unreachable from the admin API', function () {
    $admin = ad17PlatformAdmin();
    [$owner] = createBusinessOwner();
    $store = ad17Store($owner);

    $storeRoute = DeliveryRoute::create([
        'store_id' => $store->id,
        'country' => 'Nigeria',
        'state' => 'Rivers',
        'area' => 'Port Harcourt',
        'fee' => 550000,
        'delivery_days' => 4,
        'active' => true,
    ]);

    $list = $this->getJson('/api/v1/admin/delivery-routes', ad17AdminToken($admin));
    $list->assertOk();
    expect(array_column($list->json('data.routes'), 'id'))->not->toContain($storeRoute->id);

    $this->putJson("/api/v1/admin/delivery-routes/{$storeRoute->id}", ad17Payload(), ad17AdminToken($admin))
        ->assertStatus(404);

    $this->postJson("/api/v1/admin/delivery-routes/{$storeRoute->id}/toggle", [], ad17AdminToken($admin))
        ->assertStatus(404);

    $this->deleteJson("/api/v1/admin/delivery-routes/{$storeRoute->id}", [], ad17AdminToken($admin))
        ->assertStatus(404);

    expect($storeRoute->fresh()->active)->toBeTrue();
});

test('the lookups endpoint returns the state and area picklists', function () {
    $admin = ad17PlatformAdmin();

    $response = $this->getJson('/api/v1/admin/delivery-routes/lookups', ad17AdminToken($admin));

    $response->assertOk()
        ->assertJsonStructure(['data' => ['countries', 'states', 'areas_by_state']]);

    expect($response->json('data.countries'))->toContain('Nigeria')
        ->and($response->json('data.states'))->toHaveCount(37)
        ->and($response->json('data.states'))->toContain('Lagos')
        ->and($response->json('data.areas_by_state.Lagos'))->toContain('Lekki');

    // The FCT is spelled with different dashes in the two lists; the area
    // suggestions must still reach it.
    $fct = collect($response->json('data.areas_by_state'))->first(fn ($areas, $state) => str_contains($state, 'FCT'));

    expect($fct)->toContain('Garki');
});

// ---------------------------------------------------------------------------
// Refusals
// ---------------------------------------------------------------------------

test('delivery routes refuse non-platform, unpermitted and guest callers', function () {
    $superadmin = ad17SuperAdmin();
    $route = ad17GlobalRoute();

    // A business-scoped "Super Admin" carries the admin.* permission names,
    // so an admin-audience token from a business account must still fail the
    // platform-role guard on every delivery-route route.
    [$owner, $business] = createBusinessOwner();
    setPermissionsTeamId($business->id);

    $ownerToken = ad17AdminToken($owner);

    $this->getJson('/api/v1/admin/delivery-routes', $ownerToken)->assertStatus(403);
    $this->postJson('/api/v1/admin/delivery-routes', ad17Payload(), $ownerToken)->assertStatus(403);
    $this->putJson("/api/v1/admin/delivery-routes/{$route->id}", ad17Payload(), $ownerToken)->assertStatus(403);
    $this->postJson("/api/v1/admin/delivery-routes/{$route->id}/toggle", [], $ownerToken)->assertStatus(403);
    $this->deleteJson("/api/v1/admin/delivery-routes/{$route->id}", [], $ownerToken)->assertStatus(403);

    // A management-audience token cannot reach the admin API at all.
    $managementToken = ['Authorization' => 'Bearer '.$owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken];

    $this->getJson('/api/v1/admin/delivery-routes', $managementToken)->assertStatus(403);

    // An admin account without the permission is stopped by the route gate.
    $plainAdmin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    $this->getJson('/api/v1/admin/delivery-routes', ad17AdminToken($plainAdmin))->assertStatus(403);
    $this->postJson('/api/v1/admin/delivery-routes', ad17Payload(), ad17AdminToken($plainAdmin))->assertStatus(403);

    // Guests are unauthenticated.
    $this->getJson('/api/v1/admin/delivery-routes')->assertStatus(401);

    // The seeded platform role and a superadmin pass.
    $platformAdmin = ad17PlatformAdmin();

    $this->getJson('/api/v1/admin/delivery-routes', ad17AdminToken($platformAdmin))->assertOk();
    $this->getJson('/api/v1/admin/delivery-routes', ad17AdminToken($superadmin))->assertOk();

    // The refusals never touched the route.
    expect($route->fresh()->active)->toBeTrue();
});
