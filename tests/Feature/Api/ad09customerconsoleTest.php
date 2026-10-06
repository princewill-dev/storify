<?php

use App\Enums\TransactionStatus;
use App\Mail\CustomerAccountActivatedMail;
use App\Mail\CustomerAccountSuspendedMail;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-9 — Platform customer console (admin console)
|--------------------------------------------------------------------------
| Covers the platform-wide directory (search across all four legacy fields,
| status + country filters, whitelisted sort, stats cards), the detail read
| model (stats, info, address, last-10 orders/transactions, activity), the
| edit with its status/verification semantics and per-business e-mail
| uniqueness, suspend/activate with required reason, audit rows, queued
| e-mails, and the platform-role/audience/permission refusals.
*/

function ad09AdminToken(User $admin): string
{
    return $admin->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad09SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad09PlatformAdmin(): User
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

function ad09Store(User $owner, $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'AD09 Store',
        'slug' => 'ad09-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ad09Customer(int $businessId, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $businessId,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ad09-'.Str::lower(Str::random(10)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
        'email_verified_at' => now(),
    ], $attributes));
}

function ad09Order(Store $store, ?Customer $customer, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'customer_id' => $customer?->id,
        'source' => 'checkout',
        'order_number' => 'AD09-ORD-'.$sequence,
        'subtotal' => 1000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 1000,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

function ad09Item(Order $order, array $attributes = []): OrderItem
{
    return OrderItem::create(array_merge([
        'order_id' => $order->id,
        'product_name' => 'AD09 Widget',
        'product_code' => 'AD09-SKU',
        'unit_price' => 1000,
        'quantity' => 1,
        'subtotal' => 1000,
        'is_digital' => false,
    ], $attributes));
}

function ad09Transaction(Order $order, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'AD09-TXN-'.$sequence,
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => 1000,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ], $attributes));
}

test('the customer directory spans every business with stats, search and filters', function () {
    $admin = ad09SuperAdmin();
    [$ownerA, $businessA] = createBusinessOwner();
    [$ownerB, $businessB] = createBusinessOwner();

    $storeA = ad09Store($ownerA, $businessA, ['name' => 'Alpha Store']);
    $storeB = ad09Store($ownerB, $businessB, ['name' => 'Beta Store']);

    $ada = ad09Customer($businessA->id, [
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ada.obi@example.test',
        'phone' => '08012345678',
        'account_id' => 'cus_ADA00001',
        'country' => 'Nigeria',
    ]);
    $bola = ad09Customer($businessB->id, [
        'first_name' => 'Bola',
        'last_name' => 'Ade',
        'email' => 'bola.ade@example.test',
        'phone' => '08087654321',
        'status' => Customer::STATUS_SUSPENDED,
        'email_verified_at' => null,
        'country' => 'Ghana',
    ]);

    ad09Order($storeA, $ada, ['status' => 'completed']);
    ad09Order($storeA, $ada, ['status' => 'completed']);
    ad09Order($storeB, $bola);

    $token = ad09AdminToken($admin);

    $response = $this->getJson('/api/v1/admin/customers', ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.stats.total', 2)
        ->assertJsonPath('meta.stats.active', 1)
        ->assertJsonPath('meta.stats.suspended', 1)
        ->assertJsonPath('meta.stats.orders', 3);

    $names = collect($response->json('data.customers'))->keyBy('account_id');

    expect($names)->toHaveCount(2)
        ->and($names['cus_ADA00001']['name'])->toBe('Ada Obi')
        ->and($names['cus_ADA00001']['email_verified'])->toBeTrue()
        ->and($names['cus_ADA00001']['orders_count'])->toBe(2)
        ->and($names['cus_ADA00001']['business'])->toBe($businessA->name)
        ->and($names['cus_ADA00001']['status'])->toBe('active')
        // Both businesses' customers appear — this is platform oversight,
        // not a tenant-scoped read.
        ->and($names[$bola->account_id]['business'])->toBe($businessB->name)
        ->and($names[$bola->account_id]['status'])->toBe('suspended')
        ->and($names[$bola->account_id]['email_verified'])->toBeFalse();

    $accounts = function (string $query) use ($token) {
        $response = $this->getJson('/api/v1/admin/customers?'.$query, ['Authorization' => 'Bearer '.$token]);
        $response->assertOk();

        return collect($response->json('data.customers'))->pluck('account_id')->all();
    };

    // Legacy's four search fields plus the composed full name.
    expect($accounts('q=Ada'))->toBe(['cus_ADA00001']);
    expect($accounts('q=Ada Obi'))->toBe(['cus_ADA00001']);
    expect($accounts('q=ada.obi@example.test'))->toBe(['cus_ADA00001']);
    expect($accounts('q=08087654321'))->toBe([$bola->account_id]);
    expect($accounts('q=cus_ADA00001'))->toBe(['cus_ADA00001']);
    expect($accounts('q=nobody'))->toBe([]);

    // Status accepts the lowercase API spelling and the uppercase schema one.
    expect($accounts('status=suspended'))->toBe([$bola->account_id]);
    expect($accounts('status=ACTIVE'))->toBe(['cus_ADA00001']);

    // Country filters on the customer's own address column...
    expect($accounts('country=Ghana'))->toBe([$bola->account_id]);
    // ...and on a delivery-route country (legacy's join).
    expect($accounts('country=Nigeria'))->toBe(['cus_ADA00001']);
});

test('the country filter also matches a delivery route country and the options are cached', function () {
    $admin = ad09SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad09Store($owner, $business);

    $route = DeliveryRoute::create([
        'store_id' => $store->id,
        'country' => 'Kenya',
        'state' => 'Nairobi',
        'area' => 'Westlands',
        'fee' => 50000,
        'delivery_days' => 2,
    ]);

    $customer = ad09Customer($business->id, ['first_name' => 'Wanjiru', 'country' => null]);
    DeliveryAddress::create([
        'customer_id' => $customer->id,
        'recipient_name' => $customer->full_name,
        // recipient_phone is NOT NULL in the schema.
        'recipient_phone' => $customer->phone,
        'street_address' => '12 Riverside',
        'country' => 'Kenya',
        'delivery_route_id' => $route->id,
    ]);

    ad09Customer($business->id, ['country' => 'Benin']);

    $token = ad09AdminToken($admin);

    $this->getJson('/api/v1/admin/customers?country=Kenya', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonCount(1, 'data.customers')
        ->assertJsonPath('data.customers.0.name', 'Wanjiru Obi');

    $options = $this->getJson('/api/v1/admin/customers/countries', ['Authorization' => 'Bearer '.$token]);

    $options->assertOk();
    expect($options->json('data.countries'))->toBe(['Benin', 'Kenya']);

    // A second call is served from the cache and still answers.
    $this->getJson('/api/v1/admin/customers/countries', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.countries.0', 'Benin');
});

test('the customer list sorting is whitelisted and every filter is validated', function () {
    $admin = ad09SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad09Store($owner, $business);

    $quiet = ad09Customer($business->id, ['first_name' => 'Zara']);
    ad09Order($store, $quiet);
    ad09Order($store, $quiet);
    ad09Order($store, $quiet);
    ad09Customer($business->id, ['first_name' => 'Aaron']);

    $token = ad09AdminToken($admin);

    $this->getJson('/api/v1/admin/customers?sort=first_name&direction=asc', ['Authorization' => 'Bearer '.$token])->assertOk();

    // orders_count is a whitelisted alias, so the busiest customer sorts first.
    $sorted = $this->getJson('/api/v1/admin/customers?sort=orders_count&direction=desc', ['Authorization' => 'Bearer '.$token]);
    $sorted->assertOk();
    expect($sorted->json('data.customers.0.first_name'))->toBe('Zara');

    $invalid = [
        'sort=id' => 'sort',
        'sort=created_at&direction=sideways' => 'direction',
        'status=bogus' => 'status',
        'per_page=500' => 'per_page',
        'per_page=0' => 'per_page',
        'country='.str_repeat('x', 101) => 'country',
        'q='.str_repeat('y', 101) => 'q',
    ];

    foreach ($invalid as $query => $field) {
        $this->getJson('/api/v1/admin/customers?'.$query, ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    // A numeric id where the account_id belongs is not a route at all (and
    // definitely not trusted as a lookup).
    $this->getJson('/api/v1/admin/customers/1', ['Authorization' => 'Bearer '.$token])->assertStatus(404);
    $this->getJson('/api/v1/admin/customers/cus_NOPE0000', ['Authorization' => 'Bearer '.$token])->assertStatus(404);
});

test('the customer detail returns stats, address, last-10 orders, transactions and activity', function () {
    $admin = ad09SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad09Store($owner, $business, ['name' => 'Detail Store']);

    $customer = ad09Customer($business->id, [
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'street_address' => '5 Adeniyi Jones',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
    ]);

    DeliveryAddress::create([
        'customer_id' => $customer->id,
        'recipient_name' => 'Ada Obi',
        'recipient_phone' => '08012345678',
        'street_address' => '9 Marina',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
        'is_default' => true,
    ]);

    // Twelve orders, with explicit timestamps so "latest" is deterministic on
    // a second-precision column, so the "last 10" limit is exercised.
    $paid = ad09Order($store, $customer, ['order_number' => 'AD09-PAID', 'status' => 'completed', 'total' => 2500]);
    ad09Item($paid);
    ad09Item($paid, ['product_name' => 'Second', 'subtotal' => 500]);
    $paid->forceFill(['created_at' => now()->subMinutes(1)])->save();

    // A completed order with no confirmed transaction must not count toward
    // the legacy "spent" tile.
    $notx = ad09Order($store, $customer, ['order_number' => 'AD09-NOTX', 'status' => 'completed', 'total' => 999]);
    $notx->forceFill(['created_at' => now()->subMinutes(2)])->save();

    $pending = ad09Order($store, $customer, ['order_number' => 'AD09-PENDING', 'status' => 'pending', 'total' => 700]);
    $pending->forceFill(['created_at' => now()->subMinutes(3)])->save();

    for ($i = 0; $i < 9; $i++) {
        $extra = ad09Order($store, $customer);
        $extra->forceFill(['created_at' => now()->subMinutes(10 + $i)])->save();
    }

    // The 2026_07_12_150444 migration already seeds bank_transfer, so this
    // fixture must not insert a second row with the same unique code.
    $method = PaymentMethod::firstOrCreate(['code' => 'bank_transfer'], ['name' => 'Bank Transfer']);

    $transactionPaid = ad09Transaction($paid, [
        'amount' => 2500,
        'status' => TransactionStatus::CONFIRMED,
        'reference' => 'AD09-TXN-PAID',
        'payment_method_id' => $method->id,
    ]);
    $transactionPaid->forceFill(['created_at' => now()->subMinutes(2)])->save();

    $transactionPending = ad09Transaction($pending, [
        'amount' => 200,
        'status' => TransactionStatus::PENDING,
        'reference' => 'AD09-TXN-PEND',
        'paid_at' => null,
        'payment_method_id' => $method->id,
    ]);
    $transactionPending->forceFill(['created_at' => now()->subMinutes(1)])->save();

    ActivityLog::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'action' => 'customer_suspended',
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
        'description' => 'Suspended customer: Ada Obi. Reason: chargeback',
    ]);

    $response = $this->getJson('/api/v1/admin/customers/'.$customer->account_id, ['Authorization' => 'Bearer '.ad09AdminToken($admin)]);

    $response->assertOk()
        ->assertJsonPath('data.customer.account_id', $customer->account_id)
        ->assertJsonPath('data.customer.name', 'Ada Obi')
        ->assertJsonPath('data.customer.address.street_address', '5 Adeniyi Jones')
        ->assertJsonPath('data.customer.address.country', 'Nigeria')
        ->assertJsonPath('data.customer.orders_count', 12)
        ->assertJsonPath('data.customer.default_delivery_address.street_address', '9 Marina')
        ->assertJsonPath('data.customer.default_delivery_address.is_default', true)
        ->assertJsonPath('data.stats.total_orders', 12)
        ->assertJsonPath('data.stats.completed_orders', 2)
        ->assertJsonPath('data.stats.pending_orders', 10)
        ->assertJsonPath('data.stats.total_spent', 2500)
        ->assertJsonPath('data.stats.spend_basis', 'orders with confirmed transactions')
        ->assertJsonPath('data.activity.0.action', 'customer_suspended')
        ->assertJsonPath('data.activity.0.user', $owner->name);

    expect($response->json('data.recent_orders'))->toHaveCount(10)
        // Newest first, and the two oldest orders fall off the ten.
        ->and($response->json('data.recent_orders.0.order_number'))->toBe('AD09-PAID')
        ->and(collect($response->json('data.recent_orders'))->pluck('order_number'))->toContain('AD09-NOTX')
        ->and($response->json('data.transactions'))->toHaveCount(2)
        ->and($response->json('data.transactions.0.reference'))->toBe('AD09-TXN-PEND')
        ->and($response->json('data.transactions.0.payment_method'))->toBe('Bank Transfer')
        ->and($response->json('data.transactions.1.order_number'))->toBe('AD09-PAID');

    $paidRow = collect($response->json('data.recent_orders'))->firstWhere('order_number', 'AD09-PAID');

    expect($paidRow['store'])->toBe('Detail Store')
        ->and($paidRow['items_count'])->toBe(2)
        ->and($paidRow['status_label'])->toBe('Completed');
});

test('a customer edit persists the fields, flips email verification and audits old to new', function () {
    $admin = ad09PlatformAdmin();
    [$owner, $business] = createBusinessOwner();
    [, $otherBusiness] = createBusinessOwner();

    $customer = ad09Customer($business->id, [
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ada@example.test',
        'phone' => '08010000000',
        'email_verified_at' => null,
    ]);

    // The same address may exist for another business (per-business unique).
    ad09Customer($otherBusiness->id, ['email' => 'ada@example.test']);
    ad09Customer($otherBusiness->id, ['email' => 'shared@example.test']);

    $token = ad09AdminToken($admin);

    // A partial update leaves the untouched fields (and verification) alone.
    $this->putJson('/api/v1/admin/customers/'.$customer->account_id, [
        'first_name' => 'Adaeze',
        'phone' => '08099999999',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.customer.first_name', 'Adaeze')
        ->assertJsonPath('data.customer.phone', '08099999999')
        ->assertJsonPath('data.customer.status', 'active');

    expect($customer->fresh()->email_verified_at)->toBeNull();

    // Setting Active verifies the e-mail.
    $this->putJson('/api/v1/admin/customers/'.$customer->account_id, [
        'status' => 'active',
    ], ['Authorization' => 'Bearer '.$token])->assertOk();

    expect($customer->fresh()->email_verified_at)->not->toBeNull();

    // Any other status clears it.
    $this->putJson('/api/v1/admin/customers/'.$customer->account_id, [
        'status' => 'SUSPENDED',
        'location' => 'Lagos',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.customer.status', 'suspended')
        ->assertJsonPath('data.customer.location', 'Lagos');

    expect($customer->fresh()->email_verified_at)->toBeNull();

    $log = ActivityLog::where('action', 'customer_updated')->where('subject_id', $customer->id)->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->business_id)->toBe($business->id)
        ->and($log->new_values['status'])->toBe('SUSPENDED');

    // Per-business uniqueness is enforced, other-business collisions are not.
    $this->putJson('/api/v1/admin/customers/'.$customer->account_id, [
        'email' => 'ADA@example.test',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.customer.email', 'ADA@example.test');

    $sameBusiness = ad09Customer($business->id, ['email' => 'taken@example.test']);

    $this->putJson('/api/v1/admin/customers/'.$sameBusiness->account_id, [
        'email' => 'ada@example.test',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $this->putJson('/api/v1/admin/customers/'.$customer->account_id, [
        'email' => 'taken@example.test',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    // The other business's copy of the address is not a collision.
    $this->putJson('/api/v1/admin/customers/'.$customer->account_id, [
        'email' => 'shared@example.test',
    ], ['Authorization' => 'Bearer '.$token])->assertOk();

    // Field-level validation failures.
    $this->putJson('/api/v1/admin/customers/'.$customer->account_id, [
        'first_name' => '',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('first_name');

    $this->putJson('/api/v1/admin/customers/'.$customer->account_id, [
        'email' => 'not-an-email',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $this->putJson('/api/v1/admin/customers/'.$customer->account_id, [
        'status' => 'teleported',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('suspending requires and stores a reason, clears verification, audits and emails the customer', function () {
    Mail::fake();

    $admin = ad09PlatformAdmin();
    [$owner, $business] = createBusinessOwner();
    $customer = ad09Customer($business->id);

    $token = ad09AdminToken($admin);

    $this->postJson('/api/v1/admin/customers/'.$customer->account_id.'/suspend', [], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $this->postJson('/api/v1/admin/customers/'.$customer->account_id.'/suspend', [
        'reason' => str_repeat('r', 501),
    ], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $this->postJson('/api/v1/admin/customers/'.$customer->account_id.'/suspend', [
        'reason' => 'Repeated chargebacks on confirmed orders.',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.customer.status', 'suspended')
        ->assertJsonPath('data.customer.email_verified', false);

    $customer->refresh();

    expect($customer->status)->toBe(Customer::STATUS_SUSPENDED)
        ->and($customer->email_verified_at)->toBeNull();

    Mail::assertQueued(CustomerAccountSuspendedMail::class, 1);

    $mail = Mail::queued(CustomerAccountSuspendedMail::class)->first();

    expect($mail->hasTo($customer->email))->toBeTrue()
        ->and($mail->reason)->toBe('Repeated chargebacks on confirmed orders.');

    $log = ActivityLog::where('action', 'customer_suspended')->where('subject_id', $customer->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->new_values['reason'])->toBe('Repeated chargebacks on confirmed orders.')
        ->and($log->old_values['status'])->toBe('ACTIVE');

    // Suspending an already-suspended customer is refused, not silently
    // repeated (legacy warned and did nothing; this answers 422).
    $this->postJson('/api/v1/admin/customers/'.$customer->account_id.'/suspend', [
        'reason' => 'Again',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This customer is already suspended.');

    Mail::assertQueued(CustomerAccountSuspendedMail::class, 1);
});

test('activating verifies the email, audits and emails the customer', function () {
    Mail::fake();

    $admin = ad09PlatformAdmin();
    [$owner, $business] = createBusinessOwner();
    $customer = ad09Customer($business->id, [
        'status' => Customer::STATUS_SUSPENDED,
        'email_verified_at' => null,
    ]);

    $token = ad09AdminToken($admin);

    $this->postJson('/api/v1/admin/customers/'.$customer->account_id.'/activate', [], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.customer.status', 'active')
        ->assertJsonPath('data.customer.email_verified', true);

    $customer->refresh();

    expect($customer->status)->toBe(Customer::STATUS_ACTIVE)
        ->and($customer->email_verified_at)->not->toBeNull();

    Mail::assertQueued(CustomerAccountActivatedMail::class, 1);

    $mail = Mail::queued(CustomerAccountActivatedMail::class)->first();

    expect($mail->hasTo($customer->email))->toBeTrue();

    $log = ActivityLog::where('action', 'customer_activated')->where('subject_id', $customer->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->old_values['status'])->toBe('SUSPENDED')
        ->and($log->new_values['status'])->toBe('ACTIVE');

    $this->postJson('/api/v1/admin/customers/'.$customer->account_id.'/activate', [], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This customer is already active.');

    Mail::assertQueued(CustomerAccountActivatedMail::class, 1);
});

test('the platform customer console refuses non-platform, unpermitted and guest callers', function () {
    // A business-scoped account whose in-business "Super Admin" role bundles
    // the admin.* permission names still cannot read platform customers.
    [$owner, $business] = createBusinessOwner();
    setPermissionsTeamId($business->id);

    expect($owner->can('admin.customers'))->toBeTrue();

    $ownerToken = $owner->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;

    $this->getJson('/api/v1/admin/customers', ['Authorization' => 'Bearer '.$ownerToken])->assertStatus(403);
    $this->getJson('/api/v1/admin/customers/countries', ['Authorization' => 'Bearer '.$ownerToken])->assertStatus(403);

    [$otherOwner] = createBusinessOwner();
    $managementToken = $otherOwner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;

    // Sanctum's guard caches the resolved user for the whole test, so it must
    // be forgotten before a request made as a different identity.
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/admin/customers', ['Authorization' => 'Bearer '.$managementToken])->assertStatus(403);

    // An admin account without the permission is stopped by the route gate.
    $plainAdmin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    // The guard still holds the previous identity; forget it so the plain
    // admin is genuinely resolved and stopped by the route gate.
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/admin/customers', ['Authorization' => 'Bearer '.ad09AdminToken($plainAdmin)])->assertStatus(403);

    // Guests are unauthenticated — without forgetting the guard first it
    // would still answer as the permissionless admin above (403, not 401).
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/admin/customers')->assertStatus(401);

    // A seeded platform admin and a superadmin pass.
    $platformAdmin = ad09PlatformAdmin();

    $this->getJson('/api/v1/admin/customers', ['Authorization' => 'Bearer '.ad09AdminToken($platformAdmin)])->assertOk();

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/admin/customers', ['Authorization' => 'Bearer '.ad09AdminToken(ad09SuperAdmin())])->assertOk();
});
