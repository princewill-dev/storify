<?php

use App\Models\Order;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function createPosSaleContext(): array
{
    [$owner, $business] = createBusinessOwner();

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'POS Store',
        'status' => Store::STATUS_ACTIVE,
        'pos_enabled' => true,
    ]);

    $staff->assignedStores()->attach($store->id);

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'POS Product',
        'quantity' => 10,
        'stock_quantity' => 10,
        'amount' => 1000,
        'status' => 'active',
    ]);

    $stock = StockLocation::create([
        'product_id' => $product->id,
        'locationable_type' => Store::class,
        'locationable_id' => $store->id,
        'business_id' => $business->id,
        'quantity' => 10,
    ]);

    PosSession::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'staff_id' => $staff->id,
        'opening_balance' => 0,
    ]);

    Sanctum::actingAs($staff);

    return [$staff, $store, $product, $stock];
}

test('a mismatched split payment does not leave a partial order behind', function () {
    [, $store, $product] = createPosSaleContext();

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [
            ['method' => 'cash', 'amount' => 400],
            ['method' => 'cash', 'amount' => 500],
        ],
    ])->assertUnprocessable();

    expect(Order::where('store_id', $store->id)->count())->toBe(0)
        ->and(Transaction::count())->toBe(0)
        ->and($product->fresh()->quantity)->toBe(10)
        ->and($store->fresh()->balance)->toBe(0);
});

test('a valid split payment creates one order and one transaction per leg', function () {
    [, $store, $product, $stock] = createPosSaleContext();

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", [
        'idempotency_key' => 'sale-request-001',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [
            ['method' => 'cash', 'amount' => 400, 'amount_tendered' => 400],
            ['method' => 'cash', 'amount' => 600, 'amount_tendered' => 600],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.order.payment_method', 'split')
        ->assertJsonCount(2, 'data.order.payments');

    $order = Order::where('store_id', $store->id)->sole();

    expect($order->transactions)->toHaveCount(2)
        ->and((float) $order->transactions->sum('amount'))->toBe(1000.0)
        ->and($product->fresh()->quantity)->toBe(9)
        ->and($stock->fresh()->quantity)->toBe(9)
        ->and($store->fresh()->balance)->toBe(100000);

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", [
        'idempotency_key' => 'sale-request-001',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [
            ['method' => 'cash', 'amount' => 400, 'amount_tendered' => 400],
            ['method' => 'cash', 'amount' => 600, 'amount_tendered' => 600],
        ],
    ])->assertOk()->assertJsonPath('replayed', true);

    expect(Order::where('store_id', $store->id)->count())->toBe(1)
        ->and(Transaction::where('order_id', $order->id)->count())->toBe(2)
        ->and($product->fresh()->quantity)->toBe(9)
        ->and($stock->fresh()->quantity)->toBe(9)
        ->and($store->fresh()->balance)->toBe(100000);
});

test('staff cannot use POS endpoints for an unassigned store', function () {
    [$staff] = createPosSaleContext();
    [, $otherBusiness] = createBusinessOwner();

    $otherStore = Store::create([
        'user_id' => $otherBusiness->user_id,
        'business_id' => $otherBusiness->id,
        'name' => 'Other POS Store',
        'status' => Store::STATUS_ACTIVE,
        'pos_enabled' => true,
    ]);

    Sanctum::actingAs($staff);

    $this->getJson("/api/v1/pos/stores/{$otherStore->store_id}/orders")
        ->assertForbidden();
});
