<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Store;

/**
 * Physically-stocked storefront checkout: owner + store + product + store
 * stock location + an active guest cart holding 2 units (200000 kobo).
 *
 * @return array{0: Store, 1: Product, 2: StockLocation, 3: Cart, 4: array<string, string>}
 */
function storefrontCheckoutFixture(): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Atomic Checkout Store',
        'slug' => 'atomic-checkout-store',
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ]);

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Checkout Product',
        'quantity' => 5,
        'stock_quantity' => 5,
        'amount' => 1000,
        'status' => 'active',
    ]);

    $stock = StockLocation::create([
        'product_id' => $product->id,
        'locationable_type' => Store::class,
        'locationable_id' => $store->id,
        'business_id' => $business->id,
        'quantity' => 5,
    ]);

    $cart = Cart::create([
        'store_id' => $store->id,
        'guest_token' => 'guest-checkout-token',
        'checkout_token' => 'checkout-session-token',
        'status' => 'active',
        'currency' => 'NGN',
        'item_count' => 2,
        'subtotal' => 200000,
        'total' => 200000,
    ]);

    CartItem::create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
        'name' => $product->name,
        'unit_amount' => 100000,
        'qty' => 2,
        'line_subtotal' => 200000,
    ]);

    return [$store, $product, $stock, $cart, ['X-Guest-Token' => 'guest-checkout-token']];
}

/**
 * @return array<string, string>
 */
function storefrontCheckoutPayload(): array
{
    return [
        'first_name' => 'Ada',
        'last_name' => 'Buyer',
        'email' => 'ada.buyer@example.test',
        'phone' => '08010000000',
        'street_address' => '1 Test Street',
        'state' => 'Lagos',
        'city' => 'Ikeja',
        'country' => 'Nigeria',
    ];
}

test('placing a storefront order consumes its cart and decrements product and location stock', function () {
    [$store, $product, $stock, $cart, $headers] = storefrontCheckoutFixture();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/checkout', storefrontCheckoutPayload(), $headers)
        ->assertCreated()
        ->assertJsonPath('data.order.total', 2000);

    $order = Order::where('store_id', $store->id)->sole();

    expect($order->items)->toHaveCount(1)
        ->and((float) $order->total)->toBe(2000.0)
        ->and($cart->fresh()->status)->toBe('completed')
        ->and($cart->items()->count())->toBe(0)
        ->and($product->fresh()->quantity)->toBe(3)
        ->and($stock->fresh()->quantity)->toBe(3)
        ->and(StockMovement::where('product_id', $product->id)->count())->toBe(1);
});

test('replaying a storefront checkout does not create a second order or move stock again', function () {
    [$store, $product, $stock, $cart, $headers] = storefrontCheckoutFixture();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/checkout', storefrontCheckoutPayload(), $headers)
        ->assertCreated();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/checkout', storefrontCheckoutPayload(), $headers)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Your cart is empty.');

    expect(Order::where('store_id', $store->id)->count())->toBe(1)
        ->and($product->fresh()->quantity)->toBe(3)
        ->and($stock->fresh()->quantity)->toBe(3)
        ->and(StockMovement::where('product_id', $product->id)->count())->toBe(1)
        ->and($cart->fresh()->status)->toBe('completed')
        ->and($cart->items()->count())->toBe(0);
});
