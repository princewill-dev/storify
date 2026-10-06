<?php

use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function ws03Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws03Store(User $owner, $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Main Store',
        'slug' => 'main-store-'.random_int(1000, 9999),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws03Product(Store $store, array $attributes = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'Widget',
        'amount' => 1000,
        'quantity' => 10,
        'status' => 'active',
    ], $attributes));
}

function ws03Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_number' => 'WS03-ORD-'.$sequence,
        'subtotal' => 1000,
        'total' => 1000,
        'amount_paid' => 1000,
        'status' => 'pending',
    ], $attributes));
}

function ws03Transaction(Order $order, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'WS03-TXN-'.$sequence,
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => 1000,
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ], $attributes));
}

test('the store dashboard returns store-scoped metrics', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws03Store($owner, $business, ['name' => 'Lekki', 'has_website' => true, 'slug' => 'lekki']);
    $other = ws03Store($owner, $business, ['name' => 'Ikeja', 'slug' => 'ikeja']);

    // phone and password are NOT NULL on customers (strict mode), so every
    // customer insert supplies them the way the other management tests do.
    $ada = Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Ada',
        'last_name' => 'Eze',
        'email' => 'ada@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
    ]);
    $bola = Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Bola',
        'last_name' => 'Ade',
        'email' => 'bola@example.test',
        'phone' => '08033334444',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
    ]);

    $pending = ws03Order($store, ['customer_id' => $ada->id, 'status' => 'pending', 'total' => 1500]);
    $completed = ws03Order($store, ['customer_id' => $bola->id, 'status' => 'completed', 'total' => 2500]);
    $elsewhere = ws03Order($other, ['status' => 'completed', 'total' => 9999]);

    // Revenue attributed through the order, plus a pending payment that must
    // not count and another store's transaction that must not leak in.
    ws03Transaction($pending, ['amount' => 5000, 'paid_at' => now()]);
    ws03Transaction($completed, ['amount' => 2000, 'paid_at' => now()->subMonth()->startOfMonth()->addDays(5)]);
    ws03Transaction($elsewhere, ['amount' => 9000, 'paid_at' => now()]);
    ws03Transaction($pending, ['amount' => 7000, 'status' => TransactionStatus::PENDING, 'paid_at' => now()]);

    ws03Product($store, ['name' => 'Low Widget', 'quantity' => 3]);
    ws03Product($store, ['name' => 'Inactive Widget', 'quantity' => 2, 'status' => 'inactive']);
    ws03Product($store, ['name' => 'E-book', 'quantity' => null, 'is_digital' => true]);
    $outOfStock = ws03Product($store, ['name' => 'Out Widget', 'quantity' => 1]);

    // The model refuses to persist a non-digital product with zero quantity,
    // so drop the row directly to model a sold-out item.
    DB::table('products')->where('id', $outOfStock->id)->update(['quantity' => 0]);

    $response = $this->withToken(ws03Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/dashboard')
        ->assertOk();

    $response
        ->assertJsonPath('data.stats.revenue.total', 7000)
        ->assertJsonPath('data.stats.revenue.this_month', 5000)
        ->assertJsonPath('data.stats.revenue.last_month', 2000)
        ->assertJsonPath('data.stats.revenue.change_percent', 150)
        ->assertJsonPath('data.stats.revenue.change_direction', 'up')
        ->assertJsonPath('data.stats.orders.total', 2)
        ->assertJsonPath('data.stats.orders.pending', 1)
        ->assertJsonPath('data.stats.orders.completed', 1)
        ->assertJsonPath('data.stats.orders.fulfilled', 1)
        ->assertJsonPath('data.stats.products.total', 4)
        ->assertJsonPath('data.stats.products.total_stock', 5)
        ->assertJsonPath('data.stats.products.low_stock', 1)
        ->assertJsonPath('data.stats.products.out_of_stock', 1)
        ->assertJsonPath('data.stats.customers.total', 2);

    expect($response->json('data.recent_orders'))->toHaveCount(2)
        ->and(array_column($response->json('data.recent_orders'), 'order_number'))
        ->not->toContain($elsewhere->order_number);

    expect($response->json('data.revenue_series'))->toHaveCount(6);

    $series = collect($response->json('data.revenue_series'))->keyBy('month');
    expect($series['2026-06']['total'])->toBe(5000)
        ->and($series['2026-05']['total'])->toBe(2000)
        ->and($series['2026-04']['total'])->toBe(0);
});

test('the low stock list uses one documented threshold and excludes digital and inactive products', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws03Store($owner, $business);

    ws03Product($store, ['name' => 'Four Left', 'quantity' => 4]);
    ws03Product($store, ['name' => 'Two Left', 'quantity' => 2]);
    ws03Product($store, ['name' => 'Well Stocked', 'quantity' => 40]);
    ws03Product($store, ['name' => 'Archived', 'quantity' => 1, 'status' => 'inactive']);
    ws03Product($store, ['name' => 'E-book', 'quantity' => null, 'is_digital' => true]);

    $response = $this->withToken(ws03Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/dashboard')
        ->assertOk()
        ->assertJsonPath('data.low_stock.threshold', 10)
        ->assertJsonPath('data.low_stock.out_of_stock_count', 0);

    // Lowest quantity first, so the person restocking sees the urgent rows.
    expect(array_column($response->json('data.low_stock.items'), 'name'))->toBe(['Two Left', 'Four Left']);
});

test('the store payload carries the fields the legacy detail header rendered', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws03Store($owner, $business, [
        'name' => 'Lekki Flagship',
        'has_website' => true,
        'slug' => 'lekki-flagship',
        'description' => 'Our flagship store.',
        'support_email' => 'hello@lekki.test',
        'support_phone' => '08030000000',
        'address' => '1 Admiralty Way',
        'physical_address' => '1 Admiralty Way, Lekki',
        'instagram_url' => 'https://instagram.com/lekki',
    ]);

    Category::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Shoes',
        // categories.slug is NOT NULL with no model default, so it is set here.
        'slug' => 'shoes',
        'status' => 'active',
    ]);

    $this->withToken(ws03Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/dashboard')
        ->assertOk()
        ->assertJsonPath('data.store.name', 'Lekki Flagship')
        ->assertJsonPath('data.store.description', 'Our flagship store.')
        ->assertJsonPath('data.store.support_email', 'hello@lekki.test')
        ->assertJsonPath('data.store.support_phone', '08030000000')
        ->assertJsonPath('data.store.physical_address', '1 Admiralty Way, Lekki')
        ->assertJsonPath('data.store.socials.instagram', 'https://instagram.com/lekki')
        ->assertJsonPath('data.store.categories_count', 1)
        ->assertJsonPath('data.store.store_url', 'https://lekki-flagship.'.config('app.main_domain', 'storify.ng'));
});

test('a store dashboard belonging to another business is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = ws03Store($otherOwner, $otherBusiness, ['name' => 'Theirs']);

    $this->withToken(ws03Token($owner))
        ->getJson('/api/v1/management/stores/'.$theirs->store_id.'/dashboard')
        ->assertStatus(403);
});

test('a deleted store dashboard is refused', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws03Store($owner, $business, ['status' => Store::STATUS_DELETED]);

    $this->withToken(ws03Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/dashboard')
        ->assertStatus(403);
});

test('an unknown store id is not found', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws03Token($owner))
        ->getJson('/api/v1/management/stores/st_0000000000/dashboard')
        ->assertStatus(404);
});

test('a restricted staff member only reaches assigned stores', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $assigned = ws03Store($owner, $business, ['name' => 'Assigned']);
    $unassigned = ws03Store($owner, $business, ['name' => 'Unassigned']);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    // Store Manager sees stores but not transactions, so the user is both
    // permitted (stores view) and restricted to assigned stores.
    (new SpatiePermissionSeeder)->run();
    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Manager');
    $staff->assignedStores()->attach($assigned->id);

    $token = ws03Token($staff);

    $this->withToken($token)
        ->getJson('/api/v1/management/stores/'.$assigned->store_id.'/dashboard')
        ->assertOk();

    $this->withToken($token)
        ->getJson('/api/v1/management/stores/'.$unassigned->store_id.'/dashboard')
        ->assertStatus(403);
});

test('a staff member without stores view is refused', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws03Store($owner, $business);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    (new SpatiePermissionSeeder)->run();
    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');

    $this->withToken(ws03Token($staff))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/dashboard')
        ->assertStatus(403);
});

test('invoice payments count towards store revenue', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws03Store($owner, $business);

    $invoice = Invoice::create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'store_id' => $store->id,
        'recipient_name' => 'Chidi Okeke',
        'recipient_email' => 'chidi@example.test',
        'status' => 'paid',
        'issue_date' => now()->toDateString(),
        // invoices.due_date is NOT NULL; the invoice controller defaults it to
        // two weeks out, so the fixture does the same.
        'due_date' => now()->addDays(14)->toDateString(),
        'subtotal' => 4000,
        'total' => 4000,
    ]);

    Transaction::create([
        'reference' => 'WS03-INV-TXN-1',
        'invoice_id' => $invoice->id,
        'business_id' => $business->id,
        'amount' => 4000,
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ]);

    $this->withToken(ws03Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/dashboard')
        ->assertOk()
        ->assertJsonPath('data.stats.revenue.this_month', 4000);
});

test('the pos and web cards report their live state', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws03Store($owner, $business, ['pos_enabled' => true, 'has_website' => true, 'slug' => 'card-store', 'views' => 42]);

    PosSession::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'staff_id' => $owner->id,
        'opening_balance' => 5000,
        'status' => PosSession::STATUS_OPEN,
    ]);

    $this->withToken(ws03Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/dashboard')
        ->assertOk()
        ->assertJsonPath('data.pos.enabled', true)
        ->assertJsonPath('data.pos.active_session.opened_by', $owner->name)
        ->assertJsonPath('data.pos.active_session.opening_balance', 5000)
        ->assertJsonPath('data.web.has_website', true)
        ->assertJsonPath('data.web.url', 'https://card-store.'.config('app.main_domain', 'storify.ng'))
        ->assertJsonPath('data.web.views', 42);
});

test('recent sales are capped at eight and newest first', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws03Store($owner, $business);

    // created_at is not mass-assignable, so step the clock between inserts to
    // give each order a distinct timestamp.
    foreach (range(1, 10) as $index) {
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00')->addMinutes($index));
        ws03Order($store, ['order_number' => 'WS03-RECENT-'.$index]);
    }

    $response = $this->withToken(ws03Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/dashboard')
        ->assertOk();

    expect($response->json('data.recent_orders'))->toHaveCount(8)
        ->and($response->json('data.recent_orders.0.order_number'))->toBe('WS03-RECENT-10')
        ->and($response->json('data.recent_orders.0.customer'))->toBe('Walk-in');
});
