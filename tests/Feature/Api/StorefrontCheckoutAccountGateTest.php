<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Checkout requires an account
|--------------------------------------------------------------------------
| The flag that decides this had no test at all until now, which is how it came
| to be off in a product where every order is supposed to belong to somebody.
| Being untested, flipping it was invisible: the suite passed either way, and
| what the change actually did to a live storefront was not visible from here.
|
| So these are written against the *default*, with nothing setting the config —
| that is the state a running application is in, and the one worth pinning. A
| test that had to write `config(['storefront.checkout_requires_account' => true])`
| to see the refusal would keep passing if the default went back to off.
|
| An account-less checkout is refused before anything is read, so the assertion
| that matters most is the second one in the first test: no order, no stock
| movement, nothing to clean up. A refusal that had already written a row would
| be worse than no gate at all.
*/

function checkoutGateStore(): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Gate Store',
        'slug' => 'gate-store-'.Str::lower(Str::random(6)),
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ]);

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Gate Product',
        'quantity' => 5,
        'stock_quantity' => 5,
        'amount' => 1000,
        'status' => 'active',
    ]);

    StockLocation::create([
        'product_id' => $product->id,
        'locationable_type' => Store::class,
        'locationable_id' => $store->id,
        'business_id' => $business->id,
        'quantity' => 5,
    ]);

    $cart = Cart::create([
        'store_id' => $store->id,
        'guest_token' => 'gate-guest-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'currency' => 'NGN',
        'item_count' => 1,
        'subtotal' => 100000,
        'total' => 100000,
    ]);

    CartItem::create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
        'name' => $product->name,
        'unit_amount' => 100000,
        'qty' => 1,
        'line_subtotal' => 100000,
    ]);

    return [$store, $product, $cart, ['X-Guest-Token' => $cart->guest_token]];
}

/**
 * @return array<string, string>
 */
function checkoutGatePayload(): array
{
    return [
        'first_name' => 'Ada',
        'last_name' => 'Buyer',
        'email' => 'ada.gate@example.test',
        'phone' => '08010000000',
        'street_address' => '1 Test Street',
        'state' => 'Lagos',
        'city' => 'Ikeja',
        'country' => 'Nigeria',
    ];
}

test('an account-less checkout is refused, and refuses before it writes anything', function () {
    [$store, $product, $cart, $headers] = checkoutGateStore();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/checkout', checkoutGatePayload(), $headers)
        ->assertStatus(403)
        ->assertJsonPath('errors.checkout', ['account_required'])
        // The sentence, not the code. `errors` carries a machine-readable marker
        // and the storefront's error formatter prefers `errors`, so a bare code
        // in there is what a customer would have been shown.
        ->assertJsonPath('message', 'Please create an account or sign in to complete your order.');

    expect(Order::where('store_id', $store->id)->count())->toBe(0)
        ->and($product->fresh()->quantity)->toBe(5)
        // No fabricated customer either — that is the row this gate exists to
        // stop being created, and it is the one nobody would notice.
        ->and(Customer::where('business_id', $store->business_id)->count())->toBe(0)
        ->and($cart->fresh()->status)->toBe('active');
});

test('a signed-in customer with a verified email places the order and owns it', function () {
    [$store, $product, $cart, $headers] = checkoutGateStore();

    [$customer, $token] = createStorefrontCustomer($store);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/checkout', checkoutGatePayload(), [
        ...$headers,
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertCreated()
        ->assertJsonPath('data.order.total', 1000);

    $order = Order::where('store_id', $store->id)->sole();

    // The whole point of the gate: the order is on an account, and that account
    // is the one that was signed in rather than one made up from the payload.
    expect($order->customer_id)->toBe($customer->id)
        ->and($product->fresh()->quantity)->toBe(4);
});

test('a signed-in customer whose email is unverified is refused with its own marker', function () {
    [$store, $product, $cart, $headers] = checkoutGateStore();

    [, $token] = createStorefrontCustomer($store, ['email_verified_at' => null]);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/checkout', checkoutGatePayload(), [
        ...$headers,
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertStatus(403)
        ->assertJsonPath('errors.checkout', ['email_unverified'])
        ->assertJsonPath('message', 'Please verify your email address to complete your order.');

    expect(Order::where('store_id', $store->id)->count())->toBe(0)
        ->and($product->fresh()->quantity)->toBe(5);
});

test('the refusal names the account, not the payload, so a stale client gets a real sentence', function () {
    [$store, $product, $cart, $headers] = checkoutGateStore();

    // What the previous storefront sent: a full, valid-looking contact block and
    // no account. Nothing about the payload is wrong, which is why the refusal
    // has to be about the account rather than answered with field errors telling
    // the caller to fill in a form they have already filled in.
    $this->postJson('/api/v1/storefront/'.$store->slug.'/checkout', checkoutGatePayload(), $headers)
        ->assertStatus(403)
        ->assertJsonMissingPath('errors.first_name')
        ->assertJsonMissingPath('errors.email')
        ->assertJsonPath('errors.checkout', ['account_required']);
});
