<?php

use App\Enums\TransactionStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-19 — Customers module parity
|--------------------------------------------------------------------------
| Covers the list filters/stats, account_id + full-name search, the detail
| read model, edit status/verification semantics, required-and-stored
| suspension reasons, activation, restricted-staff scoping, the store
| customers tab and cross-tenant refusal.
*/

function ws19Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws19Store(User $owner, $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS19 Store',
        'slug' => 'ws19-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws19Customer(int $businessId, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $businessId,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ws19-'.Str::lower(Str::random(10)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
        'email_verified_at' => now(),
    ], $attributes));
}

function ws19Order(Store $store, ?Customer $customer, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'customer_id' => $customer?->id,
        'source' => 'checkout',
        'order_number' => 'WS19-ORD-'.$sequence,
        'subtotal' => 1000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 1000,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

function ws19Transaction(Order $order, float $amount, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'WS19-TXN-'.$sequence,
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => $amount,
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ], $attributes));
}

function ws19SalesStaff($business, Store $store): User
{
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
        'name' => 'WS19 Associate',
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');
    $staff->assignedStores()->attach($store->id);

    return $staff;
}

test('the customer list returns rows and the four stat cards scoped to the business', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws19Store($owner, $business);
    $buyer = ws19Customer($business->id, ['first_name' => 'Ada', 'last_name' => 'Obi']);
    $quiet = ws19Customer($business->id, ['first_name' => 'Bola', 'last_name' => 'Ade', 'status' => Customer::STATUS_SUSPENDED]);
    ws19Customer($otherBusiness->id, ['first_name' => 'Theirs', 'last_name' => 'Only']);

    ws19Order($store, $buyer, ['status' => 'completed']);

    $response = $this->withToken(ws19Token($owner))->getJson('/api/v1/management/customers');

    $response->assertOk()
        ->assertJsonPath('data.stats.total', 2)
        ->assertJsonPath('data.stats.active', 1)
        ->assertJsonPath('data.stats.suspended', 1)
        ->assertJsonPath('data.stats.total_orders', 1);

    expect(array_column($response->json('data.customers'), 'name'))->not->toContain('Theirs Only')
        ->and(array_column($response->json('data.customers'), 'account_id'))->toContain($quiet->account_id);
});

test('the customer list searches account_id and the composed full name', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $customer = ws19Customer($business->id, ['first_name' => 'John', 'last_name' => 'Smith']);
    ws19Customer($business->id, ['first_name' => 'Jane', 'last_name' => 'Doe']);

    $this->withToken(ws19Token($owner))
        ->getJson('/api/v1/management/customers?q='.$customer->account_id)
        ->assertOk()
        ->assertJsonCount(1, 'data.customers')
        ->assertJsonPath('data.customers.0.account_id', $customer->account_id);

    // The base search dropped legacy's CONCAT matching, breaking "John Smith".
    $this->withToken(ws19Token($owner))
        ->getJson('/api/v1/management/customers?q='.urlencode('John Smith'))
        ->assertOk()
        ->assertJsonCount(1, 'data.customers')
        ->assertJsonPath('data.customers.0.name', 'John Smith');
});

test('the customer list filters by country and store', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $lekki = ws19Store($owner, $business, ['name' => 'Lekki']);
    $ikeja = ws19Store($owner, $business, ['name' => 'Ikeja']);

    $ghana = ws19Customer($business->id, ['first_name' => 'Ama', 'country' => 'Ghana']);
    $nigeria = ws19Customer($business->id, ['first_name' => 'Chidi', 'country' => 'Nigeria']);

    ws19Order($lekki, $ghana);
    ws19Order($ikeja, $nigeria);

    $this->withToken(ws19Token($owner))
        ->getJson('/api/v1/management/customers?store_id='.$lekki->id)
        ->assertOk()
        ->assertJsonCount(1, 'data.customers')
        ->assertJsonPath('data.customers.0.name', 'Ama Obi');

    $this->withToken(ws19Token($owner))
        ->getJson('/api/v1/management/customers?country=Nigeria')
        ->assertOk()
        ->assertJsonCount(1, 'data.customers')
        ->assertJsonPath('data.customers.0.name', 'Chidi Obi');

    // The country lookup powers the filter dropdown.
    $this->withToken(ws19Token($owner))
        ->getJson('/api/v1/management/customers/countries')
        ->assertOk()
        ->assertJsonPath('data.countries', ['Ghana', 'Nigeria']);
});

test('the country filter also matches a delivery route country', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws19Store($owner, $business);
    $route = DeliveryRoute::create([
        'store_id' => $store->id,
        'country' => 'Kenya',
        'state' => 'Nairobi',
        'area' => 'Westlands',
        'fee' => 50000,
        'delivery_days' => 2,
    ]);

    $customer = ws19Customer($business->id, ['first_name' => 'Wanjiru', 'country' => null]);
    DeliveryAddress::create([
        'customer_id' => $customer->id,
        'recipient_name' => $customer->full_name,
        'recipient_phone' => $customer->phone,
        'street_address' => '12 Riverside',
        'country' => 'Kenya',
        'delivery_route_id' => $route->id,
    ]);

    $this->withToken(ws19Token($owner))
        ->getJson('/api/v1/management/customers?country=Kenya')
        ->assertOk()
        ->assertJsonCount(1, 'data.customers')
        ->assertJsonPath('data.customers.0.name', 'Wanjiru Obi');
});

test('the customer detail returns pending count, address, transactions and activity', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws19Store($owner, $business);
    $customer = ws19Customer($business->id, [
        'street_address' => '5 Adeniyi Jones',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
    ]);

    ws19Order($store, $customer, ['status' => 'pending', 'total' => 1000]);
    $paid = ws19Order($store, $customer, ['status' => 'completed', 'total' => 2500]);
    ws19Order($store, $customer, ['status' => 'completed', 'total' => 1500]);

    // The 2026_07_12_150444 migration already seeds bank_transfer, so this
    // fixture must not insert a second row with the same unique code.
    PaymentMethod::firstOrCreate(['code' => 'bank_transfer'], ['name' => 'Bank Transfer']);
    ws19Transaction($paid, 2500);

    ActivityLog::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'action' => 'customer_suspended',
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
        'description' => 'Suspended customer',
        'new_values' => ['reason' => 'Chargebacks'],
    ]);

    $response = $this->withToken(ws19Token($owner))
        ->getJson('/api/v1/management/customers/'.$customer->account_id);

    $response->assertOk()
        ->assertJsonPath('data.stats.total_orders', 3)
        ->assertJsonPath('data.stats.completed_orders', 2)
        ->assertJsonPath('data.stats.pending_orders', 1)
        ->assertJsonPath('data.stats.total_spent', 4000)
        ->assertJsonPath('data.stats.spend_basis', 'completed orders')
        ->assertJsonPath('data.customer.address.city', 'Lagos')
        ->assertJsonPath('data.customer.email_verified', true);

    expect($response->json('data.recent_orders'))->toHaveCount(3)
        ->and($response->json('data.transactions'))->toHaveCount(1)
        ->and($response->json('data.activity'))->toHaveCount(1)
        ->and($response->json('data.activity.0.description'))->toBe('Suspended customer');
});

test('a customer from another business is not reachable or editable', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = ws19Customer($otherBusiness->id);

    $this->withToken(ws19Token($owner))
        ->getJson('/api/v1/management/customers/'.$theirs->account_id)
        ->assertStatus(403);

    $this->withToken(ws19Token($owner))
        ->putJson('/api/v1/management/customers/'.$theirs->account_id, [
            'first_name' => 'Hacked',
            'last_name' => 'Account',
            'email' => 'hacked@example.test',
            'status' => 'suspended',
        ])
        ->assertStatus(403);
});

test('editing a customer to active verifies the email and any other status clears it', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $customer = ws19Customer($business->id, ['email_verified_at' => null]);

    $payload = [
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => $customer->email,
        'phone' => '08099998888',
        'location' => 'Abuja',
        'status' => 'active',
    ];

    $this->withToken(ws19Token($owner))
        ->putJson('/api/v1/management/customers/'.$customer->account_id, $payload)
        ->assertOk()
        ->assertJsonPath('data.customer.status', 'active')
        ->assertJsonPath('data.customer.email_verified', true)
        ->assertJsonPath('data.customer.location', 'Abuja');

    expect($customer->fresh()->email_verified_at)->not->toBeNull();

    $this->withToken(ws19Token($owner))
        ->putJson('/api/v1/management/customers/'.$customer->account_id, [...$payload, 'status' => 'deleted'])
        ->assertOk()
        ->assertJsonPath('data.customer.status', 'deleted');

    expect($customer->fresh()->email_verified_at)->toBeNull();

    // The edit is audited with the real actor.
    $log = ActivityLog::where('subject_type', Customer::class)
        ->where('subject_id', $customer->id)
        ->where('action', 'customer_updated')
        ->latest()
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($owner->id);
});

test('the edit form requires the legacy fields and scopes email uniqueness to the business', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws19Customer($otherBusiness->id, ['email' => 'shared@example.test']);
    $customer = ws19Customer($business->id, ['email' => 'mine@example.test']);

    $base = [
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'mine@example.test',
        'status' => 'active',
    ];

    $this->withToken(ws19Token($owner))
        ->putJson('/api/v1/management/customers/'.$customer->account_id, [...$base, 'first_name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('first_name');

    // Emails are unique per business in the schema, so another business's
    // identical email must not fail validation.
    $this->withToken(ws19Token($owner))
        ->putJson('/api/v1/management/customers/'.$customer->account_id, [...$base, 'email' => 'shared@example.test'])
        ->assertOk();

    $sameBusiness = ws19Customer($business->id, ['email' => 'taken@example.test']);

    $this->withToken(ws19Token($owner))
        ->putJson('/api/v1/management/customers/'.$customer->account_id, [...$base, 'email' => 'taken@example.test'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('suspend requires a reason and stores it on the activity timeline', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $customer = ws19Customer($business->id);
    $token = ws19Token($owner);

    $this->withToken($token)
        ->postJson('/api/v1/management/customers/'.$customer->account_id.'/suspend')
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $this->withToken($token)
        ->postJson('/api/v1/management/customers/'.$customer->account_id.'/suspend', ['reason' => 'Repeated chargebacks'])
        ->assertOk()
        ->assertJsonPath('data.customer.status', 'suspended')
        ->assertJsonPath('data.customer.email_verified', false);

    expect($customer->fresh()->status)->toBe(Customer::STATUS_SUSPENDED)
        ->and($customer->fresh()->email_verified_at)->toBeNull();

    // The audit trail answers "why", and names the real actor — legacy put a
    // null user_id in the column and the actor in metadata.
    $log = ActivityLog::where('subject_type', Customer::class)
        ->where('subject_id', $customer->id)
        ->where('action', 'customer_suspended')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($owner->id)
        ->and($log->business_id)->toBe($business->id)
        ->and($log->new_values['reason'])->toBe('Repeated chargebacks')
        ->and($log->description)->toContain('Repeated chargebacks');

    // A second suspension is refused instead of writing twice.
    $this->withToken($token)
        ->postJson('/api/v1/management/customers/'.$customer->account_id.'/suspend', ['reason' => 'Again'])
        ->assertStatus(422);
});

test('a suspended customer can be activated and an active one is refused', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $customer = ws19Customer($business->id, [
        'status' => Customer::STATUS_SUSPENDED,
        'email_verified_at' => null,
    ]);
    $token = ws19Token($owner);

    $this->withToken($token)
        ->postJson('/api/v1/management/customers/'.$customer->account_id.'/activate')
        ->assertOk()
        ->assertJsonPath('data.customer.status', 'active')
        ->assertJsonPath('data.customer.email_verified', true);

    expect($customer->fresh()->email_verified_at)->not->toBeNull();

    $log = ActivityLog::where('subject_type', Customer::class)
        ->where('subject_id', $customer->id)
        ->where('action', 'customer_activated')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($owner->id);

    $this->withToken($token)
        ->postJson('/api/v1/management/customers/'.$customer->account_id.'/activate')
        ->assertStatus(422);
});

test('suspending another business customer is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = ws19Customer($otherBusiness->id);

    $this->withToken(ws19Token($owner))
        ->postJson('/api/v1/management/customers/'.$theirs->account_id.'/suspend', ['reason' => 'Nope'])
        ->assertStatus(403);

    expect($theirs->fresh()->status)->toBe(Customer::STATUS_ACTIVE);
});

test('restricted staff only see customers with orders in their assigned stores', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $assigned = ws19Store($owner, $business, ['name' => 'Assigned']);
    $unassigned = ws19Store($owner, $business, ['name' => 'Unassigned']);

    $mine = ws19Customer($business->id, ['first_name' => 'Mine']);
    $hidden = ws19Customer($business->id, ['first_name' => 'Hidden']);

    ws19Order($assigned, $mine);
    ws19Order($assigned, $mine);
    ws19Order($unassigned, $mine);
    ws19Order($unassigned, $hidden);

    $staff = ws19SalesStaff($business, $assigned);
    $token = ws19Token($staff);

    $response = $this->withToken($token)->getJson('/api/v1/management/customers');

    $response->assertOk()
        ->assertJsonCount(1, 'data.customers')
        ->assertJsonPath('data.customers.0.name', 'Mine Obi')
        // Counts are scoped to the stores the staff member can reach, so the
        // row agrees with the orders they can actually open.
        ->assertJsonPath('data.customers.0.orders_count', 2)
        ->assertJsonPath('data.stats.total', 1)
        ->assertJsonPath('data.stats.total_orders', 2);

    $this->withToken($token)
        ->getJson('/api/v1/management/customers/'.$hidden->account_id)
        ->assertStatus(403);

    // The owner still sees the whole business.
    $this->withToken(ws19Token($owner))
        ->getJson('/api/v1/management/customers')
        ->assertOk()
        ->assertJsonCount(2, 'data.customers');
});

test('a restricted staff member cannot filter by a store they are not assigned', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $assigned = ws19Store($owner, $business, ['name' => 'Assigned']);
    $unassigned = ws19Store($owner, $business, ['name' => 'Unassigned']);

    $staff = ws19SalesStaff($business, $assigned);

    $this->withToken(ws19Token($staff))
        ->getJson('/api/v1/management/customers?store_id='.$unassigned->id)
        ->assertStatus(403);
});

test('the store customers tab lists distinct buyers with per-store counts', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws19Store($owner, $business, ['name' => 'Lekki']);
    $other = ws19Store($owner, $business, ['name' => 'Ikeja']);

    $buyer = ws19Customer($business->id, ['first_name' => 'Ama']);
    $elsewhere = ws19Customer($business->id, ['first_name' => 'Chidi']);

    ws19Order($store, $buyer);
    ws19Order($store, $buyer);
    ws19Order($other, $elsewhere);

    $response = $this->withToken(ws19Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/customers');

    $response->assertOk()
        ->assertJsonPath('data.store.name', 'Lekki')
        ->assertJsonPath('data.customers_count', 1)
        ->assertJsonCount(1, 'data.customers')
        ->assertJsonPath('data.customers.0.name', 'Ama Obi')
        ->assertJsonPath('data.customers.0.orders_count', 2);
});

test('the store customers tab refuses a foreign store', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = ws19Store($otherOwner, $otherBusiness);

    $this->withToken(ws19Token($owner))
        ->getJson('/api/v1/management/stores/'.$theirs->store_id.'/customers')
        ->assertStatus(403);
});

test('global search matches customers by account_id and composed name and is permission gated', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $customer = ws19Customer($business->id, ['first_name' => 'John', 'last_name' => 'Smith']);
    ws19Customer($otherBusiness->id, ['first_name' => 'John', 'last_name' => 'Smith']);

    $this->withToken(ws19Token($owner))
        ->getJson('/api/v1/management/search?q='.urlencode('John Smith'))
        ->assertOk()
        ->assertJsonCount(1, 'data.customers')
        ->assertJsonPath('data.customers.0.account_id', $customer->account_id);

    $this->withToken(ws19Token($owner))
        ->getJson('/api/v1/management/search?q='.$customer->account_id)
        ->assertOk()
        ->assertJsonCount(1, 'data.customers');

    // A signed-in staff member without `customers view` gets no customer hits.
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    $this->withToken(ws19Token($staff))
        ->getJson('/api/v1/management/search?q='.urlencode('John Smith'))
        ->assertOk()
        ->assertJsonCount(0, 'data.customers');
});

test('the customer module requires the legacy permissions', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $customer = ws19Customer($business->id);

    // A staff member with no role holds no customer permissions at all.
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);
    $token = ws19Token($staff);

    $this->withToken($token)->getJson('/api/v1/management/customers')->assertStatus(403);
    $this->withToken($token)->getJson('/api/v1/management/customers/'.$customer->account_id)->assertStatus(403);
});
