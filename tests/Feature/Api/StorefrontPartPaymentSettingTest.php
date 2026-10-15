<?php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Part payment is the store's decision
|--------------------------------------------------------------------------
| Paying part of an order and settling the rest later used to be available on
| every storefront, because nothing anywhere said otherwise — the whole rule was
| one clamp, `min($amount, $remaining)`, applied at both payment doors. A store
| that did not want to carry that had no way to say so.
|
| The setting that now says so defaults to *off*, so these tests are written
| against the default first: a store that has never opened the card takes full
| payment only. That is the state a store is in on the day this ships, and the
| one worth pinning.
|
| The rule is deliberately one-sided — a partial needs the switch, clearing the
| balance does not — and the test that matters most is the third one. An order
| that was part-paid before the switch went off still has to be completable:
| the alternative is a customer whose money is held against an order they are
| no longer allowed to finish.
*/

/**
 * A published storefront, a customer of it, and a token acting as that customer.
 *
 * The owner rides along in fourth position for the one test that settles a
 * payment from the management side, and carries a trial because that endpoint
 * sits behind the subscription gate.
 *
 * @return array{0: Store, 1: Business, 2: Customer, 3: string, 4: User}
 */
function ppStore(array $attributes = []): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Part Payment Shop',
        'slug' => 'pp-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ], $attributes));

    [$customer, $token] = createStorefrontCustomer($store);

    return [$store, $business, $customer, $token, $owner];
}

function ppOwnerToken(User $owner): string
{
    return $owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ppOrder(Store $store, Business $business, Customer $customer, float $total = 1000, float $paid = 0): Order
{
    return Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'source' => 'checkout',
        'subtotal' => $total,
        'total' => $total,
        'amount_paid' => $paid,
        'status' => 'pending',
    ]);
}

/**
 * @return array<string, string>
 */
function ppHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

test('a store that has never touched the setting takes full payment only', function () {
    [$store, $business, $customer, $token] = ppStore();

    // `fresh()`, not the instance `Store::create` returned: the column default
    // lives in the database, so a model that was never told about the attribute
    // reads null rather than false.
    expect($store->fresh()->allow_part_payment)->toBeFalse();

    $order = ppOrder($store, $business, $customer, 1000);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bank-transfer', [
        'order_number' => $order->order_number,
        'amount' => 400,
    ], ppHeaders($token))
        ->assertStatus(422)
        ->assertJsonPath('errors.checkout', ['part_payment_disabled'])
        // The refusal names the figure. The caller has already decided how much
        // to send, so "not allowed" on its own leaves them guessing what would
        // be, and the answer is not obvious from the order total once anything
        // has been paid towards it.
        ->assertJsonPath('message', 'This store takes full payment only. Please pay the outstanding balance of NGN 1,000.00.');

    // Refused before anything was written: no half-made payment left behind for
    // the management side to reconcile against a customer who was told no.
    expect(Transaction::where('order_id', $order->id)->count())->toBe(0)
        ->and((float) $order->fresh()->amount_paid)->toBe(0.0);
});

test('clearing the balance is allowed with the setting off', function () {
    [$store, $business, $customer, $token] = ppStore();

    $order = ppOrder($store, $business, $customer, 1000);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bank-transfer', [
        'order_number' => $order->order_number,
        'amount' => 1000,
    ], ppHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.transaction.status', 'pending');

    $transaction = Transaction::where('order_id', $order->id)->sole();

    // Recorded as a full payment, not a partial one: the flag follows the
    // amount, not the route it came in through.
    expect((float) $transaction->amount)->toBe(1000.0)
        ->and($transaction->metadata['is_partial'])->toBeFalse();
});

test('an order part-paid before the switch went off can still be completed', function () {
    // The case the one-sided rule exists for. ₦400 was taken under the old
    // behaviour; the store then switched part payment off. The ₦600 balance has
    // to stay payable, or the store is holding money against an order it will
    // not let anyone finish.
    [$store, $business, $customer, $token] = ppStore();

    $order = ppOrder($store, $business, $customer, 1000, 400);

    expect($store->fresh()->allow_part_payment)->toBeFalse()
        ->and($order->remainingBalance())->toBe(600.0);

    // Some *other* partial of the balance is still refused — the switch is off,
    // and "pay the rest" does not mean "pay any part of the rest".
    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bank-transfer', [
        'order_number' => $order->order_number,
        'amount' => 300,
    ], ppHeaders($token))->assertStatus(422)->assertJsonPath('errors.checkout', ['part_payment_disabled']);

    // …and the balance itself clears.
    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bank-transfer', [
        'order_number' => $order->order_number,
        'amount' => 600,
    ], ppHeaders($token))->assertCreated();

    expect(Transaction::where('order_id', $order->id)->count())->toBe(1)
        ->and((float) $order->fresh()->amount_paid)->toBe(400.0)
        // Still unconfirmed — the store credits the order when the transfer is
        // confirmed, not when the slip is submitted.
        ->and($order->fresh()->isFullyPaid())->toBeFalse();
});

test('with the setting on, a part payment is accepted', function () {
    [$store, $business, $customer, $token] = ppStore(['allow_part_payment' => true]);

    $order = ppOrder($store, $business, $customer, 1000);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bank-transfer', [
        'order_number' => $order->order_number,
        'amount' => 400,
    ], ppHeaders($token))->assertCreated();

    $transaction = Transaction::where('order_id', $order->id)->sole();

    expect((float) $transaction->amount)->toBe(400.0)
        ->and($transaction->metadata['is_partial'])->toBeTrue()
        // The order is not credited until the bank transfer is confirmed, so a
        // submitted slip must not move the balance.
        ->and((float) $order->fresh()->amount_paid)->toBe(0.0);
});

test('a partial is refused before the provider is ever called', function () {
    // The generic initialize route, where the money would actually move. The
    // refusal has to sit ahead of the gateway: a house that half-charges a
    // customer and then rejects the order is worse than one that rejects it
    // first, and no gateway is configured here at all, so anything reaching the
    // provider would be a bug in the ordering rather than a payment.
    Http::fake();

    [$store, $business, $customer, $token] = ppStore();

    $order = ppOrder($store, $business, $customer, 1000);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/initialize', [
        'order_number' => $order->order_number,
        'amount' => 400,
    ], ppHeaders($token))
        ->assertStatus(422)
        ->assertJsonPath('errors.checkout', ['part_payment_disabled']);

    Http::assertNothingSent();

    expect(Transaction::where('order_id', $order->id)->count())->toBe(0);
});

test('a customer can add to a part-paid order, and clearing it completes the order', function () {
    // Request C's first half, as a test: the customer sees what they have paid,
    // can add to it, and paying the balance out in full is what completes the
    // order. Two halves make a whole order here, and the second half is the one
    // that has to flip the status — a part-paid order that never reaches
    // `accepted` would leave the store unable to fulfil something it has been
    // paid in full for.
    Mail::fake();

    [$store, $business, $customer, $token, $owner] = ppStore(['allow_part_payment' => true]);

    $order = ppOrder($store, $business, $customer, 1000);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bank-transfer', [
        'order_number' => $order->order_number,
        'amount' => 400,
    ], ppHeaders($token))->assertCreated();

    $first = Transaction::where('order_id', $order->id)->sole();

    // Nothing is credited until the store confirms the transfer landed, so
    // submitting a slip is not paying.
    expect((float) $order->fresh()->amount_paid)->toBe(0.0);

    $this->withToken(ppOwnerToken($owner))
        ->postJson('/api/v1/management/transactions/'.$first->reference.'/confirm')
        ->assertOk();

    $order->refresh();

    expect((float) $order->amount_paid)->toBe(400.0)
        ->and($order->remainingBalance())->toBe(600.0)
        ->and($order->isFullyPaid())->toBeFalse()
        // Half paid is not accepted: acceptance is what tells the store it may
        // ship, and it has four hundred naira of a thousand.
        ->and($order->status->value)->toBe('pending');

    // The customer comes back for the rest. The figure is the balance, which is
    // what the storefront shows them and what the switch never blocks.
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bank-transfer', [
        'order_number' => $order->order_number,
        'amount' => 600,
    ], ppHeaders($token))->assertCreated();

    $second = Transaction::where('order_id', $order->id)->where('reference', '!=', $first->reference)->sole();

    $this->app['auth']->forgetGuards();

    $this->withToken(ppOwnerToken($owner))
        ->postJson('/api/v1/management/transactions/'.$second->reference.'/confirm')
        ->assertOk();

    $order->refresh();

    expect((float) $order->amount_paid)->toBe(1000.0)
        ->and($order->remainingBalance())->toBe(0.0)
        ->and($order->isFullyPaid())->toBeTrue()
        ->and($order->status->value)->toBe('accepted');
});

test('the storefront is told which way the setting is set', function () {
    [$store] = ppStore();

    $this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')
        ->assertOk()
        ->assertJsonPath('data.allow_part_payment', false);

    $store->update(['allow_part_payment' => true]);

    $this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')
        ->assertOk()
        ->assertJsonPath('data.allow_part_payment', true);
});
