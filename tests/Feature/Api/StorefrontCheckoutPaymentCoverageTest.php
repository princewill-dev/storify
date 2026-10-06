<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;

/**
 * Ports the checkout bank-transfer coverage from the legacy
 * tests/Feature/Checkout/CheckoutPaymentTest.php onto the storefront API.
 *
 * Already covered elsewhere (StorefrontBankTransferApiTest): a partial bank
 * transfer creates a pending transaction with the requested amount. The
 * assertions added here are the order is not credited while the transfer is
 * unconfirmed, and that orders cannot be paid through another store.
 *
 * @return array{0: Store, 1: Business, 2: User}
 */
function storefrontCheckoutPaymentContext(string $slug = 'checkout-payment-store'): array
{
    [$user, $business] = createBusinessOwner();

    $store = Store::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'name' => 'Checkout Payments Store',
        'slug' => $slug,
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ]);

    return [$store, $business, $user];
}

/**
 * @return array{0: Store, 1: Business, 2: User, 3: Order}
 */
function storefrontCheckoutPaymentOrder(float $total = 1000, string $slug = 'checkout-payment-store'): array
{
    [$store, $business, $user] = storefrontCheckoutPaymentContext($slug);

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'user_id' => $user->id,
        'source' => 'checkout',
        'subtotal' => $total,
        'total' => $total,
        'amount_paid' => 0,
        'status' => 'pending',
    ]);

    return [$store, $business, $user, $order];
}

test('a partial bank transfer stays pending and does not credit the order', function () {
    [$store, , , $order] = storefrontCheckoutPaymentOrder(1000);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bank-transfer', [
        'order_number' => $order->order_number,
        'amount' => 400,
    ])
        ->assertCreated()
        ->assertJsonPath('data.transaction.status', 'pending');

    $transaction = Transaction::where('order_id', $order->id)->sole();

    expect((float) $transaction->amount)->toBe(400.0)
        ->and($transaction->status)->toBe(TransactionStatus::PENDING)
        ->and((float) $order->fresh()->amount_paid)->toBe(0.0)
        ->and($order->fresh()->remainingBalance())->toBe(1000.0);
});

test('a bank transfer cannot be submitted against another store order', function () {
    [, , , $order] = storefrontCheckoutPaymentOrder(1000, 'checkout-owner-store');

    [$otherStore] = storefrontCheckoutPaymentContext('checkout-other-store');

    $this->postJson('/api/v1/storefront/'.$otherStore->slug.'/payments/bank-transfer', [
        'order_number' => $order->order_number,
        'amount' => 400,
    ])->assertNotFound();

    expect(Transaction::where('order_id', $order->id)->exists())->toBeFalse()
        ->and((float) $order->fresh()->amount_paid)->toBe(0.0);
});
