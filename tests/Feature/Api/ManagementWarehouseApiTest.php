<?php

use App\Enums\WarehouseStatus;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;

function warehouseToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function makeWarehouse(User $owner, array $attributes = []): Warehouse
{
    return Warehouse::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Main Warehouse',
        'status' => Warehouse::STATUS_ACTIVE,
    ], $attributes));
}

/** A stock location needs a product, which needs a store. */
function stockAt(Warehouse $warehouse, int $quantity, int $minQuantity = 0): StockLocation
{
    $store = Store::create([
        'user_id' => $warehouse->user_id,
        'business_id' => $warehouse->business_id,
        'name' => 'Store for '.$warehouse->warehouse_code,
        'slug' => 'ws-'.$warehouse->warehouse_code,
        'status' => Store::STATUS_ACTIVE,
    ]);

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $warehouse->business_id,
        'name' => 'Widget for '.$warehouse->warehouse_code,
        'amount' => 1000,
        'quantity' => $quantity,
        'status' => 'active',
    ]);

    return StockLocation::create([
        'business_id' => $warehouse->business_id,
        'product_id' => $product->id,
        'locationable_type' => Warehouse::class,
        'locationable_id' => $warehouse->id,
        'quantity' => $quantity,
        'min_quantity' => $minQuantity,
    ]);
}

test('the warehouse list returns stock and low-stock counts', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = makeWarehouse($owner);

    stockAt($warehouse, 4, 10);

    $this->withToken(warehouseToken($owner))
        ->getJson('/api/v1/management/warehouses')
        ->assertOk()
        ->assertJsonPath('data.warehouses.0.name', 'Main Warehouse')
        ->assertJsonPath('data.warehouses.0.total_stock', 4)
        ->assertJsonPath('data.warehouses.0.product_count', 1)
        ->assertJsonPath('data.warehouses.0.low_stock_count', 1);
});

test('the warehouse list excludes other businesses and deleted warehouses', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    makeWarehouse($owner, ['name' => 'Mine']);
    makeWarehouse($owner, ['name' => 'Gone', 'status' => Warehouse::STATUS_DELETED]);
    makeWarehouse($otherOwner, ['name' => 'Theirs']);

    $response = $this->withToken(warehouseToken($owner))->getJson('/api/v1/management/warehouses');

    $response->assertOk();

    expect(array_column($response->json('data.warehouses'), 'name'))->toBe(['Mine']);
});

test('a warehouse can be created with several staff assigned', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = collect(['Ada', 'Bola', 'Chidi'])->map(fn ($name) => User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
        'name' => $name,
    ]));

    $response = $this->withToken(warehouseToken($owner))->postJson('/api/v1/management/warehouses', [
        'name' => 'Ikeja Depot',
        'city' => 'Lagos',
        'is_active' => true,
        'staff_ids' => $staff->pluck('id')->all(),
    ]);

    $response->assertCreated()->assertJsonPath('data.warehouse.name', 'Ikeja Depot');

    // The legacy multi-select kept only the last id it was handed.
    expect($response->json('data.warehouse.staff'))->toHaveCount(3);
});

test('a warehouse requires a name', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(warehouseToken($owner))
        ->postJson('/api/v1/management/warehouses', ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

test('warehouse detail reports stats and recent movements', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = makeWarehouse($owner);

    stockAt($warehouse, 25);

    $this->withToken(warehouseToken($owner))
        ->getJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}")
        ->assertOk()
        ->assertJsonPath('data.stats.total_stock', 25)
        ->assertJsonPath('data.stats.low_stock_count', 0)
        ->assertJsonPath('data.stats.product_count', 1)
        ->assertJsonStructure(['data' => ['warehouse', 'stats', 'recent_movements']]);
});

test('a warehouse belonging to another business is not reachable', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = makeWarehouse($otherOwner, ['name' => 'Theirs']);

    $this->withToken(warehouseToken($owner))
        ->getJson("/api/v1/management/warehouses/{$theirs->warehouse_code}")
        ->assertStatus(403);
});

test('a warehouse can be updated', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = makeWarehouse($owner);

    $this->withToken(warehouseToken($owner))
        ->putJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}", [
            'name' => 'Renamed Depot',
            'city' => 'Abuja',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.warehouse.name', 'Renamed Depot')
        ->assertJsonPath('data.warehouse.status', 'inactive');
});

test('deleting a warehouse soft-deletes it', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = makeWarehouse($owner);

    $this->withToken(warehouseToken($owner))
        ->deleteJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}")
        ->assertOk();

    expect($warehouse->fresh()->status)->toBe(WarehouseStatus::DELETED);
});

test('a warehouse holding stock cannot be deleted', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = makeWarehouse($owner);

    stockAt($warehouse, 3);

    $this->withToken(warehouseToken($owner))
        ->deleteJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}")
        ->assertStatus(422);

    expect($warehouse->fresh()->status)->toBe(WarehouseStatus::ACTIVE);
});

test('a physical product with no warehouse is filed into the business fallback', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = makeWarehouse($owner);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Main Store',
        'slug' => 'main-store',
        'status' => Store::STATUS_ACTIVE,
    ]);

    $payload = ['name' => 'Widget', 'store_id' => $store->id, 'amount' => 1000, 'quantity' => 5];

    // Nothing chosen: the business gets a fallback warehouse rather than a
    // 422, and the product goes there — not into the warehouse the owner
    // already had, which they did not pick.
    $filedInto = $this->withToken(warehouseToken($owner))
        ->postJson('/api/v1/management/products', $payload)
        ->assertCreated()
        ->json('data.product.warehouse_id');

    $fallback = Warehouse::where('business_id', $business->id)->where('is_default', true)->sole();

    expect($filedInto)->toBe($fallback->id)
        ->and($fallback->id)->not->toBe($warehouse->id)
        ->and($fallback->name)->toBe($business->name.' warehouse');

    // An explicit choice is still honoured, and does not disturb the fallback.
    $this->withToken(warehouseToken($owner))
        ->postJson('/api/v1/management/products', [...$payload, 'warehouse_id' => $warehouse->id])
        ->assertCreated()
        ->assertJsonPath('data.product.warehouse_id', $warehouse->id);

    expect(Warehouse::where('business_id', $business->id)->count())->toBe(2);
});

test('a digital product needs no warehouse', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Main Store',
        'slug' => 'main-store',
        'status' => Store::STATUS_ACTIVE,
    ]);

    $this->withToken(warehouseToken($owner))
        ->postJson('/api/v1/management/products', [
            'name' => 'E-book',
            'store_id' => $store->id,
            'amount' => 1000,
            'is_digital' => true,
        ])
        ->assertCreated();
});

test('a product cannot be assigned to another business warehouse', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = makeWarehouse($otherOwner, ['name' => 'Theirs']);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Main Store',
        'slug' => 'main-store',
        'status' => Store::STATUS_ACTIVE,
    ]);

    $this->withToken(warehouseToken($owner))
        ->postJson('/api/v1/management/products', [
            'name' => 'Widget',
            'store_id' => $store->id,
            'amount' => 1000,
            'quantity' => 5,
            'warehouse_id' => $theirs->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('warehouse_id');
});
