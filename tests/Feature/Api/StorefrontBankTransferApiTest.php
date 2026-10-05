<?php

use App\Models\Product;
use App\Models\Store;

function storefrontBankContext(): array
{
    [, $business] = createBusinessOwner();

    $store = Store::create([
        'business_id' => $business->id,
        'name' => 'Bank Shop',
        'slug' => 'bank-shop',
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ]);

    return [$store, $business];
}

test('a bank transfer payment can be recorded without a slip', function () {
    [$store, $business] = storefrontBankContext();

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Bank Item',
        'amount' => 1500,
        'quantity' => 10,
        'status' => 'active',
    ]);

    $headers = ['X-Guest-Token' => 'bank-guest-1'];

    $this->postJson('/api/v1/storefront/'.$store->slug.'/cart/items', [
        'product_id' => $product->id,
        'qty' => 1,
    ], $headers)->assertOk();

    $orderNumber = $this->postJson('/api/v1/storefront/'.$store->slug.'/checkout', [
        'first_name' => 'Ada',
        'last_name' => 'Tester',
        'email' => 'ada@example.com',
        'phone' => '08000000000',
        'street_address' => '1 Test St',
        'city' => 'Ikeja',
        'state' => 'Lagos',
    ], $headers)->assertCreated()->json('data.order.order_number');

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bank-transfer', [
        'order_number' => $orderNumber,
        'amount' => 500,
    ], $headers)
        ->assertCreated()
        ->assertJsonPath('data.transaction.status', 'pending');

    $this->assertDatabaseHas('transactions', [
        'amount' => 500,
        'status' => 'pending',
    ]);
});

test('payment methods expose descriptions and bank accounts', function () {
    [$store] = storefrontBankContext();

    $this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'payment_methods',
                'bank_accounts',
            ],
        ]);
});
