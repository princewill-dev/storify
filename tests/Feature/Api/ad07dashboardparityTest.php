<?php

use App\Enums\TransactionStatus;
use App\Enums\TransferStatus;
use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\KycApplication;
use App\Models\Order;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WS-7 — Dashboard parity completion (admin console).
 *
 * Covers the restored legacy read-outs (KPI row, stats grid, both daily
 * charts, store table, transfer/transaction/order panels), the store and date
 * filters with the exact legacy scope (verify correction C1), the configurable
 * low-stock band, panel limits, query validation, the platform-admin boundary
 * and the audit row the dashboard route now writes.
 */
function ad07Token(User $user): string
{
    return $user->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad07SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad07PlatformAdmin(): User
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

function ad07Store(User $owner, Business $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Store',
        'slug' => 'ad07-store-'.random_int(100000, 999999),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ad07Customer(Business $business, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $business->id,
        'first_name' => 'Ada',
        'last_name' => 'Buyer',
        'email' => 'ada'.random_int(1000, 9999).'@example.test',
        // `phone` and `password` are NOT NULL with no default on the
        // restructured customers table (see ws19's fixture).
        'phone' => '080'.random_int(10000000, 99999999),
        'password' => bcrypt('ad07-secret'),
        'status' => Customer::STATUS_ACTIVE,
    ], $attributes));
}

/**
 * A product plus (optionally) stock on hand at a location.
 */
function ad07Product(Store $store, array $attributes = [], int $stockAtStore = 0): Product
{
    $attributes = array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'Product '.random_int(1000, 9999),
        'amount' => 100,
        'quantity' => 0,
        'status' => 'active',
    ], $attributes);

    // The Product model refuses to persist a non-digital, non-variant product
    // with a non-positive quantity (the ad15 catalogue rule asserts that), so a
    // sold-out fixture is created with stock and then dropped to zero directly
    // — the ws03/ws28 precedent for modelling an out-of-stock row.
    $soldOut = (int) $attributes['quantity'] <= 0
        && empty($attributes['has_variants'])
        && empty($attributes['is_digital']);

    if ($soldOut) {
        $attributes['quantity'] = 1;
    }

    $product = Product::create($attributes);

    if ($soldOut) {
        DB::table('products')->where('id', $product->id)->update(['quantity' => 0]);
        $product->quantity = 0;
    }

    if ($stockAtStore > 0) {
        StockLocation::create([
            'business_id' => $store->business_id,
            'product_id' => $product->id,
            'locationable_type' => Store::class,
            'locationable_id' => $store->id,
            'quantity' => $stockAtStore,
        ]);
    }

    return $product;
}

function ad07Order(Store $store, array $attributes = []): Order
{
    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_number' => 'AD07-ORD-'.random_int(100000, 999999),
        'subtotal' => 1000,
        'total' => 1000,
        'amount_paid' => 1000,
        'status' => 'pending',
    ], $attributes));
}

function ad07Transaction(Order $order, array $attributes = []): Transaction
{
    return Transaction::create(array_merge([
        'reference' => 'AD07-TXN-'.random_int(100000, 999999),
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => 1000,
        'status' => TransactionStatus::CONFIRMED,
    ], $attributes));
}

test('the dashboard returns the legacy KPI, stats, chart and panel payload', function () {
    $admin = ad07SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad07Store($owner, $business, ['name' => 'Flagship']);

    $customer = ad07Customer($business);
    $order = ad07Order($store, ['customer_id' => $customer->id, 'total' => 2500, 'status' => 'completed']);
    ad07Transaction($order, ['amount' => 2500]);

    ad07Product($store, ['name' => 'Widget', 'amount' => 400, 'quantity' => 3], stockAtStore: 3);
    ad07Product($store, ['name' => 'Sold out', 'amount' => 50]);

    Warehouse::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Depot',
        'status' => Warehouse::STATUS_ACTIVE,
    ]);

    KycApplication::create([
        'user_id' => $owner->id,
        'status' => KycApplication::STATUS_SUBMITTED,
        'legal_name' => 'Acme Holdings Ltd',
        'submitted_at' => now(),
    ]);

    $warehouse = Warehouse::first();
    $otherStore = ad07Store($owner, $business, ['name' => 'Second', 'status' => Store::STATUS_SUSPENDED]);

    StockTransfer::create([
        'business_id' => $business->id,
        'requested_by' => $owner->id,
        'from_location_type' => Warehouse::class,
        'from_location_id' => $warehouse->id,
        'to_location_type' => Store::class,
        'to_location_id' => $store->id,
        'status' => TransferStatus::PENDING,
    ]);

    $response = $this->getJson('/api/v1/admin/dashboard', ['Authorization' => 'Bearer '.ad07Token($admin)])
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'range_days',
                'filters' => ['store_id', 'store_code', 'store_name', 'from', 'to', 'days', 'low_stock_threshold'],
                'stores',
                'kpis' => ['revenue_today', 'orders_today', 'revenue_mtd', 'stock_value', 'active_stores', 'customers'],
                'stats' => [
                    'businesses', 'active_businesses', 'stores', 'active_stores', 'total_warehouses',
                    'users', 'staff', 'total_staff', 'customers', 'products', 'total_products',
                    'active_products', 'units_in_stock', 'stock_value', 'low_stock', 'low_stock_threshold',
                    'out_of_stock', 'open_pos_sessions', 'orders', 'orders_pending', 'orders_today',
                    'transactions', 'total_transactions', 'revenue_total', 'revenue_today', 'revenue_mtd',
                    'kyc_pending',
                ],
                'daily_revenue' => [['date', 'total']],
                'daily_orders' => [['date', 'total']],
                'payment_breakdown',
                'top_stores' => [[
                    'id', 'store_id', 'name', 'status', 'owner', 'business', 'revenue_today',
                    'revenue_mtd', 'orders_today', 'orders_count', 'revenue', 'products_count',
                    'pos_status', 'last_order_at',
                ]],
                'pending_transfers' => [['transfer_code', 'from', 'to', 'status', 'status_label']],
                'transfer_stats' => ['pending', 'today_dispatched', 'today_received'],
                'recent_transactions' => [['reference', 'store', 'amount', 'status']],
                'recent_orders' => [['order_number', 'customer', 'store', 'total', 'status']],
                'recent_businesses',
                'revenue_series' => [['month', 'total']],
                'orders_series' => [['month', 'total']],
            ],
        ]);

    // Money is kobo integers on the wire — whole floats serialise without the
    // fraction, so the decoded payload holds ints (assertJsonPath is strict).
    expect($response->json('data.kpis.revenue_today'))->toBe(2500)
        ->and($response->json('data.kpis.orders_today'))->toBe(1)
        ->and($response->json('data.kpis.revenue_mtd'))->toBe(2500)
        // 3 units at 400 — stock value counts stock on hand, not product quantity.
        ->and($response->json('data.kpis.stock_value'))->toBe(1200)
        // Flagship is active; Second is suspended — active stores counts only the former.
        ->and($response->json('data.kpis.active_stores'))->toBe(1)
        ->and($response->json('data.kpis.customers'))->toBe(1)
        ->and($response->json('data.stats.total_warehouses'))->toBe(1)
        ->and($response->json('data.stats.units_in_stock'))->toBe(3)
        ->and($response->json('data.stats.stock_value'))->toBe(1200)
        // 3 is low (<=10); the sold-out product is out of stock.
        ->and($response->json('data.stats.low_stock'))->toBe(1)
        ->and($response->json('data.stats.low_stock_threshold'))->toBe(10)
        ->and($response->json('data.stats.out_of_stock'))->toBe(1)
        ->and($response->json('data.stats.total_products'))->toBe(2)
        ->and($response->json('data.stats.kyc_pending'))->toBe(1)
        ->and($response->json('data.range_days'))->toBe(30)
        ->and($response->json('data.daily_revenue'))->toHaveCount(30)
        ->and($response->json('data.daily_orders'))->toHaveCount(30)
        ->and($response->json('data.revenue_series'))->toHaveCount(6)
        ->and($response->json('data.orders_series'))->toHaveCount(6)
        ->and($response->json('data.filters.store_id'))->toBeNull()
        ->and($response->json('data.stores'))->toHaveCount(2)
        ->and($response->json('data.pending_transfers.0.transfer_code'))->not->toBeNull()
        ->and($response->json('data.pending_transfers.0.from'))->toBe('Depot')
        ->and($response->json('data.pending_transfers.0.to'))->toBe('Flagship')
        ->and($response->json('data.transfer_stats.pending'))->toBe(1)
        ->and($response->json('data.recent_transactions.0.reference'))->not->toBeNull()
        ->and($response->json('data.recent_transactions.0.store'))->toBe('Flagship')
        ->and($response->json('data.recent_orders.0.order_number'))->toBe($order->order_number)
        ->and($response->json('data.recent_orders.0.customer'))->toBe('Ada Buyer');

    // The store table covers non-deleted stores with today's pulse.
    $flagship = collect($response->json('data.top_stores'))->firstWhere('name', 'Flagship');
    expect($flagship['orders_today'])->toBe(1)
        ->and($flagship['products_count'])->toBe(2)
        ->and($flagship['revenue_today'])->toBe(2500)
        ->and($flagship['revenue_mtd'])->toBe(2500)
        ->and($flagship['pos_status'])->toBe('closed')
        ->and($flagship['last_order_at'])->not->toBeNull()
        ->and(collect($response->json('data.top_stores'))->pluck('name')->all())
        ->toBe(['Flagship', 'Second']);
});

test('the store filter scopes exactly the widgets legacy scoped', function () {
    $admin = ad07SuperAdmin();
    [$ownerA, $businessA] = createBusinessOwner();
    $storeA = ad07Store($ownerA, $businessA, ['name' => 'Alpha']);
    $storeB = ad07Store($ownerA, $businessA, ['name' => 'Beta']);

    ad07Customer($businessA);

    // Alpha: two confirmed payments totalling 1500, one product with stock.
    $alphaOrder = ad07Order($storeA, ['total' => 1500, 'status' => 'completed']);
    ad07Transaction($alphaOrder, ['amount' => 1000]);
    ad07Transaction($alphaOrder, ['amount' => 500]);
    ad07Product($storeA, ['amount' => 100, 'quantity' => 5], stockAtStore: 5);

    // Beta: one payment of 7000, its own stock.
    $betaOrder = ad07Order($storeB, ['total' => 7000, 'status' => 'completed']);
    ad07Transaction($betaOrder, ['amount' => 7000]);
    ad07Product($storeB, ['amount' => 200, 'quantity' => 10], stockAtStore: 10);

    // A transfer touching each store.
    $warehouse = Warehouse::create([
        'user_id' => $ownerA->id,
        'business_id' => $businessA->id,
        'name' => 'Depot',
        'status' => Warehouse::STATUS_ACTIVE,
    ]);
    StockTransfer::create([
        'business_id' => $businessA->id,
        'requested_by' => $ownerA->id,
        'from_location_type' => Warehouse::class,
        'from_location_id' => $warehouse->id,
        'to_location_type' => Store::class,
        'to_location_id' => $storeA->id,
        'status' => TransferStatus::PENDING,
    ]);
    StockTransfer::create([
        'business_id' => $businessA->id,
        'requested_by' => $ownerA->id,
        'from_location_type' => Store::class,
        'from_location_id' => $storeB->id,
        'to_location_type' => Warehouse::class,
        'to_location_id' => $warehouse->id,
        'status' => TransferStatus::APPROVED,
    ]);

    $token = ad07Token($admin);

    $filtered = $this->getJson('/api/v1/admin/dashboard?store_id='.$storeA->id, ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    // Scoped: the four KPI tiles, stock counts, charts, donut, transfers, table.
    expect($filtered->json('data.kpis.revenue_today'))->toBe(1500)
        ->and($filtered->json('data.kpis.revenue_mtd'))->toBe(1500)
        ->and($filtered->json('data.kpis.orders_today'))->toBe(1)
        ->and($filtered->json('data.kpis.stock_value'))->toBe(500)
        ->and($filtered->json('data.stats.units_in_stock'))->toBe(5)
        ->and(collect($filtered->json('data.daily_revenue'))->sum('total'))->toBe(1500)
        ->and(collect($filtered->json('data.daily_orders'))->sum('total'))->toBe(1)
        ->and(collect($filtered->json('data.payment_breakdown'))->sum('total'))->toBe(1500)
        ->and($filtered->json('data.top_stores'))->toHaveCount(1)
        ->and($filtered->json('data.top_stores.0.name'))->toBe('Alpha')
        ->and($filtered->json('data.pending_transfers'))->toHaveCount(1)
        ->and($filtered->json('data.pending_transfers.0.to'))->toBe('Alpha')
        ->and($filtered->json('data.filters.store_name'))->toBe('Alpha');

    // NOT scoped (verify correction C1): stores/customers counts, the stats
    // grid, the transfer counters and the recent feeds stay platform-wide.
    expect($filtered->json('data.kpis.active_stores'))->toBe(2)
        ->and($filtered->json('data.kpis.customers'))->toBe(1)
        ->and($filtered->json('data.stats.stores'))->toBe(2)
        ->and($filtered->json('data.stats.orders'))->toBe(2)
        ->and($filtered->json('data.stats.businesses'))->toBe(1)
        ->and($filtered->json('data.transfer_stats.pending'))->toBe(2)
        ->and($filtered->json('data.recent_transactions'))->toHaveCount(3);

    // The selector accepts the public store code as well as the id.
    $byCode = $this->getJson('/api/v1/admin/dashboard?store_id='.$storeB->store_id, ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    expect($byCode->json('data.kpis.revenue_today'))->toBe(7000)
        ->and($byCode->json('data.kpis.stock_value'))->toBe(2000)
        ->and($byCode->json('data.top_stores.0.name'))->toBe('Beta');
});

test('the date range scopes the payment donut and is validated', function () {
    // Mid-month, so "five days ago" is always inside the MTD window.
    $this->travelTo(Carbon::parse('2026-03-15 12:00:00'));

    $admin = ad07SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad07Store($owner, $business);

    $todayOrder = ad07Order($store, ['total' => 1000]);
    ad07Transaction($todayOrder, ['amount' => 1000, 'status' => TransactionStatus::CONFIRMED]);

    // Five days old: inside MTD, outside a today-only donut window.
    $older = ad07Transaction(ad07Order($store, ['total' => 400]), [
        'amount' => 400,
        'status' => TransactionStatus::CONFIRMED,
    ]);
    $older->forceFill(['created_at' => now()->subDays(5)])->save();

    $token = ad07Token($admin);
    $today = now()->toDateString();

    $response = $this->getJson("/api/v1/admin/dashboard?from={$today}&to={$today}", ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    expect(collect($response->json('data.payment_breakdown'))->sum('total'))->toBe(1000)
        ->and($response->json('data.filters.from'))->toBe($today)
        ->and($response->json('data.filters.to'))->toBe($today)
        // MTD is a fixed window, not the donut range.
        ->and($response->json('data.kpis.revenue_mtd'))->toBe(1400);

    $this->getJson('/api/v1/admin/dashboard?from=not-a-date', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');

    $this->getJson('/api/v1/admin/dashboard?from=2026-01-10&to=2026-01-01', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');

    $this->getJson('/api/v1/admin/dashboard?days=15', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('days');

    $this->getJson('/api/v1/admin/dashboard?store_id=999999', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('store_id');
});

test('the low-stock band restores the legacy threshold and is configurable', function () {
    $admin = ad07SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad07Store($owner, $business);

    foreach ([1, 5, 10, 11, 0] as $quantity) {
        ad07Product($store, ['quantity' => $quantity]);
    }

    $token = ad07Token($admin);

    // Legacy band: 1..10 — the previous endpoint silently used 1..5.
    $this->getJson('/api/v1/admin/dashboard', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.stats.low_stock', 3)
        ->assertJsonPath('data.stats.low_stock_threshold', 10)
        ->assertJsonPath('data.stats.out_of_stock', 1);

    $this->getJson('/api/v1/admin/dashboard?low_stock_threshold=5', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.stats.low_stock', 2)
        ->assertJsonPath('data.filters.low_stock_threshold', 5);

    $this->getJson('/api/v1/admin/dashboard?low_stock_threshold=0', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('low_stock_threshold');

    $this->getJson('/api/v1/admin/dashboard?low_stock_threshold=101', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('low_stock_threshold');
});

test('the store table reports POS status, last sale and a drill-through id', function () {
    // Mid-month, so "three days ago" is always inside the MTD window.
    $this->travelTo(Carbon::parse('2026-03-15 12:00:00'));

    $admin = ad07SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $live = ad07Store($owner, $business, ['name' => 'Live Store']);
    $quiet = ad07Store($owner, $business, ['name' => 'Quiet Store']);
    $removed = ad07Store($owner, $business, ['name' => 'Gone Store', 'status' => Store::STATUS_DELETED]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
        'name' => 'Ada Staff',
    ]);

    PosSession::create([
        'store_id' => $live->id,
        'business_id' => $business->id,
        'staff_id' => $staff->id,
        'status' => PosSession::STATUS_OPEN,
    ]);

    // Order totals (not confirmed transactions) drive the store table revenue,
    // exactly as legacy's withSum did.
    ad07Order($live, ['total' => 3000, 'status' => 'completed']);
    $oldOrder = ad07Order($quiet, ['total' => 900, 'status' => 'completed']);
    $oldOrder->forceFill(['created_at' => now()->subDays(3)])->save();

    $response = $this->getJson('/api/v1/admin/dashboard', ['Authorization' => 'Bearer '.ad07Token($admin)])
        ->assertOk();

    $rows = collect($response->json('data.top_stores'));

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('name')->all())->toBe(['Live Store', 'Quiet Store'])
        ->and($rows->firstWhere('name', 'Live Store')['pos_status'])->toBe('open')
        ->and($rows->firstWhere('name', 'Live Store')['revenue_today'])->toBe(3000)
        ->and($rows->firstWhere('name', 'Quiet Store')['pos_status'])->toBe('closed')
        // Three days old: not today's revenue, still MTD.
        ->and($rows->firstWhere('name', 'Quiet Store')['revenue_today'])->toBe(0)
        ->and($rows->firstWhere('name', 'Quiet Store')['revenue_mtd'])->toBe(900)
        ->and($rows->firstWhere('name', 'Quiet Store')['last_order_at'])->not->toBeNull()
        ->and($rows->pluck('name'))->not->toContain($removed->name);

    // The drill-through id is the public store code other screens route on.
    expect($rows->firstWhere('name', 'Live Store')['store_id'])->toBe($live->store_id);

    $this->getJson('/api/v1/admin/dashboard?store_id='.$live->id, ['Authorization' => 'Bearer '.ad07Token($admin)])
        ->assertOk()
        ->assertJsonPath('data.top_stores.0.name', 'Live Store');
});

test('the dashboard panels honour the legacy limits and statuses', function () {
    $admin = ad07SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad07Store($owner, $business);
    $warehouse = Warehouse::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Depot',
        'status' => Warehouse::STATUS_ACTIVE,
    ]);

    for ($i = 0; $i < 12; $i++) {
        $order = ad07Order($store, ['total' => 100 + $i]);
        $order->forceFill(['created_at' => now()->subMinutes(20 - $i)])->save();

        $transaction = ad07Transaction($order, ['amount' => 100 + $i]);
        $transaction->forceFill(['created_at' => now()->subMinutes(20 - $i)])->save();
    }

    // A pending payment never shows in the "confirmed" panel (nor does its
    // order lead the recent-orders panel — it is an hour old).
    $pendingOrder = ad07Order($store, ['total' => 777]);
    $pendingOrder->forceFill(['created_at' => now()->subHour()])->save();
    $pending = ad07Transaction($pendingOrder, ['amount' => 777, 'status' => TransactionStatus::PENDING]);
    $pending->forceFill(['created_at' => now()->subHour()])->save();

    for ($i = 0; $i < 6; $i++) {
        StockTransfer::create([
            'business_id' => $business->id,
            'requested_by' => $owner->id,
            'from_location_type' => Warehouse::class,
            'from_location_id' => $warehouse->id,
            'to_location_type' => Store::class,
            'to_location_id' => $store->id,
            'status' => $i % 2 === 0 ? TransferStatus::PENDING : TransferStatus::APPROVED,
        ]);
    }

    StockTransfer::create([
        'business_id' => $business->id,
        'requested_by' => $owner->id,
        'from_location_type' => Store::class,
        'from_location_id' => $store->id,
        'to_location_type' => Warehouse::class,
        'to_location_id' => $warehouse->id,
        'status' => TransferStatus::DISPATCHED,
    ]);

    StockTransfer::create([
        'business_id' => $business->id,
        'requested_by' => $owner->id,
        'from_location_type' => Store::class,
        'from_location_id' => $store->id,
        'to_location_type' => Warehouse::class,
        'to_location_id' => $warehouse->id,
        'status' => TransferStatus::RECEIVED,
    ]);

    $response = $this->getJson('/api/v1/admin/dashboard', ['Authorization' => 'Bearer '.ad07Token($admin)])
        ->assertOk();

    $transactions = $response->json('data.recent_transactions');
    $orders = $response->json('data.recent_orders');

    expect($transactions)->toHaveCount(10)
        ->and($orders)->toHaveCount(10)
        // Newest first, and the pending payment is nowhere in the confirmed feed.
        ->and($transactions[0]['amount'])->toBe(111)
        ->and(collect($transactions)->pluck('reference'))->not->toContain($pending->reference)
        ->and($orders[0]['total'])->toBe(111)
        ->and($response->json('data.pending_transfers'))->toHaveCount(5)
        ->and($response->json('data.transfer_stats.pending'))->toBe(6)
        ->and($response->json('data.transfer_stats.today_dispatched'))->toBe(1)
        ->and($response->json('data.transfer_stats.today_received'))->toBe(1);
});

test('a platform admin with the permission can read but a permissionless admin cannot', function () {
    $platformAdmin = ad07PlatformAdmin();

    $this->getJson('/api/v1/admin/dashboard', ['Authorization' => 'Bearer '.ad07Token($platformAdmin)])
        ->assertOk();

    // Sanctum's guard caches the resolved user for the whole test, so the
    // guard must be forgotten before a request as a different user.
    app('auth')->forgetGuards();

    $plainAdmin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    $this->getJson('/api/v1/admin/dashboard', ['Authorization' => 'Bearer '.ad07Token($plainAdmin)])
        ->assertStatus(403);
});

test('a business-scoped account cannot read the platform dashboard', function () {
    [$owner, $business] = createBusinessOwner();

    // The in-business "Super Admin" role bundles the admin.* permission names,
    // so the permission gate alone would let a leaked admin token through; the
    // platform guard is what stops it.
    setPermissionsTeamId($business->id);

    expect($owner->can('admin.dashboard'))->toBeTrue();

    $this->getJson('/api/v1/admin/dashboard', [
        'Authorization' => 'Bearer '.$owner->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken,
    ])->assertStatus(403);
});

test('management tokens and guests are refused', function () {
    [$owner] = createBusinessOwner();

    $managementToken = $owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;

    $this->getJson('/api/v1/admin/dashboard', ['Authorization' => 'Bearer '.$managementToken])
        ->assertStatus(403);

    // Forget the cached guard user so the tokenless request is a real guest.
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
});

test('viewing the dashboard is itself audited', function () {
    $admin = ad07SuperAdmin();

    $this->getJson('/api/v1/admin/dashboard', ['Authorization' => 'Bearer '.ad07Token($admin)])
        ->assertOk();

    $row = ActivityLog::where('action', 'admin_route_accessed')->first();

    expect($row)->not->toBeNull()
        ->and($row->user_id)->toBe($admin->id)
        ->and($row->metadata['route'])->toBe('api.admin.dashboard')
        ->and($row->metadata['path'])->toBe('api/v1/admin/dashboard')
        ->and($row->metadata['status'])->toBe(200);
});
