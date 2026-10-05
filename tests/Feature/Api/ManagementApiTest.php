<?php

use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;

function managementToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function managementContext(): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Main Store',
        'slug' => 'main-store',
        'status' => Store::STATUS_ACTIVE,
    ]);

    return [$owner, $business, $store];
}

test('the management dashboard returns business metrics', function () {
    [$owner, $business, $store] = managementContext();

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Widget',
        'amount' => 1000,
        'quantity' => 3,
        'status' => 'active',
    ]);

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'source' => 'pos',
        'order_number' => 'ORD-DASH-1',
        'subtotal' => 1000,
        'total' => 1000,
        'amount_paid' => 1000,
        'status' => 'completed',
    ]);

    Transaction::create([
        'reference' => 'TXN-DASH-1',
        'order_id' => $order->id,
        'business_id' => $business->id,
        'amount' => 1000,
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/management/dashboard', [
        'Authorization' => 'Bearer '.managementToken($owner),
    ])->assertOk()
        ->assertJsonPath('data.stats.revenue.total', 1000)
        ->assertJsonPath('data.stats.orders.total', 1)
        ->assertJsonPath('data.stats.products.total', 1)
        ->assertJsonPath('data.stats.products.low_stock', 1)
        ->assertJsonPath('data.stats.stores.total', 1);

    expect($response->json('data.revenue_series'))->toBeArray()
        ->and($response->json('data.recent_orders.0.order_number'))->toBe('ORD-DASH-1');
});

test('stores can be listed and shown', function () {
    [$owner, $business, $store] = managementContext();

    $token = managementToken($owner);

    $this->getJson('/api/v1/management/stores', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Main Store')
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/v1/management/stores/'.$store->store_id, ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.store.slug', 'main-store');
});

test('products can be filtered and shown', function () {
    [$owner, $business, $store] = managementContext();

    Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Blue Widget',
        'amount' => 1500,
        'quantity' => 10,
        'status' => 'active',
    ]);

    Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Red Widget',
        'amount' => 2500,
        'quantity' => 5,
        'status' => 'inactive',
    ]);

    $token = managementToken($owner);

    $this->getJson('/api/v1/management/products?q=Blue', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Blue Widget');

    $this->getJson('/api/v1/management/products?status=inactive', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Red Widget');
});

test('a store associate cannot list stores', function () {
    [$owner, $business, $store] = managementContext();

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    (new SpatiePermissionSeeder)->run();
    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');

    $this->getJson('/api/v1/management/stores', [
        'Authorization' => 'Bearer '.managementToken($staff),
    ])->assertStatus(403);
});

test('management endpoints require authentication', function () {
    $this->getJson('/api/v1/management/dashboard')->assertUnauthorized();
    $this->getJson('/api/v1/management/stores')->assertUnauthorized();
});

test('global search returns products orders and customers', function () {
    [$owner, $business, $store] = managementContext();

    Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Special Kettle',
        'amount' => 5000,
        'quantity' => 4,
        'status' => 'active',
    ]);

    $this->getJson('/api/v1/management/search?q=Special', [
        'Authorization' => 'Bearer '.managementToken($owner),
    ])->assertOk()
        ->assertJsonPath('data.products.0.name', 'Special Kettle');
});
