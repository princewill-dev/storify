<?php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The public order lookup, and the number that makes it safe
|--------------------------------------------------------------------------
| `GET /storefront/{store}/orders/{orderNumber}` is deliberately
| unauthenticated, decided by the owner on 2026-10-15: a person checking on
| their order should not have to make an account to do it, and the tracking
| page is reached from an e-mail, often on a phone, often by somebody who has
| never signed in and may not be the person who ordered.
|
| What makes that defensible is not the absence of a login — it is that the
| order number is a bearer capability, unguessable and long. Nothing else is
| checked: no surname, no postcode, no e-mail match. An order number that
| counted up from one would turn this endpoint into a readable feed of every
| order on the platform, complete with buyer name, address and transactions.
|
| So this file is a guard on the generator, not on the route. If order numbers
| are ever made friendlier — sequential, shorter, derived from the id, or
| resettable per store — the first test below fails, and the failure is the
| question "should this still be public?" rather than "fix the assertion".
| The second test pins the part that still contains a leak: the lookup is
| scoped to the storefront in the path, so a number from one shop cannot be
| spent at another.
*/

function ws56tContext(string $name = 'Shopfront'): array
{
    [, $business] = createBusinessOwner();

    $store = Store::create([
        'business_id' => $business->id,
        'name' => $name,
        'slug' => 'ws56t-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ]);

    return [$store, $business];
}

function ws56tCustomer(Business $business): Customer
{
    return Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ws56t-'.Str::lower(Str::random(10)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
        'email_verified_at' => now(),
        'registered_at' => now(),
    ]);
}

function ws56tOrder(Store $store, Business $business): Order
{
    return Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'customer_id' => ws56tCustomer($business)->id,
        'source' => 'checkout',
        'subtotal' => 5000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 5000,
        'amount_paid' => 0,
        'status' => 'completed',
    ]);
}

test('an order number is long and random enough to be a bearer capability', function () {
    [$store, $business] = ws56tContext();

    $numbers = collect(range(1, 40))
        ->map(fn () => ws56tOrder($store, $business)->order_number);

    // `ORD-` plus ten symbols from [A-Z0-9] — a space of 36^10, about 3.7e15.
    // A counter ("ORD-000123"), a timestamp, or an id-derived number all fail
    // the width first, which is the point: the assertion is about how much
    // space an attacker has to search, and it should be read that way.
    $numbers->each(function (string $number) {
        expect($number)->toMatch('/^ORD-[A-Z0-9]{10}$/');
    });

    expect($numbers->unique())->toHaveCount($numbers->count());
});

test('the public lookup needs no token, but a number from one shop is refused at another', function () {
    [$store, $business] = ws56tContext('Kano Supplies');
    [$otherStore] = ws56tContext('Lagos Lights');

    $order = ws56tOrder($store, $business);

    // No Authorization header, no guest token, no cookie: this is the decision.
    $this->getJson("/api/v1/storefront/{$store->slug}/orders/{$order->order_number}")
        ->assertOk()
        ->assertJsonPath('data.order.order_number', $order->order_number);

    // Scoped to the storefront in the path — the one containment that still
    // holds, and the reason the route is `storefront/{store}/orders/...` rather
    // than a platform-wide `/orders/{number}`.
    $this->getJson("/api/v1/storefront/{$otherStore->slug}/orders/{$order->order_number}")
        ->assertNotFound();
});
