<?php

use App\Mail\CustomerMessageMail;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\CustomerMessage;
use App\Models\CustomerNote;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-56 — the Customers section, from the business's side
|--------------------------------------------------------------------------
| Covers the tabs (orders, payments, addresses, notes, messages, consents), the
| three writes that hang off a customer, the deletion queue, the CSV export and
| the erasure workflow.
|
| Two themes run through it. The first is that every one of these is a new way
| to reach a customer row that already had a tenant boundary on it, so every
| read and write is asserted across businesses. The second is that a message and
| an erasure are both irreversible in their own way — one leaves the platform,
| the other destroys a name — so consent is asserted as a filter rather than a
| courtesy, and erasure is asserted to keep the money.
*/

function ws56cToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws56cStore(User $owner, Business $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS56 Store',
        'slug' => 'ws56c-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws56cCustomer(int $businessId, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $businessId,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ws56c-'.Str::lower(Str::random(10)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
        'email_verified_at' => now(),
        'registered_at' => now(),
    ], $attributes));
}

function ws56cOrder(Store $store, Customer $customer, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'source' => 'checkout',
        'order_number' => 'WS56C-ORD-'.$sequence,
        'subtotal' => 5000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 5000,
        'amount_paid' => 0,
        'status' => 'completed',
    ], $attributes));
}

function ws56cTransaction(Order $order, float $amount): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create([
        'reference' => 'WS56C-TXN-'.$sequence,
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => $amount,
        'status' => 'confirmed',
        'paid_at' => now(),
    ]);
}

/**
 * A customer who agreed to hear about offers, as the newest consent row.
 */
function ws56cGrantMarketing(Customer $customer, bool $granted = true): void
{
    CustomerConsent::create([
        'business_id' => $customer->business_id,
        'customer_id' => $customer->id,
        'type' => 'marketing',
        'granted' => $granted,
        'source' => 'account',
    ]);
}

function ws56cAssociate(Business $business, Store $store): User
{
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
        'name' => 'WS56 Associate',
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');
    $staff->assignedStores()->attach($store->id);

    return $staff;
}

test('the customer tabs read the rows that belong to that customer', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws56cStore($owner, $business);

    $customer = ws56cCustomer($business->id);
    $order = ws56cOrder($store, $customer);
    $second = ws56cOrder($store, $customer, ['status' => 'pending']);
    ws56cTransaction($order, 5000);

    DeliveryAddress::create([
        'customer_id' => $customer->id,
        'label' => 'Home',
        'recipient_name' => 'Ada Obi',
        'recipient_phone' => '08011112222',
        'street_address' => '12 Marina',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'is_default' => true,
        'is_snapshot' => false,
    ]);

    // A per-order snapshot, which the Addresses tab deliberately does not list:
    // it is written once per order and belongs against the order, not here.
    DeliveryAddress::create([
        'customer_id' => $customer->id,
        'label' => 'Order delivery',
        'recipient_name' => 'Ada Obi',
        'recipient_phone' => '08011112222',
        'street_address' => '12 Marina',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'is_default' => false,
        'is_snapshot' => true,
    ]);

    // Another customer's rows, which must not appear in any of the tabs.
    $stranger = ws56cCustomer($business->id);
    $strangerOrder = ws56cOrder($store, $stranger);
    ws56cTransaction($strangerOrder, 9999);
    DeliveryAddress::create([
        'customer_id' => $stranger->id,
        'label' => 'Theirs',
        'recipient_name' => 'Bola Ade',
        'recipient_phone' => '08033333333',
        'street_address' => '1 Elsewhere',
        'city' => 'Abuja',
        'state' => 'FCT',
        'is_default' => true,
        'is_snapshot' => false,
    ]);

    $token = ws56cToken($owner);
    $base = "/api/v1/management/customers/{$customer->account_id}";

    $orders = $this->withToken($token)->getJson("{$base}/orders")->assertOk();
    expect($orders->json('data.orders'))->toHaveCount(2)
        ->and(collect($orders->json('data.orders'))->pluck('order_number')->all())
        ->toContain($order->order_number)->toContain($second->order_number);

    $payments = $this->withToken($token)->getJson("{$base}/payments")->assertOk();
    expect($payments->json('data.payments'))->toHaveCount(1)
        ->and((float) $payments->json('data.payments.0.amount'))->toBe(5000.0);

    $addresses = $this->withToken($token)->getJson("{$base}/addresses")->assertOk();
    expect($addresses->json('data.addresses'))->toHaveCount(1)
        ->and($addresses->json('data.addresses.0.street_address'))->toBe('12 Marina');

    $consents = $this->withToken($token)->getJson("{$base}/consents")->assertOk();
    expect($consents->json('data.consents'))->toBeArray();
});

test('every customer tab refuses a customer of another business', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws56cStore($owner, $business);

    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $theirs = ws56cCustomer($otherBusiness->id);

    $token = ws56cToken($owner);
    $base = "/api/v1/management/customers/{$theirs->account_id}";

    foreach (['orders', 'payments', 'addresses', 'notes', 'messages', 'consents'] as $tab) {
        $this->withToken($token)->getJson("{$base}/{$tab}")->assertStatus(403);
    }

    $this->withToken($token)->postJson("{$base}/notes", ['body' => 'A note'])->assertStatus(403);
    $this->withToken($token)->postJson("{$base}/message", [
        'subject' => 'Hello',
        'body' => 'Body',
        'category' => 'transactional',
    ])->assertStatus(403);
});

test('a note is written, listed and deleted by its own permissions', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws56cStore($owner, $business);
    $customer = ws56cCustomer($business->id);
    $token = ws56cToken($owner);
    $base = "/api/v1/management/customers/{$customer->account_id}";

    $created = $this->withToken($token)->postJson("{$base}/notes", ['body' => 'Called about the delayed order.'])
        ->assertCreated()
        ->json('data.note');

    expect($created['body'])->toBe('Called about the delayed order.')
        ->and($created['author'])->toBe($owner->name);

    expect($this->withToken($token)->getJson("{$base}/notes")->json('data.notes'))->toHaveCount(1);

    // A note id reached through another customer's URL is a 404, not a delete.
    $stranger = ws56cCustomer($business->id);
    $strangerNote = CustomerNote::create([
        'business_id' => $business->id,
        'customer_id' => $stranger->id,
        'user_id' => $owner->id,
        'body' => 'Their note',
    ]);

    $this->withToken($token)
        ->deleteJson("/api/v1/management/customers/{$customer->account_id}/notes/{$strangerNote->id}")
        ->assertStatus(404);

    expect(CustomerNote::find($strangerNote->id))->not->toBeNull();

    $this->withToken($token)->deleteJson("{$base}/notes/{$created['id']}")->assertOk();
    expect(CustomerNote::find($created['id']))->toBeNull();

    // The deletion is on the audit trail even though the note is gone.
    $this->assertDatabaseHas('activity_logs', [
        'business_id' => $business->id,
        'action' => 'customer_note_deleted',
    ]);

    // The role that helps customers can write a note but not export or erase.
    $associate = ws56cAssociate($business, $store);
    $associateToken = ws56cToken($associate);

    $this->withToken($associateToken)->getJson('/api/v1/management/customers/export')->assertStatus(403);
    $this->withToken($associateToken)->postJson("{$base}/erasure/approve", ['reason' => 'nope'])->assertStatus(403);
});

test('a marketing broadcast reaches only customers who agreed, but a transactional one does not ask', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws56cStore($owner, $business);

    $agreed = ws56cCustomer($business->id);
    ws56cGrantMarketing($agreed, true);

    $refused = ws56cCustomer($business->id);
    ws56cGrantMarketing($refused, false);

    // Never asked, therefore never opted in: no consent row at all.
    $neverAsked = ws56cCustomer($business->id);

    $suspended = ws56cCustomer($business->id, ['status' => Customer::STATUS_SUSPENDED]);
    ws56cGrantMarketing($suspended, true);

    $deleted = ws56cCustomer($business->id, ['status' => Customer::STATUS_DELETED]);
    ws56cGrantMarketing($deleted, true);

    $leaving = ws56cCustomer($business->id, ['deletion_requested_at' => now()]);
    ws56cGrantMarketing($leaving, true);

    $token = ws56cToken($owner);

    $marketing = $this->withToken($token)->postJson('/api/v1/management/customers/messages', [
        'subject' => 'Autumn offers',
        'body' => 'Ten percent off this week.',
        'category' => 'marketing',
    ])->assertCreated();

    expect($marketing->json('data.result.queued'))->toBe(1);

    expect(CustomerMessage::where('customer_id', $agreed->id)->count())->toBe(1)
        ->and(CustomerMessage::where('customer_id', $refused->id)->count())->toBe(0)
        ->and(CustomerMessage::where('customer_id', $neverAsked->id)->count())->toBe(0)
        // Suspended, erased and leaving are excluded whatever they agreed to:
        // one is a decision the business already made, one is a tombstone, and
        // one has asked to be forgotten and is waiting.
        ->and(CustomerMessage::where('customer_id', $suspended->id)->count())->toBe(0)
        ->and(CustomerMessage::where('customer_id', $deleted->id)->count())->toBe(0)
        ->and(CustomerMessage::where('customer_id', $leaving->id)->count())->toBe(0);

    Mail::assertQueued(CustomerMessageMail::class, 1);

    // A transactional message answers something the customer did, so a refusal
    // is not a gate on it.
    $transactional = $this->withToken($token)->postJson('/api/v1/management/customers/messages', [
        'subject' => 'Your order shipped',
        'body' => 'It is on its way.',
        'category' => 'transactional',
    ])->assertCreated();

    expect($transactional->json('data.result.queued'))->toBe(3);

    expect(CustomerMessage::where('customer_id', $refused->id)->count())->toBe(1)
        ->and(CustomerMessage::where('customer_id', $neverAsked->id)->count())->toBe(1)
        ->and(CustomerMessage::where('customer_id', $suspended->id)->count())->toBe(0);
});

test('a message to one customer is recorded and queued', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws56cStore($owner, $business);
    $customer = ws56cCustomer($business->id);

    $this->withToken(ws56cToken($owner))
        ->postJson("/api/v1/management/customers/{$customer->account_id}/message", [
            'subject' => 'About your order',
            'body' => 'We have refunded the delivery fee.',
            'category' => 'transactional',
        ])->assertCreated();

    expect(CustomerMessage::where('customer_id', $customer->id)->count())->toBe(1);

    Mail::assertQueued(CustomerMessageMail::class, 1);

    // The category is required: defaulting it would silently classify every
    // message as the kind that skips the consent check.
    $this->withToken(ws56cToken($owner))
        ->postJson("/api/v1/management/customers/{$customer->account_id}/message", [
            'subject' => 'No category',
            'body' => 'Body',
        ])->assertStatus(422);
});

test('the account state filter names exactly the rows it describes', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws56cStore($owner, $business);

    $verified = ws56cCustomer($business->id);
    $pending = ws56cCustomer($business->id, ['email_verified_at' => null, 'registered_at' => now(), 'last_login' => null]);
    $guest = ws56cCustomer($business->id, ['email_verified_at' => null, 'registered_at' => null, 'last_login' => null]);
    $suspended = ws56cCustomer($business->id, ['status' => Customer::STATUS_SUSPENDED]);
    $deleted = ws56cCustomer($business->id, ['status' => Customer::STATUS_DELETED]);

    $token = ws56cToken($owner);

    $expected = [
        'verified' => $verified->account_id,
        'pending' => $pending->account_id,
        'guest' => $guest->account_id,
        'suspended' => $suspended->account_id,
        'deleted' => $deleted->account_id,
    ];

    foreach ($expected as $state => $accountId) {
        $response = $this->withToken($token)
            ->getJson('/api/v1/management/customers?account_state='.$state.'&per_page=100')
            ->assertOk();

        $returned = collect($response->json('data.customers'))->pluck('account_id')->all();

        expect($returned)->toContain($accountId);

        // Exactly one: no other seeded state is caught by this filter. The five
        // states partition the book, so a filter matching two would mean the
        // SQL mirror and accountState() had drifted apart.
        $others = array_values(array_diff(array_keys($expected), [$state]));

        foreach ($others as $other) {
            expect($returned)->not->toContain($expected[$other]);
        }

        expect(collect($response->json('data.customers'))->pluck('account_state')->unique()->all())->toBe([$state]);
    }
});

test('the customer export carries only the acting business rows', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $mine = ws56cCustomer($business->id, ['first_name' => 'Ada', 'last_name' => 'Obi']);
    ws56cCustomer($business->id, ['first_name' => 'Bola', 'last_name' => 'Ade']);

    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $theirs = ws56cCustomer($otherBusiness->id, ['first_name' => 'Theirs', 'last_name' => 'Only']);

    $response = $this->withToken(ws56cToken($owner))->get('/api/v1/management/customers/export')->assertOk();

    $csv = $response->streamedContent();

    // The header row is the contract the columns are read under, so it is
    // asserted rather than assumed.
    expect($csv)->toContain('Account ID')
        ->and($csv)->toContain('Lifetime value')
        ->and($csv)->toContain($mine->account_id)
        ->and($csv)->toContain($mine->email)
        ->and($csv)->not->toContain($theirs->account_id)
        ->and($csv)->not->toContain($theirs->email);
});

test('restricted staff see only the orders of the stores they are assigned to', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $mine = ws56cStore($owner, $business, ['name' => 'Assigned']);
    $theirs = ws56cStore($owner, $business, ['name' => 'Unassigned']);

    $customer = ws56cCustomer($business->id);
    $visible = ws56cOrder($mine, $customer, ['status' => 'pending']);
    $hidden = ws56cOrder($theirs, $customer, ['status' => 'pending']);

    $associate = ws56cAssociate($business, $mine);

    $orders = $this->withToken(ws56cToken($associate))
        ->getJson("/api/v1/management/customers/{$customer->account_id}/orders")
        ->assertOk();

    $numbers = collect($orders->json('data.orders'))->pluck('order_number')->all();

    expect($numbers)->toContain($visible->order_number)
        ->and($numbers)->not->toContain($hidden->order_number);

    // A customer with no order in a store they can reach is out of scope
    // entirely, so even the tabs refuse them — the same boundary the detail
    // screen enforces.
    $strangerCustomer = ws56cCustomer($business->id);
    ws56cOrder($theirs, $strangerCustomer, ['status' => 'pending']);

    $this->withToken(ws56cToken($associate))
        ->getJson("/api/v1/management/customers/{$strangerCustomer->account_id}/orders")
        ->assertStatus(403);
});

test('the deletion queue lists unanswered requests oldest first', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws56cStore($owner, $business);

    $older = ws56cCustomer($business->id, ['deletion_requested_at' => now()->subDays(5)]);
    $newer = ws56cCustomer($business->id, ['deletion_requested_at' => now()->subDay()]);
    ws56cCustomer($business->id);

    $token = ws56cToken($owner);

    $queue = $this->withToken($token)->getJson('/api/v1/management/customers/deletion-requests')->assertOk();

    expect(collect($queue->json('data.requests'))->pluck('account_id')->all())
        ->toBe([$older->account_id, $newer->account_id]);

    // Declining clears the request and leaves the account standing.
    $this->withToken($token)
        ->postJson("/api/v1/management/customers/{$older->account_id}/erasure/reject", [
            'reason' => 'Open dispute on an unpaid order.',
        ])->assertOk();

    expect($older->fresh()->deletion_requested_at)->toBeNull()
        ->and($older->fresh()->status)->toBe(Customer::STATUS_ACTIVE);

    $this->assertDatabaseHas('activity_logs', [
        'business_id' => $business->id,
        'action' => 'customer_deletion_rejected',
    ]);

    // A reason is required: it is the only record of why, since no mail goes
    // out from a business-framed address yet.
    $this->withToken($token)
        ->postJson("/api/v1/management/customers/{$newer->account_id}/erasure/reject", [])
        ->assertStatus(422);
});

test('approving a deletion erases the person and keeps the money', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws56cStore($owner, $business);

    $customer = ws56cCustomer($business->id, [
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'deletion_requested_at' => now()->subDay(),
    ]);

    $first = ws56cOrder($store, $customer);
    $second = ws56cOrder($store, $customer);
    ws56cTransaction($first, 5000);

    DeliveryAddress::create([
        'customer_id' => $customer->id,
        'label' => 'Home',
        'recipient_name' => 'Ada Obi',
        'recipient_phone' => '08011112222',
        'street_address' => '12 Marina',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'is_default' => true,
        'is_snapshot' => false,
    ]);

    $originalEmail = $customer->email;
    $accountId = $customer->account_id;

    $this->withToken(ws56cToken($owner))
        ->postJson("/api/v1/management/customers/{$accountId}/erasure/approve", [
            'reason' => 'Customer confirmed the request by phone.',
        ])
        ->assertOk();

    $erased = Customer::find($customer->id);

    expect($erased)->not->toBeNull()
        ->and($erased->first_name)->toBe('Erased')
        ->and($erased->last_name)->toBe('Customer')
        ->and($erased->email)->toBe("erased+{$accountId}@storify.invalid")
        ->and($erased->email)->not->toBe($originalEmail)
        // Blank rather than null: the column is NOT NULL, and a fabricated
        // number would be somebody's real one.
        ->and($erased->phone)->toBe('')
        ->and($erased->status)->toBe(Customer::STATUS_DELETED)
        ->and($erased->email_verified_at)->toBeNull()
        // Nobody knows this password, so the row is unloggable even if some
        // future path forgets to check the status.
        ->and($erased->password)->not->toBeNull();

    // The address book is gone — both kinds, since both identify where a
    // person lives.
    expect(DeliveryAddress::where('customer_id', $customer->id)->count())->toBe(0);

    // The money is not. `orders.customer_id` cascades, so a delete would have
    // taken the financial records with it; this is the assertion that says the
    // erasure is an erasure.
    expect($erased->orders()->count())->toBe(2)
        ->and(Transaction::whereIn('order_id', [$first->id, $second->id])->count())->toBe(1)
        ->and($first->fresh()->customer_id)->toBe($customer->id);

    $this->assertDatabaseHas('activity_logs', [
        'business_id' => $business->id,
        'action' => 'customer_erased',
    ]);

    // Answered, so it leaves the queue — and cannot be actioned twice.
    $queue = $this->withToken(ws56cToken($owner))
        ->getJson('/api/v1/management/customers/deletion-requests')->assertOk();

    expect(collect($queue->json('data.requests'))->pluck('account_id')->all())->not->toContain($accountId);

    $this->withToken(ws56cToken($owner))
        ->postJson("/api/v1/management/customers/{$accountId}/erasure/approve", ['reason' => 'Again'])
        ->assertStatus(409);
});

test('a customer with no outstanding request cannot be erased', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws56cStore($owner, $business);
    $customer = ws56cCustomer($business->id);
    ws56cOrder($store, $customer);

    $this->withToken(ws56cToken($owner))
        ->postJson("/api/v1/management/customers/{$customer->account_id}/erasure/approve", [
            'reason' => 'No request exists for this one.',
        ])
        ->assertStatus(409);

    expect($customer->fresh()->first_name)->toBe('Ada')
        ->and($customer->fresh()->status)->toBe(Customer::STATUS_ACTIVE);
});
