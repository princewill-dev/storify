<?php

use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Transaction;

function createCheckoutOrder(float $total = 1000): array
{
    [$user, $business] = createBusinessOwner();

    $store = Store::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'name' => 'Checkout Store',
        'slug' => 'checkout-store',
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ]);

    $bankTransfer = PaymentMethod::firstOrCreate(
        ['code' => 'bank_transfer'],
        ['name' => 'Bank Transfer', 'type' => 'traditional', 'is_active' => true]
    );
    $store->paymentMethods()->syncWithoutDetaching([$bankTransfer->id => ['is_active' => true]]);

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

    return [$store, $order];
}

test('checkout can create a pending partial bank transfer', function () {
    [$store, $order] = createCheckoutOrder();

    $this->post(route('checkout.payment-methods.select', [
        'store_subdomain' => $store->slug,
        'order' => $order,
    ]), [
        'payment_method' => 'bank_transfer',
        'amount' => 400,
        'idempotency_key' => 'checkout-payment-001',
    ])->assertRedirect(route('payment.bank-transfer', [
        'store_subdomain' => $store->slug,
        'order' => $order,
    ]));

    $transaction = Transaction::where('order_id', $order->id)->sole();

    expect((float) $transaction->amount)->toBe(400.0)
        ->and($transaction->status)->toBe(TransactionStatus::PENDING)
        ->and($transaction->metadata['is_partial'])->toBeTrue()
        ->and((float) $order->fresh()->amount_paid)->toBe(0.0);
});

test('checkout rejects an order that belongs to another store', function () {
    [$store, $order] = createCheckoutOrder();
    [$otherUser, $otherBusiness] = createBusinessOwner();
    $otherStore = Store::create([
        'user_id' => $otherUser->id,
        'business_id' => $otherBusiness->id,
        'name' => 'Other Store',
        'slug' => 'other-store',
        'status' => Store::STATUS_ACTIVE,
    ]);

    $this->post(route('checkout.payment-methods.select', [
        'store_subdomain' => $otherStore->slug,
        'order' => $order,
    ]), [
        'payment_method' => 'bank_transfer',
        'amount' => 400,
        'idempotency_key' => 'checkout-payment-cross-store',
    ])->assertNotFound();

    expect(Transaction::where('order_id', $order->id)->exists())->toBeFalse();
});
