<?php

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransferStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-28 — dashboard widgets & store switcher
|--------------------------------------------------------------------------
*/

function ws28Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws28Store(User $owner, $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS28 Store '.Str::upper(Str::random(4)),
        'slug' => 'ws28-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws28Warehouse(User $owner, array $attributes = []): Warehouse
{
    return Warehouse::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'WS28 Warehouse '.Str::upper(Str::random(4)),
        'status' => Warehouse::STATUS_ACTIVE,
    ], $attributes));
}

function ws28Product(Store $store, array $attributes = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'WS28 Product '.Str::upper(Str::random(4)),
        'amount' => 1000,
        'quantity' => 50,
        'status' => 'active',
    ], $attributes));
}

function ws28Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_number' => 'WS28-ORD-'.$sequence,
        'subtotal' => 2500,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 2500,
        'amount_paid' => 2500,
        'status' => OrderStatus::PENDING->value,
    ], $attributes));
}

function ws28Transaction(Order $order, float $amount, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'WS28-TXN-'.$sequence,
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => $amount,
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ], $attributes));
}

function ws28Session(Store $store, User $staff, array $attributes = []): PosSession
{
    return PosSession::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'staff_id' => $staff->id,
        'opened_at' => now(),
        'opening_balance' => 50000,
        'status' => PosSession::STATUS_OPEN,
    ], $attributes));
}

function ws28Stock(Store|Warehouse $location, Product $product, int $quantity, int $minQuantity = 0): StockLocation
{
    return StockLocation::create([
        'business_id' => $location->business_id,
        'product_id' => $product->id,
        'locationable_type' => $location::class,
        'locationable_id' => $location->getKey(),
        'quantity' => $quantity,
        'min_quantity' => $minQuantity,
    ]);
}

function ws28Transfer(Store|Warehouse $from, Store|Warehouse $to, Product $product, array $overrides = []): StockTransfer
{
    $transfer = StockTransfer::create(array_merge([
        'business_id' => $from->business_id,
        'from_location_type' => $from::class,
        'from_location_id' => $from->getKey(),
        'to_location_type' => $to::class,
        'to_location_id' => $to->getKey(),
        'requested_by' => $from->user_id,
        'status' => TransferStatus::PENDING,
    ], $overrides));

    StockTransferItem::create([
        'stock_transfer_id' => $transfer->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    return $transfer;
}

/** Back-date a model's timestamps so month-over-month windows can be tested. */
function ws28At($model, Carbon $at)
{
    $model->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

    return $model;
}

function ws28Staff($business, string $role = 'Cashier'): User
{
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
        'name' => 'WS28 '.$role,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole($role);

    return $staff;
}

test('the widgets payload returns every permitted KPI for a business owner', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws28Store($owner, $business, ['name' => 'Ikeja', 'has_website' => true, 'pos_enabled' => true]);
    $product = ws28Product($store, ['name' => 'Ankara Fabric']);
    ws28Stock($store, $product, 5);

    $order = ws28Order($store, ['status' => OrderStatus::COMPLETED->value]);
    ws28Transaction($order, 4000);

    $customer = Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ada-'.Str::lower(Str::random(8)).'@example.test',
        'phone' => '080'.random_int(10000000, 99999999),
        'password' => bcrypt('secret-pass-123'),
    ]);
    $order->forceFill(['customer_id' => $customer->id])->save();

    $response = $this->withToken(ws28Token($owner))->getJson('/api/v1/management/dashboard/widgets')->assertOk();

    // Money is an integer on the wire; a whole float is written by JSON as a
    // bare number and decoded back as an int, and assertJsonPath is a strict
    // identity check.
    $response
        ->assertJsonPath('data.stats.revenue.total', 4000)
        ->assertJsonPath('data.stats.revenue.this_month', 4000)
        ->assertJsonPath('data.stats.orders.total', 1)
        ->assertJsonPath('data.stats.orders.pending', 0)
        ->assertJsonPath('data.stats.orders.completed', 1)
        ->assertJsonPath('data.stats.orders.this_month', 1)
        ->assertJsonPath('data.stats.customers.total', 1)
        ->assertJsonPath('data.stats.customers.active', 1)
        ->assertJsonPath('data.stats.products.total', 1)
        ->assertJsonPath('data.stats.stock.total_units', 5)
        ->assertJsonPath('data.stats.stock.value', 5000)
        ->assertJsonPath('data.stats.stores.total', 1)
        ->assertJsonPath('data.stats.stores.active', 1)
        ->assertJsonPath('data.stats.web_visits', 1)
        ->assertJsonPath('data.stats.staff.total', 0)
        ->assertJsonPath('data.scope.store_id', null)
        ->assertJsonPath('data.scope.store', null)
        ->assertJsonPath('data.recent_orders.0.items_count', 0)
        ->assertJsonPath('data.recent_transactions.0.customer', 'Ada Obi')
        ->assertJsonPath('data.low_stock.threshold', 10);
});

test('the revenue percent change matches the legacy definition', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws28Store($owner, $business);

    $lastMonth = ws28Transaction(ws28Order($store), 1000);
    ws28At($lastMonth, now()->subMonthNoOverflow()->startOfMonth()->addDays(2));

    $thisMonth = ws28Transaction(ws28Order($store), 1500);

    $this->withToken(ws28Token($owner))
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk()
        ->assertJsonPath('data.stats.revenue.last_month', 1000)
        ->assertJsonPath('data.stats.revenue.this_month', 1500)
        ->assertJsonPath('data.stats.revenue.change_percent', 50);

    // No baseline: legacy reported +100 when anything came in, not a division
    // by zero.
    ws28At($lastMonth, now());
    $this->withToken(ws28Token($owner))
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk()
        ->assertJsonPath('data.stats.revenue.change_percent', 100);
});

test('unconfirmed transactions never count towards revenue', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws28Store($owner, $business);

    ws28Transaction(ws28Order($store), 900, ['status' => TransactionStatus::PENDING]);

    $this->withToken(ws28Token($owner))
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk()
        ->assertJsonPath('data.stats.revenue.total', 0)
        ->assertJsonPath('data.recent_transactions.0.amount', 900);
});

test('a store selection scopes every card and panel to that store', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $ikeja = ws28Store($owner, $business, ['name' => 'Ikeja']);
    $yaba = ws28Store($owner, $business, ['name' => 'Yaba']);

    ws28Transaction(ws28Order($ikeja, ['order_number' => 'IKEJA-1']), 3000);
    ws28Transaction(ws28Order($yaba, ['order_number' => 'YABA-1']), 7000);

    $ikejaProduct = ws28Product($ikeja, ['name' => 'Ikeja Widget', 'quantity' => 4]);
    $yabaProduct = ws28Product($yaba, ['name' => 'Yaba Widget', 'quantity' => 4]);

    ws28Session($ikeja, $owner);
    ws28Session($yaba, $owner);

    $response = $this->withToken(ws28Token($owner))
        ->getJson('/api/v1/management/dashboard/widgets?store_id='.$ikeja->id)
        ->assertOk();

    $response
        ->assertJsonPath('data.scope.store_id', $ikeja->id)
        ->assertJsonPath('data.scope.store.name', 'Ikeja')
        ->assertJsonPath('data.stats.revenue.total', 3000)
        ->assertJsonPath('data.stats.orders.total', 1)
        ->assertJsonPath('data.stats.products.total', 1)
        ->assertJsonPath('data.stats.stores.total', 1)
        ->assertJsonPath('data.stats.pos.open_sessions', 1)
        ->assertJsonPath('data.recent_orders.0.order_number', 'IKEJA-1')
        ->assertJsonPath('data.pos.open_sessions.0.store', 'Ikeja');

    expect(array_column($response->json('data.low_stock.items'), 'name'))
        ->toBe([$ikejaProduct->name])
        ->and(collect($response->json('data.low_stock.items'))->pluck('name'))->not->toContain($yabaProduct->name);
});

test('a store outside the accessible set is refused, not silently empty', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $mine = ws28Store($owner, $owner->business);
    $theirs = ws28Store($otherOwner, $otherBusiness);
    $token = ws28Token($owner);

    $this->withToken($token)
        ->getJson('/api/v1/management/dashboard/widgets?store_id='.$theirs->id)
        ->assertStatus(403);

    $this->withToken($token)
        ->getJson('/api/v1/management/dashboard/widgets?store_id=999999')
        ->assertStatus(403);

    $this->withToken($token)
        ->getJson('/api/v1/management/dashboard/widgets?store_id=not-a-number')
        ->assertStatus(422)
        ->assertJsonValidationErrors('store_id');

    $this->withToken($token)
        ->getJson('/api/v1/management/dashboard/widgets?store_id='.$mine->id)
        ->assertOk();
});

test('a restricted staff member gets only permitted cards and assigned-store data', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $assigned = ws28Store($owner, $business, ['name' => 'Assigned', 'pos_enabled' => true]);
    $other = ws28Store($owner, $business, ['name' => 'Other', 'pos_enabled' => true]);

    ws28Order($assigned, ['order_number' => 'MINE-1']);
    ws28Order($other, ['order_number' => 'THEIRS-1']);
    ws28Session($assigned, $owner);
    ws28Session($other, $owner);

    // Cashier: dashboard/products/orders/customers/pos, but no transactions
    // view — so the user is restricted to assigned stores.
    $cashier = ws28Staff($business, 'Cashier');
    $cashier->assignedStores()->attach($assigned->id);

    $response = $this->withToken(ws28Token($cashier))
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk();

    $response
        ->assertJsonMissingPath('data.stats.revenue')
        ->assertJsonMissingPath('data.recent_transactions')
        ->assertJsonMissingPath('data.revenue_series')
        ->assertJsonMissingPath('data.stats.transfers')
        ->assertJsonMissingPath('data.transfer_list')
        ->assertJsonMissingPath('data.stats.staff')
        ->assertJsonMissingPath('data.recent_staff')
        ->assertJsonMissingPath('data.stats.warehouses')
        ->assertJsonMissingPath('data.warehouses')
        // Cashier holds no `stores view`, so even the store card is omitted.
        ->assertJsonMissingPath('data.stats.stores')
        ->assertJsonPath('data.stats.orders.total', 1)
        ->assertJsonPath('data.stats.pos.open_sessions', 1)
        ->assertJsonPath('data.recent_orders.0.order_number', 'MINE-1');

    // The low-stock panel was ungated in legacy; it stays visible.
    $response->assertJsonStructure(['data' => ['low_stock' => ['items', 'count', 'out_of_stock_count']]]);
});

test('a user without the dashboard permission cannot load the widgets', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    $this->withToken(ws28Token($staff))
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertStatus(403);
});

test('recent orders carry the item count and store the legacy panel showed', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws28Store($owner, $business, ['name' => 'Ikeja']);
    $product = ws28Product($store);

    $order = ws28Order($store, ['order_number' => 'WS28-ITEM']);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 2, 'unit_price' => 1000, 'subtotal' => 2000]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500]);

    $this->withToken(ws28Token($owner))
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk()
        ->assertJsonPath('data.recent_orders.0.items_count', 2)
        ->assertJsonPath('data.recent_orders.0.store', 'Ikeja');
});

test('recent transactions fall back to Walk-in without a customer', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws28Store($owner, $business);

    $transaction = ws28Transaction(ws28Order($store), 1200);

    $this->withToken(ws28Token($owner))
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk()
        ->assertJsonPath('data.recent_transactions.0.customer', 'Walk-in')
        // The helper's `static $sequence` counts across every test in the
        // process, so assert the row's own reference, not a fixed number.
        ->assertJsonPath('data.recent_transactions.0.reference', $transaction->reference);
});

test('the transfer panel lists pending and approved transfers touching the scope only', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws28Store($owner, $business, ['name' => 'Ikeja']);
    $warehouse = ws28Warehouse($owner, ['name' => 'Depot']);
    $product = ws28Product($store);
    ws28Stock($warehouse, $product, 10);
    ws28Stock($store, $product, 1);

    $pending = ws28Transfer($warehouse, $store, $product);
    ws28Transfer($warehouse, $store, $product, ['status' => TransferStatus::RECEIVED]);
    // Warehouse-to-warehouse has no store; only visible when nothing is scoped.
    $warehouseOnly = ws28Transfer($warehouse, $warehouse, $product);

    $otherStore = ws28Store($otherOwner, $otherBusiness);
    ws28Transfer($otherStore, $otherStore, ws28Product($otherStore));

    $response = $this->withToken(ws28Token($owner))
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk();

    $codes = collect($response->json('data.transfer_list'))->pluck('transfer_code')->all();

    expect($codes)->toContain($pending->transfer_code)
        ->toContain($warehouseOnly->transfer_code)
        ->and($codes)->toHaveCount(2);

    $response->assertJsonPath('data.pending_transfer_count', 2);
    $response->assertJsonPath('data.transfer_list.0.items_count', 1);

    // A selected store narrows the table to transfers touching it.
    $scoped = $this->withToken(ws28Token($owner))
        ->getJson('/api/v1/management/dashboard/widgets?store_id='.$store->id)
        ->assertOk();

    expect(collect($scoped->json('data.transfer_list'))->pluck('transfer_code')->all())
        ->toBe([$pending->transfer_code]);
});

test('the low stock panel uses the products-list threshold and splits out-of-stock', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws28Store($owner, $business);

    $low = ws28Product($store, ['name' => 'Low Widget', 'quantity' => 4]);
    // The model refuses to persist a non-digital product with zero quantity,
    // so drop the row directly to model a sold-out item (the ws03 precedent).
    $empty = ws28Product($store, ['name' => 'Empty Widget', 'quantity' => 1]);
    DB::table('products')->where('id', $empty->id)->update(['quantity' => 0]);
    ws28Product($store, ['name' => 'Healthy Widget', 'quantity' => 50]);
    ws28Product($store, ['name' => 'Digital Widget', 'quantity' => 2, 'is_digital' => true]);
    ws28Product($store, ['name' => 'Inactive Widget', 'quantity' => 3, 'status' => 'inactive']);

    // Variant-driven stock is measured by the variant total, like the filter.
    $variantLow = ws28Product($store, ['name' => 'Variant Widget', 'quantity' => 0, 'has_variants' => true]);
    ProductVariant::create(['product_id' => $variantLow->id, 'quantity' => 2, 'amount' => 1000]);
    ProductVariant::create(['product_id' => $variantLow->id, 'quantity' => 1, 'amount' => 1000]);

    $variantHealthy = ws28Product($store, ['name' => 'Variant Healthy', 'quantity' => 0, 'has_variants' => true]);
    ProductVariant::create(['product_id' => $variantHealthy->id, 'quantity' => 40, 'amount' => 1000]);

    $response = $this->withToken(ws28Token($owner))
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk();

    $rows = collect($response->json('data.low_stock.items'))->keyBy('name');

    expect($rows->keys()->all())->toHaveCount(2)
        ->toContain('Low Widget')
        ->toContain('Variant Widget')
        ->and($rows['Low Widget']['quantity'])->toBe(4)
        ->and($rows['Variant Widget']['quantity'])->toBe(3)
        ->and($rows['Low Widget']['store'])->toBe($store->name)
        ->and(collect($response->json('data.low_stock.items'))->pluck('name'))
        ->not->toContain('Empty Widget')
        ->not->toContain('Healthy Widget')
        ->not->toContain('Digital Widget')
        ->not->toContain('Inactive Widget')
        ->not->toContain('Variant Healthy');

    $response
        ->assertJsonPath('data.low_stock.count', 3)
        ->assertJsonPath('data.low_stock.out_of_stock_count', 1);

    // The panel maxes out at six rows (legacy `take(6)`).
    expect($response->json('data.low_stock.items'))->toHaveCount(2);
});

test('deleted stores and warehouses are excluded from the widgets', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $live = ws28Store($owner, $business, ['name' => 'Live']);
    $gone = ws28Store($owner, $business, ['name' => 'Gone', 'status' => Store::STATUS_DELETED]);

    ws28Order($gone, ['order_number' => 'DELETED-1']);
    $goneProduct = ws28Product($gone, ['name' => 'Gone Widget', 'quantity' => 2]);

    ws28Warehouse($owner, ['name' => 'Live Depot']);
    ws28Warehouse($owner, ['name' => 'Gone Depot', 'status' => Warehouse::STATUS_DELETED]);

    $response = $this->withToken(ws28Token($owner))
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk();

    $response
        ->assertJsonPath('data.stats.stores.total', 1)
        ->assertJsonPath('data.stats.orders.total', 0)
        ->assertJsonPath('data.stats.warehouses.total', 1);

    $warehouseNames = collect($response->json('data.warehouses'))->pluck('name');

    expect($warehouseNames)->toContain('Live Depot')->not->toContain('Gone Depot');

    // A store selected from the request is access-checked against the
    // non-deleted set, so a deleted id is refused like any other.
    $this->withToken(ws28Token($owner))
        ->getJson('/api/v1/management/dashboard/widgets?store_id='.$gone->id)
        ->assertStatus(403);

    expect(collect($response->json('data.low_stock.items'))->pluck('name'))->not->toContain($goneProduct->name);
});

test('the warehouses panel reports stocked products and active state', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws28Store($owner, $business);
    $product = ws28Product($store);

    $active = ws28Warehouse($owner, ['name' => 'Active Depot', 'city' => 'Lagos', 'state' => 'Lagos']);
    $inactive = ws28Warehouse($owner, ['name' => 'Inactive Depot', 'status' => Warehouse::STATUS_INACTIVE]);

    ws28Stock($active, $product, 8);
    // A drained row should not count as a stocked product, but still counts
    // as inventory units? No — the panel counts rows with quantity > 0.
    ws28Stock($active, ws28Product($store, ['name' => 'Second']), 0);
    ws28Stock($inactive, $product, 4);

    $response = $this->withToken(ws28Token($owner))
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk();

    $rows = collect($response->json('data.warehouses'))->keyBy('name');

    expect($rows['Active Depot']['product_count'])->toBe(1)
        ->and($rows['Active Depot']['is_active'])->toBeTrue()
        ->and($rows['Active Depot']['city'])->toBe('Lagos')
        ->and($rows['Inactive Depot']['is_active'])->toBeFalse()
        ->and($response->json('data.stats.warehouses.total'))->toBe(2)
        ->and($response->json('data.stats.warehouses.total_stock'))->toBe(12);
});
