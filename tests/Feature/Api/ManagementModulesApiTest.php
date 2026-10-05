<?php

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;

/**
 * @return array{0: User, 1: \App\Models\Business, 2: Store}
 */
function modulesContext(): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Modules Store',
        'slug' => 'modules-store',
        'status' => Store::STATUS_ACTIVE,
    ]);

    return [$owner, $business, $store];
}

test('products can be created, updated and deleted through the api', function () {
    [$owner, $business, $store] = modulesContext();
    $token = managementToken($owner);

    $created = $this->postJson('/api/v1/management/products', [
        'name' => 'API Widget',
        'store_id' => $store->id,
        'amount' => 2500,
        'quantity' => 10,
        'status' => 'active',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertCreated()
        ->assertJsonPath('data.product.name', 'API Widget');

    $productId = $created->json('data.product.id');

    $this->putJson('/api/v1/management/products/'.$created->json('data.product.product_code'), [
        'name' => 'API Widget Pro',
        'amount' => 3000,
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.product.name', 'API Widget Pro');

    $this->putJson('/api/v1/management/products/'.$created->json('data.product.product_code').'/status', [
        'status' => 'inactive',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.product.status', 'inactive');

    $this->deleteJson('/api/v1/management/products/'.$created->json('data.product.product_code'), [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk();

    expect(Product::find($productId))->toBeNull();
});

test('categories can be managed through the api', function () {
    [$owner, $business, $store] = modulesContext();
    $token = managementToken($owner);

    $created = $this->postJson('/api/v1/management/categories', [
        'name' => 'Fiction',
        'store_id' => $store->id,
    ], ['Authorization' => 'Bearer '.$token])
        ->assertCreated();

    $categoryId = $created->json('data.category.id');

    $this->getJson('/api/v1/management/categories?store_id='.$store->id, ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Fiction');

    $this->deleteJson('/api/v1/management/categories/'.$categoryId, [], ['Authorization' => 'Bearer '.$token])
        ->assertOk();
});

test('orders can be listed and their status updated', function () {
    [$owner, $business, $store] = modulesContext();
    $token = managementToken($owner);

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'source' => 'checkout',
        'order_number' => 'ORD-API-1',
        'subtotal' => 5000,
        'total' => 5000,
        'amount_paid' => 0,
        'status' => 'pending',
    ]);

    $this->getJson('/api/v1/management/orders', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.0.order_number', 'ORD-API-1');

    $this->putJson('/api/v1/management/orders/'.$order->order_number.'/status', [
        'status' => 'processing',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.order.status', 'processing');
});

test('customers can be updated, suspended and activated', function () {
    [$owner, $business, $store] = modulesContext();
    $token = managementToken($owner);

    $customer = Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Janet',
        'last_name' => 'Buyer',
        'email' => 'janet@example.test',
        'phone' => '08011110000',
        'password' => bcrypt('secret-pass-123'),
        'status' => 'ACTIVE',
    ]);

    $this->putJson('/api/v1/management/customers/'.$customer->account_id, [
        'first_name' => 'Janet',
        'last_name' => 'Shopper',
    ], ['Authorization' => 'Bearer '.$token])->assertOk();

    $this->postJson('/api/v1/management/customers/'.$customer->account_id.'/suspend', [
        'reason' => 'Fraud review',
    ], ['Authorization' => 'Bearer '.$token])->assertOk();

    expect(strtolower($customer->fresh()->status))->toBe('suspended');

    $this->postJson('/api/v1/management/customers/'.$customer->account_id.'/activate', [], ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    expect(strtolower($customer->fresh()->status))->toBe('active');
});

test('pending transactions can be confirmed then refunded', function () {
    [$owner, $business, $store] = modulesContext();
    $token = managementToken($owner);

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'source' => 'checkout',
        'order_number' => 'ORD-API-2',
        'subtotal' => 4000,
        'total' => 4000,
        'amount_paid' => 0,
        'status' => 'pending',
    ]);

    $transaction = Transaction::create([
        'reference' => 'TXN-API-1',
        'order_id' => $order->id,
        'business_id' => $business->id,
        'amount' => 4000,
        'status' => TransactionStatus::PENDING,
    ]);

    $this->postJson('/api/v1/management/transactions/'.$transaction->reference.'/confirm', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()
        ->assertJsonPath('data.transaction.status', 'confirmed');

    expect((float) $order->fresh()->amount_paid)->toBe(4000.0)
        ->and($store->fresh()->balance)->toBe(400000);

    $this->postJson('/api/v1/management/transactions/'.$transaction->reference.'/refund', [
        'reason' => 'Customer changed mind',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.transaction.status', 'refunded');

    expect((float) $order->fresh()->amount_paid)->toBe(0.0)
        ->and($store->fresh()->balance)->toBe(0);
});

test('a staff member can be invited and roles listed', function () {
    [$owner, $business, $store] = modulesContext();
    $token = managementToken($owner);

    $this->getJson('/api/v1/management/roles', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonStructure(['data' => ['roles', 'permissions']]);

    $this->postJson('/api/v1/management/staff', [
        'name' => 'New Cashier',
        'email' => 'new-cashier@example.test',
        'role' => 'Cashier',
        'pin' => '123456',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertCreated()
        ->assertJsonPath('data.staff.status', 'invited');
});

test('accounting endpoints return reports', function () {
    [$owner, $business, $store] = modulesContext();
    $token = managementToken($owner);

    $this->getJson('/api/v1/management/accounting/dashboard', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonStructure(['data' => ['assets', 'liabilities', 'equity', 'income_ytd', 'expenses_ytd', 'net_profit_ytd']]);

    $this->getJson('/api/v1/management/accounting/accounts', ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    $this->getJson('/api/v1/management/accounting/journal', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);

    $this->getJson('/api/v1/management/accounting/reports/profit-and-loss', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonStructure(['data' => ['total_income', 'total_expenses', 'net_profit']]);

    $this->getJson('/api/v1/management/accounting/reports/balance-sheet', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonStructure(['data' => ['total_assets', 'total_liabilities', 'total_equity']]);

    $this->getJson('/api/v1/management/accounting/reports/vat-summary', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonStructure(['data' => ['output_tax', 'input_tax', 'net_payable']]);
});
