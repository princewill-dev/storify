<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Paying is authorised; reading an order is not
|--------------------------------------------------------------------------
| The storefront's payment endpoints and its order lookup were one public group.
| The store in the path scoped the lookup, so a number from one shop could not
| be spent at another — but nothing scoped it to a *customer*, and the number
| itself is not a secret: it is in the customer's address bar, and it is in the
| receipt e-mailed to them.
|
| So these tests draw the line the change makes. Reading an order by its number
| stays open, because /track is a deliberate no-account feature reached from an
| e-mail, often by somebody who did not order. Moving money does not: that needs
| a token, and the order has to be the payer's own.
|
| The ownerless case at the end is the deliberate exception, and the reason it
| is not simply "every order needs an owner" is that a quarter of the orders on
| this platform already have none — they were placed before checkout required
| accounts, and `orders` carries no e-mail to match a claimant against. Refusing
| them outright would not protect anybody; it would just make them unpayable.
*/

/**
 * @return array{0: Store, 1: Business, 2: Customer, 3: string}
 */
function poStore(array $attributes = []): array
{
    [$owner, $business] = createBusinessOwner();

    $store = Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Ownership Shop',
        'slug' => 'po-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
        'allow_part_payment' => true,
    ], $attributes));

    [$customer, $token] = createStorefrontCustomer($store);

    return [$store, $business, $customer, $token];
}

function poOrder(Store $store, Business $business, ?Customer $customer, float $total = 1000): Order
{
    return Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'customer_id' => $customer?->id,
        'source' => 'checkout',
        'subtotal' => $total,
        'total' => $total,
        'amount_paid' => 0,
        'status' => 'pending',
    ]);
}

/**
 * @return array<string, string>
 */
function poHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

test('every payment endpoint refuses an anonymous caller', function () {
    [$store] = poStore();

    $paths = [
        ['payments/bank-transfer', ['order_number' => 'ORD-NOBODY01', 'amount' => 100]],
        ['payments/paystack/initialize', ['order_number' => 'ORD-NOBODY01']],
        ['payments/paystack/verify', ['reference' => 'NOT-A-REFERENCE']],
        ['payments/bitfra/initialize', ['order_number' => 'ORD-NOBODY01']],
        ['payments/bitfra/verify', ['reference' => 'NOT-A-REFERENCE']],
    ];

    foreach ($paths as [$path, $payload]) {
        $this->postJson('/api/v1/storefront/'.$store->slug.'/'.$path, $payload)
            ->assertUnauthorized();
    }

    // Nothing was read, so nothing was written either.
    expect(Transaction::count())->toBe(0);
});

test('one customer cannot pay another customer’s order', function () {
    [$store, $business, $customer, $token] = poStore();

    $order = poOrder($store, $business, $customer, 1000);

    [, $strangerToken] = createStorefrontCustomer($store);

    // A stranger inside the right store, holding the right order number — the
    // case the old public group allowed through. It answers 404 rather than 403
    // so that a number which exists and a number that does not are
    // indistinguishable from here, exactly as a reference from another store is.
    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bank-transfer', [
        'order_number' => $order->order_number,
        'amount' => 1000,
    ], poHeaders($strangerToken))->assertNotFound();

    expect(Transaction::where('order_id', $order->id)->count())->toBe(0)
        ->and((float) $order->fresh()->amount_paid)->toBe(0.0);

    // The owner is who it is for, and gets through.
    //
    // The guard has to be forgotten first: within one test the container keeps
    // the auth guards it resolved, so the token that authenticated the previous
    // request would still be the answer here and this assertion could never
    // fail — or, as it did, pass for the wrong reason. Two identities in one
    // test need this line; in the application each request gets a fresh
    // container and it never arises.
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bank-transfer', [
        'order_number' => $order->order_number,
        'amount' => 1000,
    ], poHeaders($token))->assertCreated();

    expect(Transaction::where('order_id', $order->id)->count())->toBe(1);
});

test('one customer cannot verify another customer’s payment reference', function () {
    [$store, $business, $customer] = poStore();

    $order = poOrder($store, $business, $customer, 1000);

    $transaction = Transaction::create([
        'reference' => 'PO-'.Str::upper(Str::random(12)),
        'order_id' => $order->id,
        'business_id' => $business->id,
        'amount' => 1000,
        'currency' => 'NGN',
        'status' => TransactionStatus::PENDING,
    ]);

    [, $strangerToken] = createStorefrontCustomer($store);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/verify', [
        'reference' => $transaction->reference,
    ], poHeaders($strangerToken))->assertNotFound();
});

test('an order that names nobody is still payable, so pre-account orders are not stranded', function () {
    // Deliberate, and the comment in CheckoutRepository::scopeToCustomer() is
    // the long version. There is no field on an ownerless order to match a
    // claimant against, so the order number remains its only credential —
    // which is what it always was for these rows.
    [$store, $business] = poStore();

    $order = poOrder($store, $business, null, 1000);

    [, $token] = createStorefrontCustomer($store);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bank-transfer', [
        'order_number' => $order->order_number,
        'amount' => 1000,
    ], poHeaders($token))->assertCreated();

    expect(Transaction::where('order_id', $order->id)->count())->toBe(1);
});

test('tracking stays public, and no longer publishes the buyer', function () {
    // The half that does not change shape: no token, and the order comes back.
    [$store, $business, $customer] = poStore();

    $order = poOrder($store, $business, $customer, 1000);

    $this->getJson('/api/v1/storefront/'.$store->slug.'/orders/'.$order->order_number)
        ->assertOk()
        ->assertJsonPath('data.order.order_number', $order->order_number)
        // The other half does. `customer_email` used to ride along here, which
        // put a named buyer behind a capability the buyer's own receipt hands
        // out. Nothing read it — the storefront had no reference to the key —
        // and the account views get the address from an authenticated call.
        ->assertJsonMissingPath('data.order.customer_email');
});
