<?php

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| RBAC — which staff roles reach which management surfaces
|--------------------------------------------------------------------------
| Ports the role matrix from the legacy tests/Feature/Management/RbacTest.php
| onto the API. The permission-level refusals already live in the workstream
| files (ws11 payment settings, ws18 transactions, ws20 staff); this file
| covers the role-specific instances the legacy suite asserted, the
| accountant and warehouse-manager card gating, and the sweep proving every
| seeded business role can open the dashboard.
|
| The SPA derives its sidebar from the permissions the routes enforce, so
| "a role reaches a surface" is tested at the endpoint, and the dashboard
| sections are tested on the widgets payload the SPA renders.
*/

function rbacToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function rbacStaff(Business $business, string $role): User
{
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole($role);

    return $staff;
}

function rbacStore(User $owner, Business $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'RBAC Store '.Str::upper(Str::random(4)),
        'slug' => 'rbac-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function rbacWarehouse(User $owner, array $attributes = []): Warehouse
{
    return Warehouse::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'RBAC Depot '.Str::upper(Str::random(4)),
        'status' => Warehouse::STATUS_ACTIVE,
    ], $attributes));
}

function rbacOrder(Store $store, array $attributes = []): Order
{
    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_number' => 'RBAC-ORD-'.Str::upper(Str::random(6)),
        'subtotal' => 2500,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 2500,
        'amount_paid' => 2500,
        'status' => OrderStatus::PENDING->value,
    ], $attributes));
}

test('every staff role can reach the management dashboard', function (string $role) {
    [, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = rbacStaff($business, $role);
    $token = rbacToken($staff);

    // The shell endpoint and the widgets payload the SPA dashboard renders
    // both open on `dashboard view`, which every seeded business role holds.
    $this->withToken($token)
        ->getJson('/api/v1/management/dashboard')
        ->assertOk()
        ->assertJsonStructure(['data' => ['stats']]);

    $this->withToken($token)
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk()
        ->assertJsonStructure(['data' => ['stats', 'low_stock']]);
})->with([
    'Cashier', 'Warehouse Manager', 'Store Manager', 'Accountant',
    'Inventory Clerk', 'Customer Support', 'Delivery Agent', 'Store Associate',
    'Auditor', 'Manager', 'Chief Financial Officer', 'Managing Director',
]);

test('a cashier dashboard shows the catalogue, customers and POS sections but not stock transfers', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = rbacStore($owner, $business, ['name' => 'Cashier Store', 'pos_enabled' => true]);

    Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Cashier Widget',
        'amount' => 1500,
        'quantity' => 20,
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'rbac-cashier-'.Str::lower(Str::random(8)).'@example.test',
        'phone' => '080'.random_int(10000000, 99999999),
        'password' => bcrypt('secret-pass-123'),
    ]);

    rbacOrder($store, ['customer_id' => $customer->id, 'order_number' => 'RBAC-CASHIER-1']);

    $cashier = rbacStaff($business, 'Cashier');
    $cashier->assignedStores()->attach($store->id);
    $token = rbacToken($cashier);

    // The sections the legacy sidebar showed a cashier...
    $this->withToken($token)
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk()
        ->assertJsonPath('data.stats.products.total', 1)
        ->assertJsonPath('data.stats.customers.total', 1)
        ->assertJsonPath('data.stats.orders.total', 1)
        ->assertJsonPath('data.stats.pos.active_stores', 1)
        // ...and the Stock Transfers section it did not.
        ->assertJsonMissingPath('data.transfer_list')
        ->assertJsonMissingPath('data.pending_transfer_count');

    // The surfaces follow the same permissions: catalogue and customers open,
    // transactions, transfers and payment settings refuse.
    $this->withToken($token)->getJson('/api/v1/management/products')->assertOk();
    $this->withToken($token)->getJson('/api/v1/management/customers')->assertOk();
    $this->withToken($token)->getJson('/api/v1/management/transactions')->assertStatus(403);
    $this->withToken($token)->getJson('/api/v1/management/transfers')->assertStatus(403);
    $this->withToken($token)->getJson('/api/v1/management/payment-settings')->assertStatus(403);
});

test('an accountant dashboard shows revenue and transactions but not the staff card', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = rbacStore($owner, $business);

    $order = rbacOrder($store, ['order_number' => 'RBAC-REVENUE-1']);

    Transaction::create([
        'reference' => 'RBAC-TXN-1',
        'order_id' => $order->id,
        'business_id' => $business->id,
        'amount' => 4000,
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ]);

    // A staff row exists, so the staff card would render if it were gated in.
    rbacStaff($business, 'Cashier');

    $accountant = rbacStaff($business, 'Accountant');
    $token = rbacToken($accountant);

    $response = $this->withToken($token)
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk();

    // Money is a kobo integer on the wire; assertJsonPath is a strict
    // identity check.
    $response
        ->assertJsonPath('data.stats.revenue.total', 4000)
        ->assertJsonPath('data.recent_transactions.0.reference', 'RBAC-TXN-1');

    expect($response->json('data.revenue_series'))->toBeArray();

    $response
        ->assertJsonMissingPath('data.stats.staff')
        ->assertJsonMissingPath('data.recent_staff');
});

test('a warehouse manager reaches warehouses but not payment settings or the staff invite', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $warehouse = rbacWarehouse($owner, ['name' => 'RBAC Main Depot']);

    $manager = rbacStaff($business, 'Warehouse Manager');
    $manager->assignedWarehouses()->attach($warehouse->id);
    $token = rbacToken($manager);

    // Warehouses are the manager's surface...
    $this->withToken($token)->getJson('/api/v1/management/warehouses')->assertOk();

    $this->withToken($token)
        ->getJson('/api/v1/management/dashboard/widgets')
        ->assertOk()
        ->assertJsonPath('data.stats.warehouses.total', 1)
        ->assertJsonStructure(['data' => ['warehouses']])
        // ...and the revenue card is not (no transactions view).
        ->assertJsonMissingPath('data.stats.revenue');

    // Payment settings and the staff invite are closed.
    $this->withToken($token)->getJson('/api/v1/management/payment-settings')->assertStatus(403);

    $this->withToken($token)->postJson('/api/v1/management/staff', [
        'name' => 'Not Allowed',
        'email' => 'rbac-not-allowed@example.test',
        'role' => 'Cashier',
    ])->assertStatus(403);

    expect(User::where('email', 'rbac-not-allowed@example.test')->exists())->toBeFalse();
});

test('a store associate can list products but cannot create one', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = rbacStore($owner, $business, ['name' => 'Associate Store']);

    $associate = rbacStaff($business, 'Store Associate');
    $associate->assignedStores()->attach($store->id);
    $token = rbacToken($associate);

    $this->withToken($token)->getJson('/api/v1/management/products')->assertOk();

    $this->withToken($token)->postJson('/api/v1/management/products', [
        'name' => 'Sneaky Widget',
        'store_id' => $store->id,
        'amount' => 1500,
        'quantity' => 3,
    ])->assertStatus(403);

    expect(Product::where('name', 'Sneaky Widget')->exists())->toBeFalse();
});
