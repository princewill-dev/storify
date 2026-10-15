<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Store;

/**
 * Physically-stocked storefront checkout: owner + store + product + store
 * stock location + an active guest cart holding 2 units (200000 kobo).
 *
 * The customer is the fixture's own: checkout requires a verified account, and
 * the cart is handed over as that account's by the `X-Guest-Token` the cart was
 * built with — `lockCart` treats a customer's own carts and the guest cart this
 * browser holds as one pool, which is exactly what signing in mid-checkout
 * relies on.
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

    return [$store, $product, $stock, $cart, [
        'X-Guest-Token' => 'guest-checkout-token',
        'Authorization' => 'Bearer '.createStorefrontCustomer($store)[1],
    ]];
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

test('a returning customer is charged for the basket on screen, not one they abandoned earlier', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Two Carts Store',
        'slug' => 'two-carts-store',
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ]);

    $productOf = function (string $name, float $amount) use ($store, $business): Product {
        $product = Product::create([
            'store_id' => $store->id,
            'business_id' => $business->id,
            'name' => $name,
            'quantity' => 5,
            'stock_quantity' => 5,
            'amount' => $amount,
            'status' => 'active',
        ]);

        StockLocation::create([
            'product_id' => $product->id,
            'locationable_type' => Store::class,
            'locationable_id' => $store->id,
            'business_id' => $business->id,
            'quantity' => 5,
        ]);

        return $product;
    };

    $abandoned = $productOf('Abandoned Item', 9000);
    $wanted = $productOf('Wanted Item', 1000);

    [$customer, $token] = createStorefrontCustomer($store);

    $cartOf = function (Product $product, ?string $guestToken) use ($store, $customer): Cart {
        $cart = Cart::create([
            'store_id' => $store->id,
            'user_id' => $guestToken === null ? $customer->id : null,
            'user_type' => $guestToken === null ? Customer::class : null,
            'guest_token' => $guestToken,
            'status' => 'active',
            'currency' => 'NGN',
            'item_count' => 1,
            'subtotal' => (int) round((float) $product->amount * 100),
            'total' => (int) round((float) $product->amount * 100),
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'unit_amount' => (int) round((float) $product->amount * 100),
            'qty' => 1,
            'line_subtotal' => (int) round((float) $product->amount * 100),
        ]);

        return $cart;
    };

    // Signed in months ago, filled a basket, left. Lower id, older.
    $oldCart = $cartOf($abandoned, null);

    // Filled a new basket while signed out, then signed in at checkout. The
    // storefront shows this one, because `resolveCart` takes the newest of the
    // customer's own carts and the guest cart together.
    $newCart = $cartOf($wanted, 'two-carts-guest');

    $headers = [
        'X-Guest-Token' => 'two-carts-guest',
        'Authorization' => 'Bearer '.$token,
    ];

    $this->postJson('/api/v1/storefront/'.$store->slug.'/checkout', storefrontCheckoutPayload(), $headers)
        ->assertCreated()
        ->assertJsonPath('data.order.total', 1000);

    $order = Order::where('store_id', $store->id)->sole();

    // Asking for the customer's own cart first, and the oldest at that, meant
    // this order was for the abandoned item while the screen showed the new one.
    expect($order->items->pluck('product_name')->all())->toBe(['Wanted Item'])
        ->and($wanted->fresh()->quantity)->toBe(4)
        ->and($abandoned->fresh()->quantity)->toBe(5)
        ->and($newCart->fresh()->status)->toBe('completed')
        // The untouched basket is still there rather than swallowed.
        ->and($oldCart->fresh()->status)->toBe('active')
        ->and($oldCart->items()->count())->toBe(1);
});
