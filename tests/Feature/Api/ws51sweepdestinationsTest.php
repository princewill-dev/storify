<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-51 — one sweep, one transfer per destination
|--------------------------------------------------------------------------
| With per-store wallets on, a business's money sits on its stores and each
| store is paid where it says. What matters here is that the split is by
| *destination* and not by store — two stores sharing the business default are
| still one transfer — and that a store can only ever be funded by its own
| takings.
|
| The tests that would catch a double-payment are the ones worth reading: a
| second sweep reserving nothing, and a sale never being allocated twice.
*/

function sd51Settings(array $attributes): void
{
    $settings = Setting::query()->first() ?? Setting::query()->create(['company_name' => 'Storify']);
    $settings->update($attributes);
}

function sd51Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'custody.per_store_wallets' => true,
    ]);
}

/**
 * A custody business with two stores and a default payout account.
 *
 * @return array{0: Business, 1: Store, 2: Store, 3: StoreBank}
 */
function sd51Business(int $defaultAvailableKobo = 0): array
{
    [$owner, $business] = createBusinessOwner([], ['auto_payout' => true]);

    $bank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Guaranty Trust Bank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'Ada Trading Ltd',
        'is_primary' => true,
    ]);

    $business->forceFill([
        'custody_enabled' => true,
        'custody_enabled_at' => now(),
        'payout_bank_id' => $bank->id,
        'available_balance' => $defaultAvailableKobo,
    ])->save();

    $stores = [];

    foreach ([1, 2] as $index) {
        $stores[] = Store::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'name' => 'SD51 Store '.$index,
            'slug' => 'sd51-'.$index.'-'.fake()->unique()->numerify('######'),
            'status' => Store::STATUS_ACTIVE,
            'payment_mode' => 'manual',
        ]);
    }

    return [$business->fresh(), $stores[0], $stores[1], $bank];
}

function sd51Sale(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $order = $store !== null ? Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'SD51-'.strtoupper(Str::random(10)),
        'subtotal' => $kobo / 100,
        'total' => $kobo / 100,
    ]) : null;

    return Transaction::create([
        'reference' => 'SD51-TXN-'.Str::random(12),
        'business_id' => $business->id,
        'order_id' => $order?->id,
        'amount' => $kobo / 100,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'fee_kobo' => 0,
        'platform_fee_kobo' => 0,
        'metadata' => ['payment_mode' => 'platform_managed', 'leg_method' => 'bachs'],
    ]);
}

/** A collected, matured sale, so it is payable. */
function sd51Funded(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $sale = sd51Sale($business, $kobo, $store);
    $custody = app(CustodyService::class);

    $custody->recordCollectedSale($sale, 'bachs');
    $custody->promoteSettled();

    return $sale->fresh();
}

function sd51ProviderAccepts(): void
{
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);
}

function sd51Payouts(Business $business): Collection
{
    return Payout::query()->where('business_id', $business->id)->get();
}

test('one sweep pays one transfer per destination', function () {
    sd51Platform();
    sd51Settings(['system_payment_processor' => 'bachs']);

    [$business, $first, $second, $default] = sd51Business();

    // The second store names an account of its own.
    $own = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Zenith Bank',
        'bank_code' => '057',
        'account_number' => '9876543210',
        'account_name' => 'Ada Trading Ltd',
    ]);
    $second->forceFill(['payout_bank_id' => $own->id])->save();

    sd51Funded($business, 100_000, $first);
    sd51Funded($business, 60_000, $second);

    sd51ProviderAccepts();

    app(PayoutSweepService::class)->sweep();

    $payouts = sd51Payouts($business);

    expect($payouts)->toHaveCount(2);

    $byAccount = $payouts->keyBy('account_number');

    expect($byAccount)->toHaveKeys(['0123456789', '9876543210'])
        ->and((int) $byAccount['0123456789']->amount_kobo)->toBe(100_000)
        ->and((int) $byAccount['9876543210']->amount_kobo)->toBe(60_000)
        // The destination is snapshotted on the row: a store changing its bank
        // after the reservation must not redirect a transfer already promised.
        ->and((int) $byAccount['9876543210']->payout_bank_id)->toBe($own->id);
});

test('two stores sharing the business default are paid in one transfer', function () {
    sd51Platform();
    sd51Settings(['system_payment_processor' => 'bachs']);

    [$business, $first, $second] = sd51Business();

    sd51Funded($business, 100_000, $first);
    sd51Funded($business, 60_000, $second);

    sd51ProviderAccepts();

    app(PayoutSweepService::class)->sweep();

    $payouts = sd51Payouts($business);

    // One destination, so one transfer — even though two stores earned it. Two
    // transfers to the same account would pay the provider twice for the
    // privilege and show the business a duplicated payout.
    expect($payouts)->toHaveCount(1)
        ->and((int) $payouts->first()->amount_kobo)->toBe(160_000)
        // Divided between them on the allocations, which is where the per-store
        // breakdown of a shared payout lives.
        ->and($payouts->first()->allocations()->pluck('store_id')->sort()->values()->all())
        ->toBe([(int) $first->id, (int) $second->id]);
});

test('a store is never funded by another store\'s takings', function () {
    sd51Platform();
    sd51Settings(['system_payment_processor' => 'bachs']);

    [$business, $first, $second] = sd51Business();

    $own = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Zenith Bank',
        'bank_code' => '057',
        'account_number' => '9876543210',
        'account_name' => 'Ada Trading Ltd',
    ]);
    $second->forceFill(['payout_bank_id' => $own->id])->save();

    // The first store has far more than the second. If the cap were the
    // business's whole wallet, the second store's account would receive it.
    sd51Funded($business, 500_000, $first);
    sd51Funded($business, 20_000, $second);

    sd51ProviderAccepts();

    app(PayoutSweepService::class)->sweep();

    $byAccount = sd51Payouts($business)->keyBy('account_number');

    expect((int) $byAccount['9876543210']->amount_kobo)->toBe(20_000)
        ->and((int) $byAccount['0123456789']->amount_kobo)->toBe(500_000);
});

test('money no store accounts for is paid to the business default', function () {
    sd51Platform();
    sd51Settings(['system_payment_processor' => 'bachs']);

    [$business, $first] = sd51Business();

    // A managed till leg: no order, no invoice, so no store to live on.
    sd51Funded($business, 45_000);
    sd51Funded($business, 100_000, $first);

    sd51ProviderAccepts();

    app(PayoutSweepService::class)->sweep();

    $payouts = sd51Payouts($business);

    expect($payouts)->toHaveCount(1)
        ->and((int) $payouts->first()->amount_kobo)->toBe(145_000);
});

test('a second sweep while the first is in flight reserves nothing', function () {
    sd51Platform();
    sd51Settings(['system_payment_processor' => 'bachs']);

    [$business, $first, $second] = sd51Business();

    $own = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Zenith Bank',
        'bank_code' => '057',
        'account_number' => '9876543210',
        'account_name' => 'Ada Trading Ltd',
    ]);
    $second->forceFill(['payout_bank_id' => $own->id])->save();

    sd51Funded($business, 100_000, $first);
    sd51Funded($business, 60_000, $second);

    sd51ProviderAccepts();

    app(PayoutSweepService::class)->sweep();
    app(PayoutSweepService::class)->sweep();

    // Two transfers, not four. Each destination's money was taken out of its own
    // bucket at reservation, which is what the second run finds empty.
    expect(sd51Payouts($business))->toHaveCount(2)
        ->and(sd51Payouts($business)->sum('amount_kobo'))->toBe(160_000)
        ->and((int) $first->fresh()->available_balance)->toBe(0)
        ->and((int) $second->fresh()->available_balance)->toBe(0);
});

test('the failure ceiling counts per destination, not per business', function () {
    sd51Platform();
    sd51Settings(['system_payment_processor' => 'bachs']);

    [$business, $first, $second] = sd51Business();

    $own = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Zenith Bank',
        'bank_code' => '057',
        'account_number' => '9876543210',
        'account_name' => 'Ada Trading Ltd',
    ]);
    $second->forceFill(['payout_bank_id' => $own->id])->save();

    // Three refusals attributed to the second store's destination.
    foreach (range(1, 3) as $ignored) {
        Payout::create([
            'business_id' => $business->id,
            'provider' => 'bachs',
            'bank_code' => '057',
            'account_number' => '9876543210',
            'account_name' => 'Ada Trading Ltd',
            'amount_kobo' => 1_000,
            'currency' => 'NGN',
            'status' => Payout::STATUS_FAILED,
            'reference' => 'SD51-FAIL-'.uniqid(),
            'failure_code' => 'destination_unusable',
            'failure_reason' => 'The provider will not open this account.',
        ]);
    }

    // A payout that *did* land, to the other destination. Before this was scoped,
    // it would have reset the count above and the dead destination would have
    // been retried on every run for ever.
    Payout::create([
        'business_id' => $business->id,
        'provider' => 'bachs',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'Ada Trading Ltd',
        'amount_kobo' => 5_000,
        'currency' => 'NGN',
        'status' => Payout::STATUS_PAID,
        'reference' => 'SD51-PAID-'.uniqid(),
        'paid_at' => now(),
    ]);

    $sweep = app(PayoutSweepService::class);

    // Asked about *that* destination. The business default has one payout that
    // landed and no refusals, so a business-level reading would say zero — which
    // is precisely the bug this replaced.
    expect($sweep->destinationFailures($business, $own))->toBe(3)
        ->and($sweep->destinationIsExhausted($business, $own))->toBeTrue()
        ->and($sweep->destinationFailures($business))->toBe(0)
        ->and($sweep->destinationIsExhausted($business))->toBeFalse();
});
