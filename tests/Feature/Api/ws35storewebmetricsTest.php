<?php

use App\Enums\InvoiceStatus;
use App\Enums\TransactionStatus;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Spatie\Permission\Models\Role;

function ws35Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws35Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Main Store',
        'slug' => 'ws35-'.random_int(1000, 9999),
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ], $attributes));
}

function ws35Product(Store $store, array $attributes = []): Product
{
    // `views` is a counter the storefront increments directly, so it is not
    // mass-assignable; set it after create the way the increments do.
    $views = (int) ($attributes['views'] ?? 0);
    unset($attributes['views']);

    $product = Product::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'Widget',
        'amount' => 1000,
        'quantity' => 10,
        'status' => 'active',
    ], $attributes));

    if ($views > 0) {
        $product->views = $views;
        $product->save();
    }

    return $product;
}

function ws35Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_number' => 'WS35-ORD-'.$sequence,
        'source' => 'checkout',
        'subtotal' => 1000,
        'total' => 1000,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

function ws35Transaction(array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'WS35-TXN-'.$sequence,
        'amount' => 1000,
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ], $attributes));
}

function ws35Invoice(User $owner, Store $store, array $attributes = []): Invoice
{
    static $sequence = 0;
    $sequence++;

    return Invoice::create(array_merge([
        'business_id' => $owner->business_id,
        'user_id' => $owner->id,
        'store_id' => $store->id,
        'invoice_number' => 'WS35-INV-'.$sequence,
        'recipient_name' => 'Ada Client',
        'recipient_email' => 'client@example.test',
        'status' => InvoiceStatus::SENT,
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(14)->toDateString(),
        'subtotal' => 2500,
        'tax_amount' => 0,
        'discount_value' => 0,
        'total' => 2500,
    ], $attributes));
}

function ws35Activity(Store $store, array $attributes = []): ActivityLog
{
    return ActivityLog::create(array_merge([
        'business_id' => $store->business_id,
        'action' => 'store_view',
        'subject_type' => Store::class,
        'subject_id' => $store->id,
        'description' => 'Viewed store products',
        'ip_address' => '102.89.34.10',
    ], $attributes));
}

/** A staff member whose role holds exactly the given permissions. */
function ws35StaffWith(User $owner, array $permissions, array $storeIds = []): User
{
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $owner->business_id,
        'name' => 'Associate '.random_int(100, 999),
    ]);

    setPermissionsTeamId($owner->business_id);

    $role = Role::firstOrCreate([
        'name' => 'WS35 Role '.substr(md5(implode(',', $permissions)), 0, 8),
        'business_id' => $owner->business_id,
        'guard_name' => 'web',
    ]);
    $role->syncPermissions($permissions);

    $staff->assignRole($role);

    foreach ($storeIds as $storeId) {
        $staff->assignedStores()->attach($storeId);
    }

    return $staff;
}

/** The full fixture: two stores of one business plus a second business. */
function ws35Fixture(): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws35Store($owner, ['name' => 'Lekki', 'views' => 500]);
    $sibling = ws35Store($owner, ['name' => 'Ikeja', 'views' => 999]);
    $foreign = ws35Store($otherOwner, ['name' => 'Theirs', 'business_id' => $otherBusiness->id, 'views' => 7777]);

    // Views are the two lifetime counters the tiles report.
    ws35Product($store, ['name' => 'Most viewed', 'views' => 120]);
    ws35Product($store, ['name' => 'Some viewed', 'views' => 40]);
    ws35Product($store, ['name' => 'Never viewed', 'views' => 0]);
    ws35Product($sibling, ['name' => 'Sibling product', 'views' => 5000]);
    ws35Product($foreign, ['name' => 'Foreign product', 'views' => 9000]);

    // Two web orders, one recent one old; a POS order belongs to neither tile.
    $recentOrder = ws35Order($store, ['order_number' => 'WS35-RECENT', 'total' => 1000]);
    $oldOrder = ws35Order($store, ['order_number' => 'WS35-OLD', 'total' => 3000]);
    $oldOrder->forceFill(['created_at' => now()->subDays(90)])->save();

    $posOrder = ws35Order($store, ['order_number' => 'WS35-POS', 'source' => 'pos', 'total' => 9000]);

    // A sibling order and a foreign order whose money must never leak in.
    $siblingOrder = ws35Order($sibling, ['order_number' => 'WS35-SIBLING']);
    $foreignOrder = ws35Order($foreign, ['order_number' => 'WS35-FOREIGN']);

    // Web revenue: confirmed checkout-order money + confirmed store invoices.
    ws35Transaction(['order_id' => $recentOrder->id, 'business_id' => $business->id, 'amount' => 1000, 'reference' => 'WS35-T-RECENT']);
    ws35Transaction(['order_id' => $oldOrder->id, 'business_id' => $business->id, 'amount' => 500, 'reference' => 'WS35-T-PENDING', 'status' => TransactionStatus::PENDING]);
    ws35Transaction(['order_id' => $posOrder->id, 'business_id' => $business->id, 'amount' => 9000, 'reference' => 'WS35-T-POS']);

    $invoice = ws35Invoice($owner, $store);
    ws35Transaction([
        'invoice_id' => $invoice->id,
        'business_id' => $business->id,
        'amount' => 2500,
        'reference' => 'WS35-T-INVOICE',
        'paid_at' => now()->subDays(90),
    ]);

    ws35Transaction(['order_id' => $siblingOrder->id, 'business_id' => $business->id, 'amount' => 700, 'reference' => 'WS35-T-SIBLING']);
    ws35Transaction(['order_id' => $foreignOrder->id, 'business_id' => $otherBusiness->id, 'amount' => 800, 'reference' => 'WS35-T-FOREIGN']);

    ws35Activity($store, ['description' => 'Viewed store products', 'action' => 'store_view']);
    ws35Activity($store, ['description' => 'Featured product updated', 'action' => 'store_update', 'ip_address' => '102.89.34.11']);
    ws35Activity($sibling, ['description' => 'Sibling activity']);

    return [$owner, $business, $otherOwner, $otherBusiness, $store, $sibling, $foreign];
}

test('web metrics report the legacy tiles, chart, top products and activity', function () {
    [$owner, $business, , , $store] = ws35Fixture();

    $response = $this->withToken(ws35Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/web-metrics')
        ->assertOk();

    $response
        ->assertJsonPath('data.store.name', 'Lekki')
        ->assertJsonPath('data.store.has_website', true)
        ->assertJsonPath('data.store.store_url', 'https://'.$store->slug.'.'.config('app.main_domain', 'storify.ng'))
        // Lifetime counters.
        ->assertJsonPath('data.metrics.store_views', 500)
        ->assertJsonPath('data.metrics.product_views', 160)
        // Checkout orders only — the POS order and other stores do not count.
        ->assertJsonPath('data.metrics.web_orders', 2)
        // Confirmed money only: 1000 on the recent order + 2500 on the invoice,
        // in kobo. The pending transaction and the POS order are excluded.
        ->assertJsonPath('data.metrics.web_revenue_kobo', 350000)
        // (1000 + 3000) * 100 kobo across the two web orders.
        ->assertJsonPath('data.metrics.average_order_value_kobo', 200000);

    // The default chart is the legacy six-month window, zero-filled, bucketed
    // by month.
    expect($response->json('data.range.is_custom'))->toBeFalse()
        ->and($response->json('data.range.chart.bucket'))->toBe('month')
        ->and($response->json('data.web_orders_series'))->toHaveCount(6);

    // Both web orders fall inside the default six-month window.
    $seriesTotal = array_sum(array_column($response->json('data.web_orders_series'), 'count'));
    expect($seriesTotal)->toBe(2);

    // Top products by views, this store only, highest first.
    $topProducts = $response->json('data.top_products');
    expect(array_column($topProducts, 'name'))->toBe(['Most viewed', 'Some viewed', 'Never viewed'])
        ->and(array_column($topProducts, 'views'))->toBe([120, 40, 0]);

    // Activity feed: this store's rows only, newest first, with IP and author.
    $activity = $response->json('data.recent_activity');
    expect($activity)->toHaveCount(2)
        ->and(collect($activity)->pluck('description'))->toContain('Viewed store products')
        ->and(collect($activity)->pluck('description'))->not->toContain('Sibling activity')
        ->and($activity[0]['ip_address'])->not->toBeNull();
});

test('a date range scopes web orders and revenue, and switches the chart to days', function () {
    [$owner, , , , $store] = ws35Fixture();

    // Last 30 days: the recent order + its confirmed payment only.
    $response = $this->withToken(ws35Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/web-metrics?from='.now()->subDays(30)->toDateString().'&to='.now()->toDateString())
        ->assertOk();

    $response
        ->assertJsonPath('data.range.is_custom', true)
        ->assertJsonPath('data.range.chart.bucket', 'day')
        ->assertJsonPath('data.metrics.web_orders', 1)
        ->assertJsonPath('data.metrics.web_revenue_kobo', 100000)
        ->assertJsonPath('data.metrics.average_order_value_kobo', 100000);

    // Daily buckets are zero-filled across the whole range.
    $series = $response->json('data.web_orders_series');
    expect(count($series))->toBe(31)
        ->and(array_sum(array_column($series, 'count')))->toBe(1);

    // A window that only contains the old order picks its money up instead.
    $older = $this->withToken(ws35Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/web-metrics?from='.now()->subDays(120)->toDateString().'&to='.now()->subDays(60)->toDateString())
        ->assertOk();

    $older
        ->assertJsonPath('data.metrics.web_orders', 1)
        ->assertJsonPath('data.metrics.web_revenue_kobo', 250000)
        ->assertJsonPath('data.metrics.average_order_value_kobo', 300000);
});

test('the view counters stay lifetime when a range is requested', function () {
    [$owner, , , , $store] = ws35Fixture();

    $this->withToken(ws35Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/web-metrics?from='.now()->subDays(7)->toDateString().'&to='.now()->toDateString())
        ->assertOk()
        // Counters carry no timestamps, so they are reported as all-time and
        // the SPA labels them that way rather than pretending they filtered.
        ->assertJsonPath('data.metrics.store_views', 500)
        ->assertJsonPath('data.metrics.product_views', 160);
});

test('a bad date range is rejected', function () {
    [$owner, , , , $store] = ws35Fixture();

    $token = ws35Token($owner);
    $url = '/api/v1/management/stores/'.$store->store_id.'/web-metrics';

    $this->withToken($token)->getJson($url.'?from=2026-13-01&to='.now()->toDateString())
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');

    // from and to travel as a pair — a half-open range is a client bug, not a
    // silent "since the beginning of time" read.
    $this->withToken($token)->getJson($url.'?from='.now()->subDays(7)->toDateString())
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');

    $this->withToken($token)->getJson($url.'?to='.now()->toDateString())
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');

    $this->withToken($token)->getJson($url.'?from='.now()->toDateString().'&to='.now()->subDays(7)->toDateString())
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');
});

test('a brand-new storefront reports zeroes instead of failing', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws35Store($owner, ['name' => 'Fresh', 'views' => 0]);

    $response = $this->withToken(ws35Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/web-metrics')
        ->assertOk();

    $response
        ->assertJsonPath('data.metrics.store_views', 0)
        ->assertJsonPath('data.metrics.product_views', 0)
        ->assertJsonPath('data.metrics.web_orders', 0)
        ->assertJsonPath('data.metrics.web_revenue_kobo', 0)
        ->assertJsonPath('data.metrics.average_order_value_kobo', null)
        ->assertJsonPath('data.top_products', [])
        ->assertJsonPath('data.recent_activity', []);

    // The chart is still a full, zero-filled series so the SPA can draw an
    // honest empty chart rather than an empty box.
    $series = $response->json('data.web_orders_series');
    expect($series)->toHaveCount(6)
        ->and(array_sum(array_column($series, 'count')))->toBe(0);
});

test('a store without a storefront is refused with a machine-readable marker', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws35Store($owner, ['name' => 'Offline', 'has_website' => false]);

    $this->withToken(ws35Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/web-metrics')
        ->assertStatus(422)
        ->assertJsonPath('errors.store.0', 'no_website');
});

test('a store from another business is not reachable', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [, , $otherOwner, $otherBusiness] = ws35Fixture();

    $foreign = ws35Store($otherOwner, ['name' => 'Theirs', 'business_id' => $otherBusiness->id]);

    $this->withToken(ws35Token($owner))
        ->getJson('/api/v1/management/stores/'.$foreign->store_id.'/web-metrics')
        ->assertForbidden();
});

test('a deleted store is refused even when it has a storefront', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws35Store($owner, ['name' => 'Gone', 'status' => Store::STATUS_DELETED]);

    $this->withToken(ws35Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/web-metrics')
        ->assertForbidden();
});

test('the metrics require the stores view permission and an assigned store', function () {
    [$owner, , , , $store, $sibling] = ws35Fixture();

    // Assigned to the store but missing the permission.
    $noPermission = ws35StaffWith($owner, ['products view'], [$store->id]);

    $this->withToken(ws35Token($noPermission))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/web-metrics')
        ->assertForbidden();

    // Holds the permission but is only assigned to the other store.
    $otherStore = ws35StaffWith($owner, ['stores view'], [$sibling->id]);

    // Sanctum's guard caches the authenticated user for the life of the test
    // application, so swapping the bearer token alone would keep the request
    // above's staff member cached. Reset the guards so this request is
    // actually authorised as the staff member who holds `stores view`.
    $this->app['auth']->forgetGuards();

    $this->withToken(ws35Token($otherStore))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/web-metrics')
        ->assertForbidden();

    // Assigned and permitted — allowed.
    $assigned = ws35StaffWith($owner, ['stores view'], [$store->id]);

    // Same guard reset: without it this request would still run as the
    // unassigned staff member above and answer 403 instead of 200.
    $this->app['auth']->forgetGuards();

    $this->withToken(ws35Token($assigned))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/web-metrics')
        ->assertOk()
        ->assertJsonPath('data.metrics.web_orders', 2);

    // withToken() writes into the test's default headers, so a request issued
    // after it would still be authenticated (and answer 200, not 401). The
    // cached guard user outlives the header too, so reset before dropping the
    // token.
    $this->app['auth']->forgetGuards();

    $this->withoutToken()
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/web-metrics')
        ->assertUnauthorized();
});
