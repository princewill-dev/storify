<?php

use App\Models\Business;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Services\Payments\PaymentGatewayResolver;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| WS-45 — what a charge looks like when Storify collects it.
|
| The charge is the platform's own: it names nothing of the business's, and the
| gross lands in Storify's balance for `custody:payouts` to hand over. That is
| the whole of it now. This file used to be about the *flip* — before it the
| platform's key carried the business's account so the provider settled the
| business directly, after it the charge named nothing — and about the failure
| that had no symptom until the books were read: an account header still going
| out would settle the business directly *and* have the wallet credit it as
| though Storify held the money, so the business was paid twice and nothing
| errored.
|
| There is nothing left to flip, and no per-business account for a header to
| name. So the tests below assert the absences that used to be conditional —
| the split fields on Paystack, the account header and platform fee on Bachs —
| unconditionally, because there is no longer a charge anywhere on the platform
| that carries them. The companion assertions on the row that gets written are
| kept for the same reason they were written: the webhook, the ledger and the
| wallet all read it afterwards, and a charge that stopped being recorded as
| platform-processed would be money credited to nobody.
*/

function cc45Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function cc45Commission(float $percent = 2.5): void
{
    cc45Settings([
        'platform_commission_percent' => $percent,
        'platform_commission_fixed_kobo' => 0,
    ]);
}

function cc45PlatformPaystack(): void
{
    config([
        'services.paystack.secret_key' => 'sk_live_platform1234567890',
        'services.paystack.public_key' => 'pk_live_platform1234567890',
    ]);
}

function cc45PlatformBachs(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

function cc45Store($owner): Store
{
    return Store::create([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'cc45-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ]);
}

/**
 * A business Storify collects for.
 *
 * No payment-method row and no account anywhere. The column is already true by
 * default, so this exists to say the precondition out loud rather than to
 * arrange it — which is itself the point: there is nothing left to arrange.
 */
function cc45Custody(Business $business): Business
{
    $business->forceFill(['custody_enabled' => true, 'custody_enabled_at' => now()])->save();

    return $business->fresh();
}

function cc45Order(Business $business, Store $store, float $total = 2500): Order
{
    return Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'CC45-'.fake()->unique()->numerify('######'),
        'subtotal' => $total,
        'total' => $total,
        'amount_paid' => 0,
        'status' => 'pending',
    ]);
}

/** The body of the one call the provider received at `$path`. */
function cc45Sent(string $path): array
{
    $sent = null;

    Http::assertSent(function (Request $request) use (&$sent, $path) {
        if (! str_contains($request->url(), $path)) {
            return false;
        }

        $sent = $request->data();

        return true;
    });

    return $sent ?? [];
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

/* ------------------------------------------------------ the account is not named */

test('a Paystack charge carries no subaccount, no split and no bearer', function () {
    // The three split fields, all absent, and they are absent from the driver
    // rather than switched off per charge. `subaccount` is the one that decided
    // where the money landed: Paystack credits that subaccount's settlement and
    // pays the platform nothing, so sending it is what put the gross in the
    // business's own account while the wallet recorded a debt against it.
    cc45PlatformPaystack();
    cc45Commission();
    cc45Settings(['system_payment_processor' => 'paystack']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = cc45Store($owner);
    cc45Custody($business);

    $order = cc45Order($business, $store);

    Http::swap(new Factory);
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.com/cc45', 'reference' => 'cc45_ref'],
    ])]);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/initialize', [
        'order_number' => $order->order_number,
        'amount' => 2500,
    ])->assertOk();

    $sent = cc45Sent('/transaction/initialize');

    expect($sent)->not->toHaveKey('subaccount')
        // With no split there is no share to take, so a commission figure riding
        // along would be an instruction about a thing that does not exist.
        ->and($sent)->not->toHaveKey('transaction_charge')
        ->and($sent)->not->toHaveKey('bearer')
        // The charge itself is unchanged: same amount, priced the same way.
        ->and($sent['amount'])->toBe(250000);
});

test('a Bachs charge carries no account header and no platform fee', function () {
    // Bachs does not reject an absent `X-Account-Id` — it charges the platform's
    // own account and reports success — so the header is the only place this
    // could be caught, and it is caught here. `platform_fee` is the same
    // instruction in the other direction: it would have the provider take our
    // commission out of the business's proceeds, which is money the business has
    // already received as far as the wallet is concerned.
    cc45PlatformBachs();
    cc45Commission();
    cc45Settings(['system_payment_processor' => 'bachs']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = cc45Store($owner);
    cc45Custody($business);

    $order = cc45Order($business, $store);

    Http::swap(new Factory);

    $headers = null;
    $sent = null;

    Http::fake(function (Request $request) use (&$headers, &$sent) {
        if (str_contains($request->url(), '/checkout-sessions')) {
            $headers = $request->headers();
            $sent = $request->data();
        }

        return Http::response([
            'checkout_id' => 'cs_cc45',
            'checkout_url' => 'https://checkout.bachs.test/cc45',
            'status' => 'open',
        ]);
    });

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bachs/initialize', [
        'order_number' => $order->order_number,
        'amount' => 2500,
    ])->assertOk();

    expect($headers['X-Account-Id'][0] ?? null)->toBeNull()
        ->and($sent)->not->toHaveKey('platform_fee');
});

/* --------------------------------------------------------- what the row records */

test('a charge is recorded as platform-processed, and names no account', function () {
    // The row is what the webhook, the ledger and the wallet read afterwards. If
    // a charge stopped being `platform_managed` the money would never be credited
    // to anyone; if it carried an account id, a later refund would go looking for
    // a split that never happened. Both are asserted on the column and on the
    // absence of the key, because the schema no longer has a place to put one.
    cc45PlatformPaystack();
    cc45Commission();
    cc45Settings(['system_payment_processor' => 'paystack']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = cc45Store($owner);
    cc45Custody($business);

    $order = cc45Order($business, $store);

    Http::swap(new Factory);
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.com/cc45', 'reference' => 'cc45_ref'],
    ])]);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/initialize', [
        'order_number' => $order->order_number,
        'amount' => 2500,
    ])->assertOk();

    $transaction = DB::table('transactions')->where('order_id', $order->id)->first();
    $metadata = json_decode($transaction->metadata, true);

    expect($metadata['payment_mode'])->toBe('platform_managed')
        ->and($metadata)->not->toHaveKey('gateway_account_id')
        // The commission is still recorded: it is Storify's cut of a sale it
        // processed, and the collection entry on the books depends on it.
        ->and((int) $transaction->platform_fee_kobo)->toBe(6250);
});

/* --------------------------------------------------- what may be sold, and why */

test('a collected business with no bank on file is offered, and nothing is explained away', function () {
    // The half of the change that is about the business rather than the money.
    // A destination that cannot be paid out to used to stop the store selling;
    // under collect-and-pay-out the money waits for a bank instead, so the only
    // reason the storefront has nothing to offer is a reason about the platform.
    cc45PlatformPaystack();
    cc45Settings(['system_payment_processor' => 'paystack']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = cc45Store($owner);
    cc45Custody($business);

    expect($business->fresh()->payout_bank_id)->toBeNull();

    $resolver = app(PaymentGatewayResolver::class);

    expect(collect($resolver->forStore($store))->keys())->toContain('paystack')
        ->and($resolver->storeUnavailableReason($store))->toBeNull();
});

test('the settings screen reports custody without pretending an account exists', function () {
    // What the business's own screen is told. Custody is the fact; there is no
    // second one about an account at the provider beside it, and the card still
    // renders as managed rather than offering a key form the business must not
    // fill in.
    cc45PlatformPaystack();
    cc45Settings(['system_payment_processor' => 'paystack']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = cc45Store($owner);
    cc45Custody($business);

    $state = collect(app(PaymentGatewayResolver::class)->states((int) $business->id, $store))
        ->firstWhere('code', 'paystack');

    expect($state['custody'])->toBeTrue()
        ->and($state['payment_mode'])->toBe('platform_managed')
        ->and($state['has_credentials'])->toBeTrue()
        ->and($state['unavailable_reason'])->toBeNull()
        // No account, and no field carrying one. A screen that still received
        // `managed.account_id` would be reading a fact the server no longer has.
        ->and($state)->not->toHaveKey('managed');
});
