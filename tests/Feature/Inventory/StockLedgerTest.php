<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Store;
use App\Services\StockLedgerService;

test('stock removal updates the location and records an auditable balance', function () {
    [$user, $business] = createBusinessOwner();

    $store = Store::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'name' => 'Ledger Store',
        'status' => Store::STATUS_ACTIVE,
    ]);

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Ledger Product',
        'quantity' => 20,
        'stock_quantity' => 20,
        'amount' => 5000,
        'status' => 'active',
    ]);

    $location = StockLocation::create([
        'product_id' => $product->id,
        'locationable_type' => Store::class,
        'locationable_id' => $store->id,
        'business_id' => $business->id,
        'quantity' => 20,
    ]);

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'user_id' => $user->id,
        'source' => 'checkout',
        'subtotal' => 15000,
        'total' => 15000,
        'status' => 'pending',
    ]);

    $movement = app(StockLedgerService::class)
        ->recordRemoval($location, 3, $order, $user, 'Characterization sale');

    expect($location->fresh()->quantity)->toBe(17)
        ->and($movement->balance_before)->toBe(20)
        ->and($movement->balance_after)->toBe(17)
        ->and($movement->quantity)->toBe(3)
        ->and($movement->business_id)->toBe($business->id)
        ->and(StockMovement::where('reference_id', $order->id)->count())->toBe(1);

    $retry = app(StockLedgerService::class)
        ->recordRemoval($location, 3, $order, $user, 'Retried sale');

    expect($retry->is($movement))->toBeTrue()
        ->and($location->fresh()->quantity)->toBe(17)
        ->and(StockMovement::where('reference_id', $order->id)->count())->toBe(1);
});

test('stock removal rejects an insufficient balance without writing a movement', function () {
    [$user, $business] = createBusinessOwner();

    $store = Store::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'name' => 'Low Stock Store',
        'status' => Store::STATUS_ACTIVE,
    ]);

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Low Stock Product',
        'quantity' => 2,
        'stock_quantity' => 2,
        'amount' => 5000,
        'status' => 'active',
    ]);

    $location = StockLocation::create([
        'product_id' => $product->id,
        'locationable_type' => Store::class,
        'locationable_id' => $store->id,
        'business_id' => $business->id,
        'quantity' => 2,
    ]);

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'user_id' => $user->id,
        'source' => 'checkout',
        'subtotal' => 15000,
        'total' => 15000,
        'status' => 'pending',
    ]);

    expect(fn () => app(StockLedgerService::class)
        ->recordRemoval($location, 3, $order, $user))
        ->toThrow(DomainException::class, 'Insufficient stock');

    expect($location->fresh()->quantity)->toBe(2)
        ->and(StockMovement::where('reference_id', $order->id)->count())->toBe(0);
});
