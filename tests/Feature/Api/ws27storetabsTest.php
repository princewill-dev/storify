<?php

use App\Enums\InvoiceStatus;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Spatie\Permission\Models\Role;

function ws27Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws27Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Main Store',
        'slug' => 'ws27-'.random_int(1000, 9999),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws27Product(Store $store, array $attributes = []): Product
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

function ws27Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_number' => 'WS27-ORD-'.$sequence,
        'subtotal' => 1000,
        'total' => 1000,
        'amount_paid' => 1000,
        'status' => 'completed',
    ], $attributes));
}

/** A transaction may hang off an order or an invoice; both count for a store. */
function ws27Transaction(array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'WS27-TXN-'.$sequence,
        'amount' => 1000,
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ], $attributes));
}

function ws27Customer(int $businessId, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $businessId,
        'first_name' => 'Ada',
        'last_name' => 'Buyer',
        'email' => 'buyer-'.random_int(1000, 9999).'@example.test',
    ], $attributes));
}

function ws27Invoice(User $owner, Store $store, array $attributes = []): Invoice
{
    return Invoice::create(array_merge([
        'business_id' => $owner->business_id,
        'user_id' => $owner->id,
        'store_id' => $store->id,
        'recipient_name' => 'Ada Client',
        'recipient_email' => 'client@example.test',
        'status' => InvoiceStatus::SENT,
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(14)->toDateString(),
        'subtotal' => 1000,
        'tax_amount' => 0,
        'discount_value' => 0,
        'total' => 1000,
    ], $attributes));
}

/** A staff member whose role holds exactly the given permissions. */
function ws27StaffWith(User $owner, array $permissions, array $storeIds = []): User
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
        'name' => 'WS27 Role '.substr(md5(implode(',', $permissions)), 0, 8),
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

test('the store tab summary counts every tab scoped to the store', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws27Store($owner, ['name' => 'Lekki']);
    $other = ws27Store($owner, ['name' => 'Ikeja']);

    ws27Product($store);
    ws27Product($store, ['name' => 'Second Widget']);
    ws27Product($other, ['name' => 'Elsewhere Widget']);

    $buyer = ws27Customer($owner->business_id, ['first_name' => 'Ada']);
    $otherBuyer = ws27Customer($owner->business_id, ['first_name' => 'Bola']);
    $stranger = ws27Customer($owner->business_id, ['first_name' => 'Chidi']);

    $mine = ws27Order($store, ['customer_id' => $buyer->id]);
    $elsewhere = ws27Order($other, ['customer_id' => $otherBuyer->id]);
    ws27Order($store, ['customer_id' => $stranger->id, 'status' => 'pending']);

    // Order- and invoice-borne money both belong to the store; other stores'
    // transactions must not leak into the count.
    ws27Transaction(['order_id' => $mine->id, 'business_id' => $owner->business_id]);
    $invoice = ws27Invoice($owner, $store);
    ws27Transaction(['invoice_id' => $invoice->id, 'business_id' => $owner->business_id]);
    ws27Transaction(['order_id' => $elsewhere->id, 'business_id' => $owner->business_id]);

    ws27Invoice($owner, $other, ['recipient_name' => 'Theirs']);

    $assigned = ws27StaffWith($owner, ['staff view'], [$store->id]);
    ws27StaffWith($owner, ['staff view'], [$other->id]);

    // A deactivated account no longer shows in the directory (WS-20).
    $deleted = ws27StaffWith($owner, ['staff view'], [$store->id]);
    $deleted->update(['status' => 'deleted']);
    $assignedModel = $assigned->fresh();

    $response = $this->withToken(ws27Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/tabs')
        ->assertOk();

    $response
        ->assertJsonPath('data.store.name', 'Lekki')
        ->assertJsonPath('data.store.store_id', $store->store_id);

    $tabs = collect($response->json('data.tabs'))->keyBy('key');

    expect($tabs->keys()->all())->toBe(['products', 'sales', 'transactions', 'customers', 'invoices', 'staff'])
        ->and($tabs['products']['count'])->toBe(2)
        ->and($tabs['sales']['count'])->toBe(2)
        ->and($tabs['transactions']['count'])->toBe(2)
        ->and($tabs['customers']['count'])->toBe(2)
        ->and($tabs['invoices']['count'])->toBe(1)
        ->and($tabs['staff']['count'])->toBe(1)
        ->and($assignedModel->id)->not->toBe($deleted->id);
});

test('the tab summary hides counts for tabs the caller cannot open', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws27Store($owner);

    ws27Product($store);
    ws27Invoice($owner, $store);
    ws27Order($store);

    // A store-scoped role that may only open Products — the other five tabs
    // must come back disabled with a null count rather than leaking a number.
    $staff = ws27StaffWith($owner, ['stores view', 'products view'], [$store->id]);

    $response = $this->withToken(ws27Token($staff))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/tabs')
        ->assertOk();

    $tabs = collect($response->json('data.tabs'))->keyBy('key');

    expect($tabs['products']['enabled'])->toBeTrue()
        ->and($tabs['products']['count'])->toBe(1)
        ->and($tabs['sales']['enabled'])->toBeFalse()
        ->and($tabs['sales']['count'])->toBeNull()
        ->and($tabs['invoices']['count'])->toBeNull()
        ->and($tabs['staff']['count'])->toBeNull();
});

test('the tab summary refuses another business store', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $foreign = ws27Store($otherOwner, ['name' => 'Theirs', 'business_id' => $otherBusiness->id]);

    $this->withToken(ws27Token($owner))
        ->getJson('/api/v1/management/stores/'.$foreign->store_id.'/tabs')
        ->assertForbidden();
});

test('the tab summary refuses a deleted store', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws27Store($owner, ['status' => Store::STATUS_DELETED]);

    $this->withToken(ws27Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/tabs')
        ->assertForbidden();
});

test('the tab summary requires the stores view permission', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws27Store($owner);

    // Unauthenticated first: withToken() writes into the test's default
    // headers, so a request issued after it would still be authenticated and
    // answer 403 rather than 401.
    $this->getJson('/api/v1/management/stores/'.$store->store_id.'/tabs')
        ->assertUnauthorized();

    $staff = ws27StaffWith($owner, ['products view'], [$store->id]);

    $this->withToken(ws27Token($staff))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/tabs')
        ->assertForbidden();
});

test('the tab summary refuses a store the staff member is not assigned to', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws27Store($owner);
    $other = ws27Store($owner, ['name' => 'Ikeja']);

    $staff = ws27StaffWith($owner, ['stores view'], [$other->id]);

    $this->withToken(ws27Token($staff))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/tabs')
        ->assertForbidden();
});

test('the store-scoped lists behind the tabs never leak another store rows', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws27Store($owner, ['name' => 'Lekki']);
    $other = ws27Store($owner, ['name' => 'Ikeja']);

    $buyer = ws27Customer($owner->business_id, ['first_name' => 'Ada']);
    $otherBuyer = ws27Customer($owner->business_id, ['first_name' => 'Bola']);

    ws27Product($store, ['name' => 'Mine Widget']);
    ws27Product($other, ['name' => 'Theirs Widget']);

    $order = ws27Order($store, ['order_number' => 'WS27-MINE', 'customer_id' => $buyer->id]);
    ws27Order($other, ['order_number' => 'WS27-THEIRS', 'customer_id' => $otherBuyer->id]);

    ws27Transaction(['order_id' => $order->id, 'business_id' => $owner->business_id, 'reference' => 'WS27-MINE-TXN']);
    $invoice = ws27Invoice($owner, $store, ['recipient_name' => 'Mine Client']);
    ws27Invoice($owner, $other, ['recipient_name' => 'Theirs Client']);

    $assigned = ws27StaffWith($owner, ['staff view'], [$store->id]);
    ws27StaffWith($owner, ['staff view'], [$other->id]);

    $token = ws27Token($owner);

    // Products — the tab reads the same endpoint the catalog list uses.
    $products = $this->withToken($token)->getJson('/api/v1/management/products?store_id='.$store->id)->assertOk();
    expect(array_column($products->json('data'), 'name'))->toBe(['Mine Widget'])
        ->and(array_column($products->json('data'), 'store_id'))->toBe([$store->id]);

    // Sales — the orders board with the store filter pinned.
    $orders = $this->withToken($token)->getJson('/api/v1/management/orders/board/list?store_id='.$store->id)->assertOk();
    expect(array_column($orders->json('data.orders'), 'order_number'))->toBe(['WS27-MINE']);

    // Transactions — order- and invoice-borne payments, store-filtered.
    $transactions = $this->withToken($token)->getJson('/api/v1/management/transactions?store_id='.$store->id)->assertOk();
    expect(array_column($transactions->json('data.transactions'), 'reference'))->toBe(['WS27-MINE-TXN']);

    // Customers — buyers of this store, with a per-store order count.
    $customers = $this->withToken($token)
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/customers')
        ->assertOk();
    expect(array_column($customers->json('data.customers'), 'name'))->toBe(['Ada Buyer'])
        ->and($customers->json('data.customers_count'))->toBe(1)
        ->and($customers->json('data.customers.0.orders_count'))->toBe(1);

    // Invoices — store-filtered.
    $invoices = $this->withToken($token)->getJson('/api/v1/management/invoices?store_id='.$store->id)->assertOk();
    expect(array_column($invoices->json('data.invoices'), 'recipient_name'))->toBe(['Mine Client']);

    // Staff — the directory filtered to this store's assignees.
    $staff = $this->withToken($token)->getJson('/api/v1/management/staff?store_id='.$store->store_id)->assertOk();
    expect(array_column($staff->json('data'), 'id'))->toBe([$assigned->id]);
});

test('the sales tab rejects an invalid status and a foreign store id', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws27Store($owner);
    $foreign = ws27Store($otherOwner, ['business_id' => $otherBusiness->id]);

    $token = ws27Token($owner);

    $this->withToken($token)
        ->getJson('/api/v1/management/orders/board/list?store_id='.$store->id.'&status=teleported')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    // A store the caller cannot reach is refused outright, not answered with
    // an empty page (the legacy filter silently matched nothing).
    $this->withToken($token)
        ->getJson('/api/v1/management/orders/board/list?store_id='.$foreign->id)
        ->assertForbidden();
});
