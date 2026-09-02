<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;

test('placing a storefront order consumes its cart and stock exactly once', function () {
    [$owner, $business] = createBusinessOwner();

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

    $payload = [
        'first_name' => 'Ada',
        'last_name' => 'Buyer',
        'email' => 'ada.buyer@example.test',
        'phone' => '08010000000',
        'street_address' => '1 Test Street',
        'state' => 'Lagos',
        'city' => 'Ikeja',
        'country' => 'Nigeria',
        'checkout_token' => $cart->checkout_token,
    ];

    $url = route('checkout.process', ['store_subdomain' => $store->slug]);

    $this->withCookie('guest_token', $cart->guest_token)
        ->post($url, $payload)
        ->assertRedirect();

    $order = Order::where('store_id', $store->id)->sole();

    expect((float) $order->total)->toBe(2000.0)
        ->and($order->items)->toHaveCount(1)
        ->and($cart->fresh()->status)->toBe('completed')
        ->and($cart->items()->count())->toBe(0)
        ->and($product->fresh()->quantity)->toBe(3)
        ->and($stock->fresh()->quantity)->toBe(3);

    $this->withCookie('guest_token', $cart->guest_token)
        ->from($url)
        ->post($url, $payload)
        ->assertRedirect($url)
        ->assertSessionHas('error');

    expect(Order::where('store_id', $store->id)->count())->toBe(1)
        ->and($product->fresh()->quantity)->toBe(3)
        ->and($stock->fresh()->quantity)->toBe(3);
});
