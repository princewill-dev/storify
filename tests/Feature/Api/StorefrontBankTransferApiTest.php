<?php

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

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

    // 500 against a 1500 order is a part-payment, so it must be flagged as one.
    // The Paystack branch already recorded this; the bank-transfer branch did
    // not, which made a part-payment indistinguishable from a settled transfer.
    $transaction = Transaction::query()->where('amount', 500)->where('status', 'pending')->firstOrFail();

    expect($transaction->metadata['is_partial'])->toBeTrue()
        ->and($transaction->metadata['payment_method'])->toBe('bank_transfer');
});

test('an unconfigured storefront offers no payment methods', function () {
    // This endpoint used to fall back to every platform-active method when a
    // store had none of its own, so an unconfigured store advertised gateways
    // its business had never connected. Offering nothing is the honest answer:
    // the bank-transfer option it used to show had no bank account behind it
    // anyway, so it could not have completed a payment.
    [$store] = storefrontBankContext();

    $this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')
        ->assertOk()
        ->assertJsonCount(0, 'data.payment_methods')
        ->assertJsonStructure([
            'data' => ['payment_methods', 'bank_accounts'],
        ]);
});

test('payment methods expose descriptions and bank accounts', function () {
    [$store, $business] = storefrontBankContext();

    DB::table('store_payment_method')->insert([
        'store_id' => $store->id,
        'payment_method_id' => PaymentMethod::where('code', 'bank_transfer')->value('id'),
        'business_id' => $store->business_id,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Bank transfer is only offered when there is somewhere to transfer to.
    // Without an account the customer would pick it and find no details.
    StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'GTBank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'BANK SHOP LTD',
        'is_primary' => true,
        'is_verified' => true,
    ]);

    $this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')
        ->assertOk()
        ->assertJsonCount(1, 'data.payment_methods')
        ->assertJsonPath('data.payment_methods.0.code', 'bank_transfer')
        ->assertJsonStructure([
            'data' => [
                'payment_methods' => [['id', 'name', 'code', 'type', 'description']],
                'bank_accounts',
            ],
        ]);
});
