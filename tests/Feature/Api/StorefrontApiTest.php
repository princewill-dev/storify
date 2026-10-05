<?php

use App\Models\Customer;
use App\Models\DigitalDownload;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;

/**
 * @return array{0: Store, 1: \App\Models\Business}
 */
function storefrontContext(): array
{
    [, $business] = createBusinessOwner();

    $store = Store::create([
        'business_id' => $business->id,
        'name' => 'Shopfront',
        'slug' => 'shopfront',
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ]);

    return [$store, $business];
}

test('storefront catalog endpoints return products and categories', function () {
    [$store, $business] = storefrontContext();

    Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Visible Product',
        'amount' => 1000,
        'quantity' => 5,
        'status' => 'active',
        'featured' => true,
    ]);

    Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Hidden Product',
        'amount' => 1000,
        'quantity' => 5,
        'status' => 'inactive',
    ]);

    $this->getJson('/api/v1/storefront/'.$store->slug.'/home')
        ->assertOk()
        ->assertJsonPath('data.store.slug', 'shopfront')
        ->assertJsonCount(1, 'data.featured_products');

    $this->getJson('/api/v1/storefront/'.$store->slug.'/products')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Visible Product');

    $visible = Product::where('name', 'Visible Product')->sole();

    $this->getJson('/api/v1/storefront/'.$store->slug.'/products/'.$visible->product_code)
        ->assertOk()
        ->assertJsonPath('data.product.name', 'Visible Product');
});

test('a guest can manage a cart with the guest token header', function () {
    [$store, $business] = storefrontContext();

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Cart Item',
        'amount' => 1500,
        'quantity' => 10,
        'status' => 'active',
    ]);

    $headers = ['X-Guest-Token' => 'guest-token-123'];

    $this->postJson('/api/v1/storefront/'.$store->slug.'/cart/items', [
        'product_id' => $product->id,
        'qty' => 2,
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.item_count', 2)
        ->assertJsonPath('data.subtotal', 300000);

    $itemId = null;

    $cart = $this->getJson('/api/v1/storefront/'.$store->slug.'/cart', $headers)->assertOk();
    $itemId = $cart->json('data.items.0.id');

    $this->patchJson('/api/v1/storefront/'.$store->slug.'/cart/items/'.$itemId, [
        'qty' => 3,
    ], $headers)->assertOk()->assertJsonPath('data.item_count', 3);

    $this->deleteJson('/api/v1/storefront/'.$store->slug.'/cart', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.item_count', 0);
});

test('a digital checkout can be placed without shipping details', function () {
    [$store, $business] = storefrontContext();

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'API E-Book',
        'amount' => 5000,
        'status' => 'active',
        'is_digital' => true,
        'quantity' => 0,
    ]);

    $headers = ['X-Guest-Token' => 'guest-digital-1'];

    $this->postJson('/api/v1/storefront/'.$store->slug.'/cart/items', [
        'product_id' => $product->id,
        'qty' => 1,
    ], $headers)->assertOk();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/checkout', [
        'email' => 'buyer@example.test',
        'first_name' => 'Digital',
        'last_name' => 'Buyer',
        'phone' => '08000000001',
    ], $headers)
        ->assertCreated()
        ->assertJsonPath('data.order.shipping_fee', 0)
        ->assertJsonPath('data.order.total', 5000);

    $order = Order::where('store_id', $store->id)->sole();

    expect((float) $order->shipping_fee)->toBe(0.0)
        ->and($order->delivery_address_id)->toBeNull()
        ->and($product->fresh()->quantity)->toBe(0);
});

test('customers can view their orders and downloads through the api', function () {
    [$store, $business] = storefrontContext();

    $customer = Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Account',
        'last_name' => 'Holder',
        'email' => 'account@example.test',
        'phone' => '08000000002',
        'password' => bcrypt('secret-pass-123'),
        'status' => 'active',
    ]);

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Owned E-Book',
        'amount' => 3000,
        'status' => 'active',
        'is_digital' => true,
        'quantity' => 0,
    ]);

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'source' => 'digital',
        'order_number' => 'ORD-SF-1',
        'subtotal' => 3000,
        'total' => 3000,
        'amount_paid' => 3000,
        'status' => 'accepted',
    ]);

    $item = $order->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'unit_price' => 3000,
        'quantity' => 1,
        'subtotal' => 3000,
        'is_digital' => true,
    ]);

    DigitalDownload::create([
        'business_id' => $business->id,
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'product_id' => $product->id,
        'customer_id' => $customer->id,
        'token' => str_repeat('e', 64),
        'download_count' => 0,
        'max_downloads' => 5,
        'expires_at' => now()->addDays(7),
    ]);

    $token = $customer->createToken('customer-access', ['customer'], now()->addHour())->plainTextToken;
    $headers = ['Authorization' => 'Bearer '.$token];

    $this->getJson('/api/v1/storefront/account/orders', $headers)
        ->assertOk()
        ->assertJsonPath('data.0.order_number', 'ORD-SF-1');

    $this->getJson('/api/v1/storefront/account/orders/ORD-SF-1', $headers)
        ->assertOk()
        ->assertJsonPath('data.downloads.0.product_name', 'Owned E-Book');

    $this->getJson('/api/v1/storefront/account/downloads', $headers)
        ->assertOk()
        ->assertJsonPath('data.0.status', 'Active');

    $this->putJson('/api/v1/storefront/account/profile', [
        'first_name' => 'Renamed',
    ], $headers)->assertOk();
});
