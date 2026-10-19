<?php

use App\Enums\TransferStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-59 — the inventory overview
|--------------------------------------------------------------------------
| Covers the one-read overview: parity with the WS-29 summary, the zero-filled
| movement window (and what is deliberately excluded from it), the value
| breakdowns, the transfer pipeline, tenant/assignment boundaries and the
| permission-shaped payload.
*/

function ws59Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws59Warehouse(User $owner, array $attributes = []): Warehouse
{
    return Warehouse::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Warehouse '.Str::upper(Str::random(5)),
        'status' => Warehouse::STATUS_ACTIVE,
    ], $attributes));
}

function ws59Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.Str::upper(Str::random(5)),
        'slug' => 'ws59-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws59Product(Store $store, array $attributes = []): Product
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

function ws59Stock(Warehouse|Store $host, Product $product, int $quantity, int $minQuantity = 0, ?ProductVariant $variant = null): StockLocation
{
    return StockLocation::create([
        'business_id' => $host->business_id,
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
        'locationable_type' => $host::class,
        'locationable_id' => $host->getKey(),
        'quantity' => $quantity,
        'min_quantity' => $minQuantity,
    ]);
}

function ws59LocationAt(Warehouse|Store $host, Product $product): StockLocation
{
    return StockLocation::query()
        ->where('locationable_type', $host::class)
        ->where('locationable_id', $host->getKey())
        ->where('product_id', $product->id)
        ->firstOrFail();
}

/**
 * One warehouse, one store, three physical products and the stock rows the
 * summary's documented fixture uses: rice is low in both locations, beans is
 * healthy, oil is out.
 *
 * @return array{0: User, 1: Warehouse, 2: Store, 3: Product, 4: Product, 5: Product}
 */
function ws59Context(): array
{
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $warehouse = ws59Warehouse($owner);
    $store = ws59Store($owner);

    $rice = ws59Product($store, ['name' => 'Rice', 'amount' => 1000, 'quantity' => 3]);
    $beans = ws59Product($store, ['name' => 'Beans', 'amount' => 500, 'quantity' => 50]);
    $oil = ws59Product($store, ['name' => 'Oil', 'amount' => 100, 'quantity' => 1]);

    ws59Stock($warehouse, $rice, 3, 5);
    ws59Stock($store, $rice, 8);
    ws59Stock($warehouse, $beans, 50);
    ws59Stock($store, $oil, 0, 10);

    return [$owner, $warehouse, $store, $rice, $beans, $oil];
}

function ws59MoveTo(StockMovement $movement, Carbon $at): StockMovement
{
    $movement->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

    return $movement;
}

test('the overview requires authentication', function () {
    $this->getJson('/api/v1/management/inventory/overview')->assertStatus(401);
});

test('the overview republishes the stock summary and partitions its health', function () {
    [$owner] = ws59Context();

    $summary = $this->withToken(ws59Token($owner))
        ->getJson('/api/v1/management/stock/summary')
        ->assertOk();

    $overview = $this->withToken(ws59Token($owner))
        ->getJson('/api/v1/management/inventory/overview')
        ->assertOk()
        ->assertJsonPath('data.overview.range.days', 30)
        ->assertJsonPath('data.overview.definition.threshold', 10)
        ->assertJsonPath('data.overview.stock.total_units', $summary->json('data.summary.stock.total_units'))
        ->assertJsonPath('data.overview.stock.value_kobo', $summary->json('data.summary.stock.value_kobo'))
        ->assertJsonPath('data.overview.stock.value', $summary->json('data.summary.stock.value'))
        ->assertJsonPath('data.overview.stock.stocked_products', $summary->json('data.summary.stock.stocked_products'))
        ->assertJsonPath('data.overview.stock.locations', 4)
        ->assertJsonPath('data.overview.stock.health.low_stock', 2)
        ->assertJsonPath('data.overview.stock.health.out_of_stock', 1)
        ->assertJsonPath('data.overview.stock.health.in_stock', 1)
        // Product-level signal: Rice (3) and Oil (1) are low; the location
        // rows tell the finer story (two low, one out).
        ->assertJsonPath('data.overview.low_stock.product_count', 2)
        ->assertJsonPath('data.overview.filters.warehouses.0.id', $summary->json('data.summary.warehouses.rows.0.id'));

    // The three health states are a partition of the same location count.
    $health = $overview->json('data.overview.stock.health');

    expect(array_sum($health))->toBe($overview->json('data.overview.stock.locations'));
});

test('the movement window is zero-filled and excludes transfers', function () {
    [$owner, $warehouse, $store, $rice, $beans, $oil] = ws59Context();

    $ledger = app(StockLedgerService::class);
    $warehouseBeans = ws59LocationAt($warehouse, $beans);

    // The ledger only moves one product between two of its own locations.
    ws59Stock($store, $beans, 0);

    // The ledger deduplicates on reference + location + action, so each call
    // needs its own reference model to produce its own row.
    ws59MoveTo($ledger->recordAddition($warehouseBeans, 5, $beans, $owner), Carbon::now()->setTime(10, 0));
    ws59MoveTo($ledger->recordRemoval($warehouseBeans, 2, $rice, $owner), Carbon::now()->setTime(11, 0));

    // A transfer writes two ledger rows, one on each side. Neither may count
    // as stock entering or leaving the business.
    $transfer = StockTransfer::create([
        'business_id' => $owner->business_id,
        'transfer_code' => 'trf_'.Str::lower(Str::random(8)),
        'from_location_type' => Warehouse::class,
        'from_location_id' => $warehouse->id,
        'to_location_type' => Store::class,
        'to_location_id' => $store->id,
        'requested_by' => $owner->id,
        'status' => TransferStatus::PENDING,
    ]);

    $ledger->recordTransfer(
        $warehouseBeans,
        ws59LocationAt($store, $beans),
        2,
        $transfer,
        $owner,
    );

    // One movement in the previous window, so the comparison has something to
    // compare against.
    ws59MoveTo(
        $ledger->recordAddition($warehouseBeans, 9, $oil, $owner),
        Carbon::now()->subDays(8)->setTime(12, 0),
    );

    $response = $this->withToken(ws59Token($owner))
        ->getJson('/api/v1/management/inventory/overview?days=7')
        ->assertOk();

    $series = $response->json('data.overview.movements.series');

    expect($series)->toHaveCount(7);

    $today = collect($series)->firstWhere('date', Carbon::now()->toDateString());

    expect($today['in'])->toBe(5)
        ->and($today['out'])->toBe(2)
        // The transfer rows are not in either series; only the current
        // window's two movements and the previous window's one are counted.
        ->and($response->json('data.overview.movements.totals.in'))->toBe(5)
        ->and($response->json('data.overview.movements.totals.out'))->toBe(2)
        ->and($response->json('data.overview.movements.totals.count'))->toBe(2)
        ->and($response->json('data.overview.movements.previous.in'))->toBe(9)
        ->and($response->json('data.overview.movements.previous.out'))->toBe(0)
        ->and($response->json('data.overview.movements.previous.count'))->toBe(1);
});

test('top products and categories reconcile with the stock value', function () {
    [$owner, $warehouse, $store, $rice, $beans] = ws59Context();

    $food = Category::create([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'Food',
        'slug' => 'food-'.Str::lower(Str::random(6)),
        'status' => 'active',
    ]);

    $rice->update(['category_id' => $food->id]);

    // A variant-priced product: its value must use the variant amount, not the
    // product amount.
    $coffee = ws59Product($store, ['name' => 'Coffee', 'amount' => 100, 'quantity' => 0, 'has_variants' => true]);
    $variant = ProductVariant::create([
        'product_id' => $coffee->id,
        'variant_code' => 'V-'.Str::upper(Str::random(6)),
        'quantity' => 4,
        'amount' => 2000,
    ]);
    ws59Stock($store, $coffee, 4, 0, $variant);

    // Digital stock is not inventory and must never rank here.
    $ebook = ws59Product($store, ['name' => 'Ebook', 'amount' => 99999, 'quantity' => 100, 'is_digital' => true]);
    ws59Stock($store, $ebook, 100);

    $response = $this->withToken(ws59Token($owner))
        ->getJson('/api/v1/management/inventory/overview')
        ->assertOk();

    $products = collect($response->json('data.overview.top_products'));

    expect($products->pluck('name'))->not->toContain('Ebook')
        ->and($products->firstWhere('name', 'Beans')['value_kobo'])->toBe(2500000)
        ->and($products->firstWhere('name', 'Rice')['value_kobo'])->toBe(1100000)
        ->and($products->firstWhere('name', 'Coffee')['value_kobo'])->toBe(800000)
        // Ordered by value, largest first.
        ->and($products->pluck('value_kobo')->all())->toBe(
            $products->pluck('value_kobo')->sortDesc()->values()->all(),
        );

    $categories = collect($response->json('data.overview.categories'));

    expect($categories->firstWhere('name', 'Uncategorised')['value_kobo'])->toBe(3300000)
        ->and($categories->firstWhere('name', 'Food')['value_kobo'])->toBe(1100000);
});

test('the transfer pipeline counts every status and treats awaiting acknowledgement as pending', function () {
    [$owner, $warehouse, $store] = ws59Context();

    foreach ([TransferStatus::PENDING, TransferStatus::AWAITING_ACKNOWLEDGMENT, TransferStatus::APPROVED, TransferStatus::RECEIVED] as $status) {
        StockTransfer::create([
            'business_id' => $owner->business_id,
            'transfer_code' => 'trf_'.Str::lower(Str::random(8)),
            'from_location_type' => Warehouse::class,
            'from_location_id' => $warehouse->id,
            'to_location_type' => Store::class,
            'to_location_id' => $store->id,
            'requested_by' => $owner->id,
            'status' => $status,
        ]);
    }

    $response = $this->withToken(ws59Token($owner))
        ->getJson('/api/v1/management/inventory/overview')
        ->assertOk()
        ->assertJsonPath('data.overview.transfers.pending', 2)
        ->assertJsonPath('data.overview.transfers.approved', 1)
        ->assertJsonPath('data.overview.transfers.open', 3);

    $byStatus = collect($response->json('data.overview.transfers.by_status'))
        ->pluck('count', 'value');

    expect($byStatus['awaiting_acknowledgment'])->toBe(1)
        ->and($byStatus['received'])->toBe(1)
        ->and($byStatus['cancelled'])->toBe(0);
});

test('store and warehouse filters narrow the scoped blocks', function () {
    [$owner, $warehouse, $store] = ws59Context();

    $this->withToken(ws59Token($owner))
        ->getJson('/api/v1/management/inventory/overview?warehouse_id='.$warehouse->id)
        ->assertOk()
        // Warehouse stock only: rice 3 + beans 50.
        ->assertJsonPath('data.overview.stock.total_units', 53)
        ->assertJsonPath('data.overview.warehouses.rows.0.id', $warehouse->id);

    $this->withToken(ws59Token($owner))
        ->getJson('/api/v1/management/inventory/overview?store_id='.$store->id)
        ->assertOk()
        // Store stock only: rice 8 + oil 0.
        ->assertJsonPath('data.overview.stock.total_units', 8)
        ->assertJsonPath('data.overview.warehouses.rows.0.units', 0);

    // A deleted warehouse disappears from both the scoped reads and the picker.
    $warehouse->update(['status' => Warehouse::STATUS_DELETED]);

    $response = $this->withToken(ws59Token($owner))
        ->getJson('/api/v1/management/inventory/overview')
        ->assertOk();

    expect(collect($response->json('data.overview.filters.warehouses'))->pluck('id'))->not->toContain($warehouse->id);
    expect(collect($response->json('data.overview.warehouses.rows'))->pluck('id'))->not->toContain($warehouse->id);
});

test('a foreign location filter is refused and foreign stock never leaks', function () {
    [$owner] = ws59Context();
    [$otherOwner, $otherWarehouse] = (function () {
        [$other] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
        $warehouse = ws59Warehouse($other, ['name' => 'Foreign']);

        return [$other, $warehouse];
    })();

    $this->withToken(ws59Token($owner))
        ->getJson('/api/v1/management/inventory/overview?warehouse_id='.$otherWarehouse->id)
        ->assertForbidden();

    $otherStore = ws59Store($otherOwner);
    $foreignProduct = ws59Product($otherStore, ['name' => 'Foreign Stock', 'amount' => 7777]);
    ws59Stock($otherStore, $foreignProduct, 99);

    $response = $this->withToken(ws59Token($owner))
        ->getJson('/api/v1/management/inventory/overview')
        ->assertOk();

    expect($response->json('data.overview.stock.total_units'))->toBe(61)
        ->and(collect($response->json('data.overview.top_products'))->pluck('name'))->not->toContain('Foreign Stock');
});

test('the payload is shaped by permission rather than zeroed', function () {
    [$owner, , $store] = ws59Context();

    $cashier = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $owner->business_id,
    ]);

    setPermissionsTeamId($owner->business_id);
    $cashier->assignRole('Cashier');
    $cashier->assignedStores()->attach($store->id);

    $this->withToken(ws59Token($cashier))
        ->getJson('/api/v1/management/inventory/overview')
        ->assertOk()
        ->assertJsonMissingPath('data.overview.movements')
        ->assertJsonMissingPath('data.overview.warehouses')
        ->assertJsonMissingPath('data.overview.transfers');

    // `warehouses view` opens the ledger and the per-warehouse breakdown.
    $cashier->givePermissionTo('warehouses view');

    $this->withToken(ws59Token($cashier))
        ->getJson('/api/v1/management/inventory/overview')
        ->assertOk()
        ->assertJsonPath('data.overview.movements.totals.in', 0)
        ->assertJsonMissingPath('data.overview.transfers');

    // `transfers view` adds only the pipeline.
    $cashier->givePermissionTo('transfers view');

    $this->withToken(ws59Token($cashier))
        ->getJson('/api/v1/management/inventory/overview')
        ->assertOk()
        ->assertJsonPath('data.overview.transfers.pending', 0)
        ->assertJsonStructure(['data' => ['overview' => ['transfers' => ['by_status']]]]);
});

test('the overview validates its window and ids', function () {
    [$owner] = ws59Context();

    $this->withToken(ws59Token($owner))
        ->getJson('/api/v1/management/inventory/overview?days=14')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('days');

    $this->withToken(ws59Token($owner))
        ->getJson('/api/v1/management/inventory/overview?store_id=not-an-id')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('store_id');
});
