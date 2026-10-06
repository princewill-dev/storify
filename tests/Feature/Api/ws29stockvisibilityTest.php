<?php

use App\Enums\TransferStatus;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockLedgerService;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-29 — stock visibility & the low-stock model
|--------------------------------------------------------------------------
| Covers the reconciled low-stock definition (min level when set, the legacy
| default threshold of 10 otherwise, zero always "out"), the missing min-level
| writes, the movement-history read, and the tenant/assignment boundaries.
*/

function ws29Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws29Warehouse(User $owner, array $attributes = []): Warehouse
{
    return Warehouse::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Warehouse '.Str::upper(Str::random(5)),
        'status' => Warehouse::STATUS_ACTIVE,
    ], $attributes));
}

function ws29Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.Str::upper(Str::random(5)),
        'slug' => 'ws29-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws29Product(Store $store, array $attributes = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'Widget '.Str::upper(Str::random(5)),
        'amount' => 1000,
        'quantity' => 10,
        'status' => 'active',
    ], $attributes));
}

function ws29Stock(Warehouse|Store $host, Product $product, int $quantity, int $minQuantity = 0): StockLocation
{
    return StockLocation::create([
        'business_id' => $host->business_id,
        'product_id' => $product->id,
        'locationable_type' => $host::class,
        'locationable_id' => $host->getKey(),
        'quantity' => $quantity,
        'min_quantity' => $minQuantity,
    ]);
}

function ws29LocationAt(Warehouse|Store $host, Product $product): StockLocation
{
    return StockLocation::query()
        ->where('locationable_type', $host::class)
        ->where('locationable_id', $host->getKey())
        ->where('product_id', $product->id)
        ->firstOrFail();
}

/**
 * The shared fixture: one warehouse, one store, four products covering low by
 * min level, low by the default threshold, out of stock, and a product with no
 * stock-location row at all (legacy product-level stock).
 *
 * @return array{0: User, 1: Warehouse, 2: Store, 3: Product, 4: Product, 5: Product, 6: Product}
 */
function ws29Context(): array
{
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $warehouse = ws29Warehouse($owner);
    $store = ws29Store($owner);

    $rice = ws29Product($store, ['name' => 'Rice', 'amount' => 1000, 'quantity' => 3]);
    $beans = ws29Product($store, ['name' => 'Beans', 'amount' => 500, 'quantity' => 50]);
    $oil = ws29Product($store, ['name' => 'Oil', 'amount' => 100, 'quantity' => 1]);
    $salt = ws29Product($store, ['name' => 'Salt', 'amount' => 200, 'quantity' => 2]);

    ws29Stock($warehouse, $rice, 3, 5);   // low: breaches its own min level
    ws29Stock($store, $rice, 8);          // low: default threshold
    ws29Stock($warehouse, $beans, 50);    // healthy
    ws29Stock($store, $oil, 0, 10);       // out of stock
    // Salt deliberately has no stock-location row.

    return [$owner, $warehouse, $store, $rice, $beans, $oil, $salt];
}

test('stock visibility requires authentication', function () {
    $this->getJson('/api/v1/management/stock/low-stock')->assertStatus(401);
    $this->getJson('/api/v1/management/stock/summary')->assertStatus(401);
    $this->getJson('/api/v1/management/stock-movements')->assertStatus(401);
});

test('the stock summary reconciles value, units and one low-stock definition', function () {
    [$owner, , $store] = ws29Context();

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/summary')
        ->assertOk()
        ->assertJsonPath('data.summary.definition.threshold', 10)
        ->assertJsonPath('data.summary.stock.total_units', 61)
        // 3x ₦1000 + 8x ₦1000 + 50x ₦500 + 0x ₦100, in integer kobo.
        ->assertJsonPath('data.summary.stock.value_kobo', 3600000)
        ->assertJsonPath('data.summary.stock.value', 36000)
        ->assertJsonPath('data.summary.stock.stocked_products', 3)
        ->assertJsonPath('data.summary.low_stock.locations', 2)
        ->assertJsonPath('data.summary.low_stock.out_of_stock_locations', 1)
        // Product-level signal: Rice (3), Oil (1) and Salt (2) are all low.
        ->assertJsonPath('data.summary.low_stock.product_count', 3)
        ->assertJsonPath('data.summary.low_stock.out_of_stock_products', 0)
        ->assertJsonPath('data.summary.warehouses.count', 1)
        ->assertJsonPath('data.summary.warehouses.units', 53)
        ->assertJsonPath('data.summary.warehouses.rows.0.low_stock_count', 1)
        ->assertJsonPath('data.summary.stores.count', 1)
        ->assertJsonPath('data.summary.stores.units', 8)
        ->assertJsonPath('data.summary.stores.low_stock', 1)
        ->assertJsonPath('data.summary.stores.out_of_stock', 1)
        ->assertJsonPath('data.summary.transfer_requests.pending', 0)
        ->assertJsonPath('data.summary.transfer_requests.approved', 0)
        // The legacy dashboard card: lowest stock first, capped at the limit.
        ->assertJsonPath('data.summary.low_stock.products.0.name', 'Oil')
        ->assertJsonPath('data.summary.low_stock.products.0.quantity', 1)
        ->assertJsonPath('data.summary.low_stock.products.0.store.name', $store->name);
});

test('the low-stock list merges location rows and product fallbacks', function () {
    [$owner] = ws29Context();

    $response = $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/low-stock?per_page=10');

    $response->assertOk()
        ->assertJsonPath('data.counts.attention', 4)
        ->assertJsonPath('data.counts.low_stock', 3)
        ->assertJsonPath('data.counts.out_of_stock', 1)
        ->assertJsonPath('data.counts.location_rows', 3)
        ->assertJsonPath('data.counts.product_rows', 1)
        ->assertJsonPath('meta.total', 4)
        // Out rows sort first, then the lowest quantity.
        ->assertJsonPath('data.low_stock.0.product.name', 'Oil')
        ->assertJsonPath('data.low_stock.0.state', 'out_of_stock')
        ->assertJsonPath('data.low_stock.0.min_quantity', 10)
        ->assertJsonPath('data.low_stock.0.location.type', 'store')
        ->assertJsonPath('data.low_stock.1.product.name', 'Salt')
        ->assertJsonPath('data.low_stock.1.source', 'product')
        ->assertJsonPath('data.low_stock.1.stock_location_id', null)
        ->assertJsonPath('data.low_stock.1.threshold', 10)
        ->assertJsonPath('data.low_stock.2.product.name', 'Rice')
        ->assertJsonPath('data.low_stock.2.location.type', 'warehouse')
        ->assertJsonPath('data.low_stock.2.min_quantity', 5)
        ->assertJsonPath('data.low_stock.2.threshold', 5);

    $sources = array_column($response->json('data.low_stock'), 'source');
    expect($sources)->toContain('product', 'location');
});

test('the low-stock list filters by state and by location', function () {
    [$owner, $warehouse, $store] = ws29Context();

    // Selecting a tab filters the rows, not the counts — the tabs stay stable.
    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/low-stock?state=out_of_stock')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.counts.attention', 4)
        ->assertJsonPath('data.counts.low_stock', 3)
        ->assertJsonPath('data.low_stock.0.product.name', 'Oil');

    $warehouseRows = $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/low-stock?warehouse_id='.$warehouse->id.'&per_page=50')
        ->assertOk();

    expect($warehouseRows->json('data.low_stock'))->toHaveCount(1)
        ->and($warehouseRows->json('data.low_stock.0.product.name'))->toBe('Rice')
        ->and($warehouseRows->json('data.low_stock.0.location.type'))->toBe('warehouse');

    $storeRows = $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/low-stock?store_id='.$store->id.'&per_page=50')
        ->assertOk();

    expect($storeRows->json('data.counts.attention'))->toBe(3)
        ->and(array_column($storeRows->json('data.low_stock'), 'source'))->toContain('product');

    // The product fallback can be switched off for callers that only want
    // physical stock-location rows.
    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/low-stock?include_product_fallback=0')
        ->assertOk()
        ->assertJsonPath('data.counts.attention', 3)
        ->assertJsonPath('data.counts.product_rows', 0);
});

test('a min level can be set and immediately changes the low-stock verdict', function () {
    [$owner, $warehouse, $store] = ws29Context();

    // 15 units with no min level is healthy under the default threshold of 10.
    $product = ws29Product($store, ['name' => 'Cocoa', 'quantity' => 15]);
    $location = ws29Stock($warehouse, $product, 15);

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/low-stock?per_page=50')
        ->assertOk()
        ->assertJsonMissing(['stock_location_id' => $location->id]);

    $this->withToken(ws29Token($owner))
        ->patchJson("/api/v1/management/stock-locations/{$location->id}", ['min_quantity' => 20])
        ->assertOk()
        ->assertJsonPath('data.stock_level.stock_location_id', $location->id)
        ->assertJsonPath('data.stock_level.min_quantity', 20)
        ->assertJsonPath('data.stock_level.threshold', 20)
        ->assertJsonPath('data.stock_level.state', 'low_stock');

    expect($location->fresh()->min_quantity)->toBe(20);

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/low-stock?per_page=50')
        ->assertOk()
        ->assertJsonFragment(['stock_location_id' => $location->id, 'state' => 'low_stock']);

    // Lowering it again clears the verdict.
    $this->withToken(ws29Token($owner))
        ->patchJson("/api/v1/management/stock-locations/{$location->id}", ['min_quantity' => 0])
        ->assertOk()
        ->assertJsonPath('data.stock_level.state', 'in_stock');
});

test('minimum levels save in bulk, all or nothing', function () {
    [$owner, $warehouse] = ws29Context();
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $mine = ws29Stock($warehouse, ws29Product(ws29Store($owner), ['quantity' => 2]), 4);
    $otherStore = ws29Store($otherOwner);
    $foreign = ws29Stock(ws29Warehouse($otherOwner), ws29Product($otherStore, ['quantity' => 2]), 4);

    $this->withToken(ws29Token($owner))
        ->putJson('/api/v1/management/stock-levels/min-levels', [
            'items' => [
                ['id' => $mine->id, 'min_quantity' => 12],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.updated', 1)
        ->assertJsonPath('data.stock_levels.0.min_quantity', 12);

    expect($mine->fresh()->min_quantity)->toBe(12);

    // One foreign id poisons the whole save — nothing is half-applied.
    $this->withToken(ws29Token($owner))
        ->putJson('/api/v1/management/stock-levels/min-levels', [
            'items' => [
                ['id' => $mine->id, 'min_quantity' => 99],
                ['id' => $foreign->id, 'min_quantity' => 5],
            ],
        ])
        ->assertStatus(403);

    expect($mine->fresh()->min_quantity)->toBe(12)
        ->and($foreign->fresh()->min_quantity)->toBe(0);
});

test('the stock-level editor lists every location with its threshold', function () {
    [$owner] = ws29Context();

    $response = $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-levels?per_page=50');

    $response->assertOk()
        // Counts describe the whole filtered set, not just the state tab.
        ->assertJsonPath('data.counts.total', 4)
        ->assertJsonPath('data.counts.low_stock', 2)
        ->assertJsonPath('data.counts.out_of_stock', 1)
        ->assertJsonPath('data.stock_levels.0.product.name', 'Oil')
        ->assertJsonPath('data.stock_levels.0.state', 'out_of_stock')
        ->assertJsonPath('data.stock_levels.1.product.name', 'Rice')
        ->assertJsonPath('data.stock_levels.1.threshold', 5)
        ->assertJsonStructure([
            'data' => ['definition', 'counts', 'stock_levels', 'filters' => ['states', 'warehouses', 'stores', 'locations']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-levels?state=out_of_stock')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.stock_levels.0.product.name', 'Oil');

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-levels?state=low_stock')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

test('the movement history shows signed quantities, balances and the actor', function () {
    [$owner, $warehouse, , , $beans] = ws29Context();

    $location = ws29LocationAt($warehouse, $beans);

    $ledger = app(StockLedgerService::class);
    $ledger->recordAddition($location, 5, $beans, $owner);
    $ledger->recordRemoval($location, 2, $beans, $owner);

    $response = $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-movements?warehouse_id='.$warehouse->id);

    $response->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.movements.0.type', 'removed')
        ->assertJsonPath('data.movements.0.type_label', 'Stock Removed')
        ->assertJsonPath('data.movements.0.direction', 'out')
        ->assertJsonPath('data.movements.0.signed_quantity', -2)
        ->assertJsonPath('data.movements.0.balance_before', 55)
        ->assertJsonPath('data.movements.0.balance_after', 53)
        ->assertJsonPath('data.movements.0.location.type', 'warehouse')
        ->assertJsonPath('data.movements.0.product.name', 'Beans')
        ->assertJsonPath('data.movements.0.performed_by.name', $owner->name)
        ->assertJsonPath('data.movements.1.type', 'added')
        ->assertJsonPath('data.movements.1.direction', 'in')
        ->assertJsonPath('data.movements.1.signed_quantity', 5);

    expect($response->json('data.movements.0.movement_code'))->toStartWith('stm_');

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-movements?type=added')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-movements?from='.now()->toDateString().'&to='.now()->toDateString())
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-movements?q=Beans')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

test('transfer movements are signed per side and carry their counterpart', function () {
    [$owner, $warehouse, $store, , $beans] = ws29Context();

    $source = ws29LocationAt($warehouse, $beans);
    $destination = ws29Stock($store, $beans, 0);

    $transfer = StockTransfer::create([
        'business_id' => $owner->business_id,
        'from_location_type' => Warehouse::class,
        'from_location_id' => $warehouse->id,
        'to_location_type' => Store::class,
        'to_location_id' => $store->id,
        'status' => TransferStatus::PENDING->value,
        'requested_by' => $owner->id,
    ]);

    app(StockLedgerService::class)->recordTransfer($source, $destination, 4, $transfer, $owner);

    $out = $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-movements?type=transferred&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.movements.0.direction', 'out')
        ->assertJsonPath('data.movements.0.signed_quantity', -4)
        ->assertJsonPath('data.movements.0.counterpart.type', 'store')
        ->assertJsonPath('data.movements.0.reference.type', 'stock_transfer')
        ->assertJsonPath('data.movements.0.reference.code', $transfer->transfer_code);

    expect($out->json('data.movements.0.reference.label'))->toBe($transfer->transfer_code);

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-movements?type=transferred&store_id='.$store->id)
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.movements.0.direction', 'in')
        ->assertJsonPath('data.movements.0.signed_quantity', 4)
        ->assertJsonPath('data.movements.0.counterpart.type', 'warehouse');
});

test('stock reads and writes never cross the business boundary', function () {
    [$owner] = ws29Context();
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $otherStore = ws29Store($otherOwner);
    $otherWarehouse = ws29Warehouse($otherOwner);
    $otherProduct = ws29Product($otherStore, ['name' => 'Theirs', 'quantity' => 2]);
    $otherLocation = ws29Stock($otherWarehouse, $otherProduct, 1);

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/low-stock?warehouse_id='.$otherWarehouse->id)
        ->assertStatus(403);

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-movements?warehouse_id='.$otherWarehouse->id)
        ->assertStatus(403);

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/low-stock?product_id='.$otherProduct->id)
        ->assertStatus(403);

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-levels?product_id='.$otherProduct->id)
        ->assertStatus(403);

    $this->withToken(ws29Token($owner))
        ->patchJson("/api/v1/management/stock-locations/{$otherLocation->id}", ['min_quantity' => 5])
        ->assertStatus(403);

    expect($otherLocation->fresh()->min_quantity)->toBe(0);

    $response = $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/low-stock?per_page=50')
        ->assertOk();

    expect(collect($response->json('data.low_stock'))->pluck('product.name'))->not->toContain('Theirs');
});

test('deleted warehouses drop out of every stock read', function () {
    [$owner, $warehouse] = ws29Context();

    $gone = ws29Warehouse($owner, ['status' => Warehouse::STATUS_DELETED]);
    ws29Stock($gone, ws29Product(ws29Store($owner), ['name' => 'Ghost item', 'quantity' => 2]), 3);

    $summary = $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/summary')
        ->assertOk();

    expect($summary->json('data.summary.warehouses.count'))->toBe(1);

    $levels = $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-levels?per_page=50')
        ->assertOk();

    expect(collect($levels->json('data.stock_levels'))->pluck('warehouse.name'))->not->toContain($gone->name);
});

test('the ledger is warehouse-gated while the low list stays catalogue-visible', function () {
    [$owner, , $store, $rice] = ws29Context();

    $cashier = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $owner->business_id,
    ]);

    setPermissionsTeamId($owner->business_id);
    $cashier->assignRole('Cashier');
    $cashier->assignedStores()->attach($store->id);

    // No "warehouses view": the per-location ledger and movement history are
    // closed, the product-facing low-stock list is not.
    $this->withToken(ws29Token($cashier))->getJson('/api/v1/management/stock-levels')->assertStatus(403);
    $this->withToken(ws29Token($cashier))->getJson('/api/v1/management/stock-movements')->assertStatus(403);

    $storeLocation = ws29LocationAt($store, $rice);

    $this->withToken(ws29Token($cashier))
        ->patchJson("/api/v1/management/stock-locations/{$storeLocation->id}", ['min_quantity' => 5])
        ->assertStatus(403);

    $response = $this->withToken(ws29Token($cashier))
        ->getJson('/api/v1/management/stock/low-stock?per_page=50')
        ->assertOk();

    $rows = $response->json('data.low_stock');

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        // The cashier is assigned to the store only — no warehouse rows leak.
        expect($row['warehouse'])->toBeNull()
            ->and($row['store']['id'] ?? null)->toBe($store->id);
    }

    $this->withToken(ws29Token($cashier))->getJson('/api/v1/management/stock/summary')->assertOk();
});

test('stock visibility endpoints validate their input', function () {
    [$owner, $warehouse, , $rice] = ws29Context();

    $location = ws29LocationAt($warehouse, $rice);

    $this->withToken(ws29Token($owner))
        ->patchJson("/api/v1/management/stock-locations/{$location->id}", ['min_quantity' => -1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('min_quantity');

    $this->withToken(ws29Token($owner))
        ->patchJson("/api/v1/management/stock-locations/{$location->id}", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('min_quantity');

    $this->withToken(ws29Token($owner))
        ->putJson('/api/v1/management/stock-levels/min-levels', ['items' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items');

    $this->withToken(ws29Token($owner))
        ->putJson('/api/v1/management/stock-levels/min-levels', [
            'items' => [['id' => $location->id, 'min_quantity' => 'plenty']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.min_quantity');

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-movements?type=nope')
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-movements?from=2026-02-02&to=2026-01-01')
        ->assertStatus(422);

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock-movements?from=not-a-date')
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/low-stock?state=mostly')
        ->assertStatus(422)
        ->assertJsonValidationErrors('state');

    $this->withToken(ws29Token($owner))
        ->getJson('/api/v1/management/stock/summary?product_limit=0')
        ->assertStatus(422)
        ->assertJsonValidationErrors('product_limit');
});
