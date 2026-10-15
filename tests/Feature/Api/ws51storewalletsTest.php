<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Services\Payments\Custody\CustodyBucket;
use App\Services\Payments\Custody\CustodyService;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-51 — the custody wallet, per store
|--------------------------------------------------------------------------
| `HasCustodyWallet` is shared with `Business`, whose behaviour is pinned by
| the wallet and custody suites. What is new is that a **store** can hold a
| bucket, and that the two are separate: a store's money must never be read off
| the business, and the business's must never be read off a store.
|
| The other thing worth pinning here is the pair of columns on `stores`. `Store`
| now has two independent monies — `balance` for sales charged on the business's
| own provider keys, and `pending_balance`/`available_balance` for what Storify
| collected. Summing them would pay out money the business already holds, so a
| test says they do not move each other.
*/

function ws51wContext(): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS51 Store',
        'slug' => 'ws51-'.uniqid(),
        'status' => Store::STATUS_ACTIVE,
    ]);

    return [$owner, $business, $store];
}

test('a store starts with an empty wallet', function () {
    [, , $store] = ws51wContext();

    // Re-read: a model built by `create()` holds only the attributes it was
    // given, so the column defaults are the database's until it is refreshed.
    $store->refresh();

    expect($store->pending_balance)->toBe(0)
        ->and($store->available_balance)->toBe(0)
        ->and($store->walletBalance())->toBe(0)
        ->and($store->payoutableBalance())->toBe(0);
});

test('a credit lands in the store bucket and moves nothing else', function () {
    [, $business, $store] = ws51wContext();

    $store->creditPending(150_000);

    expect($store->fresh()->pending_balance)->toBe(150_000)
        // Not payable until the provider settles it.
        ->and($store->fresh()->payoutableBalance())->toBe(0)
        ->and($store->fresh()->walletBalance())->toBe(150_000)
        // The business's own bucket is a different money and is untouched.
        ->and($business->fresh()->pending_balance)->toBe(0)
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('promoting moves a store from pending to available, in that store', function () {
    [, $business, $store] = ws51wContext();

    $store->creditPending(150_000);
    $store->promoteToAvailable(150_000);

    $store->refresh();

    expect($store->pending_balance)->toBe(0)
        ->and($store->available_balance)->toBe(150_000)
        ->and($store->balance_updated_at)->not->toBeNull()
        // The business did not absorb it on the way through.
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('a payout debit refuses to overdraw, and a refund is allowed to', function () {
    [, , $store] = ws51wContext();

    $store->creditAvailable(10_000);

    // A payout must never invent money — the right answer is to pay less.
    expect(fn () => $store->debitAvailable(20_000))->toThrow(Exception::class);
    expect($store->fresh()->available_balance)->toBe(10_000);

    // A refund must be able to leave the store owing Storify: the customer has
    // already been paid back from the platform's own balance, and refusing
    // would leave the debt unrecorded rather than recovered from the next sale.
    $store->debitAvailable(25_000, allowNegative: true);

    expect($store->fresh()->available_balance)->toBe(-15_000);
});

test('promoting more than is pending fails loudly rather than going negative', function () {
    [, , $store] = ws51wContext();

    $store->creditPending(5_000);

    // A promote that overdraws means the job ran twice. Failing loudly is the
    // point — quietly absorbing it would destroy money.
    expect(fn () => $store->promoteToAvailable(9_000))->toThrow(Exception::class);
    expect($store->fresh()->pending_balance)->toBe(5_000);
});

test('the store wallet and the store balance are two different monies', function () {
    [, , $store] = ws51wContext();

    // `Store::creditBalance` is the own-gateway path: sales charged on the
    // business's own provider keys, already in the business's own account.
    $store->creditBalance(70_000);
    $store->creditPending(30_000);

    $store->refresh();

    expect($store->balance)->toBe(70_000)
        ->and($store->pending_balance)->toBe(30_000)
        // `walletBalance()` reads the custody pair only. Summing the two would
        // pay the business money it has already been given.
        ->and($store->walletBalance())->toBe(30_000);
});

test('a store hold on one store does not touch another', function () {
    [, $business, $first] = ws51wContext();

    $second = Store::create([
        'user_id' => $business->owner->id,
        'business_id' => $business->id,
        'name' => 'WS51 Store Two',
        'slug' => 'ws51-two-'.uniqid(),
        'status' => Store::STATUS_ACTIVE,
    ]);

    $first->creditAvailable(40_000);
    $second->creditAvailable(60_000);

    $first->debitAvailable(40_000, allowNegative: true);

    expect($first->fresh()->available_balance)->toBe(0)
        ->and($second->fresh()->available_balance)->toBe(60_000);
});

test('the business bucket and its stores sum to the business real balance', function () {
    [, $business, $store] = ws51wContext();

    // Unattributed money — a managed POS till leg, which has neither an order
    // nor an invoice to derive a store from — stays on the business.
    $business->creditAvailable(20_000);
    $store->creditAvailable(80_000);

    $total = Business::query()->whereKey($business->id)->sum('available_balance')
        + Store::query()->where('business_id', $business->id)->sum('available_balance');

    expect((int) $total)->toBe(100_000);
});

/*
|--------------------------------------------------------------------------
| WS-51 — routing a sale to its store, behind the flag
|--------------------------------------------------------------------------
| `custody.per_store_wallets` decides whether a collected sale is credited to
| the store that earned it or to the business as a whole. It is read in exactly
| one place — `CustodyBucket` — and credit, promote and refund all resolve their
| wallet through it, so they cannot disagree about where the money is.
|
| Flag off is parity: the platform behaves as it did before stores had wallets.
| That test is here because the flag is what makes this shippable, and a parity
| claim nobody asserts is a claim nobody has checked.
*/

function ws51rBusiness(): array
{
    [$owner, $business] = createBusinessOwner([], ['auto_payout' => true]);

    $business->forceFill([
        'custody_enabled' => true,
        'custody_enabled_at' => now(),
    ])->save();

    $stores = [];

    foreach ([1, 2] as $index) {
        $stores[] = Store::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'name' => 'WS51R Store '.$index,
            'slug' => 'ws51r-'.$index.'-'.fake()->unique()->numerify('######'),
            'status' => Store::STATUS_ACTIVE,
        ]);
    }

    return [$business, $stores[0], $stores[1]];
}

/** A collected sale of exactly `$kobo`, with no fees so net == amount. */
function ws51rSale(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $order = $store !== null ? Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'WS51R-'.strtoupper(Str::random(10)),
        'subtotal' => $kobo / 100,
        'total' => $kobo / 100,
    ]) : null;

    return Transaction::create([
        'reference' => 'WS51R-TXN-'.Str::random(12),
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

test('with the flag off a sale credits the business, exactly as before', function () {
    config(['custody.per_store_wallets' => false]);

    [$business, $store] = ws51rBusiness();
    $sale = ws51rSale($business, 50_000, $store);

    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    $sale->refresh();

    expect($business->fresh()->pending_balance)->toBe(50_000)
        ->and($store->fresh()->pending_balance)->toBe(0)
        // Nothing recorded, which is what the column means for everything
        // credited before stores had wallets.
        ->and($sale->custody_store_id)->toBeNull();
});

test('with the flag on a sale credits its own store and records where it went', function () {
    config(['custody.per_store_wallets' => true]);

    [$business, $first, $second] = ws51rBusiness();
    $sale = ws51rSale($business, 50_000, $first);

    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    $sale->refresh();

    expect($first->fresh()->pending_balance)->toBe(50_000)
        ->and($second->fresh()->pending_balance)->toBe(0)
        // The business's own bucket is untouched: its money is for sales no
        // store accounts for.
        ->and($business->fresh()->pending_balance)->toBe(0)
        ->and($sale->custody_store_id)->toBe($first->id);
});

test('with the flag on a sale no store accounts for still credits the business', function () {
    config(['custody.per_store_wallets' => true]);

    [$business, $first] = ws51rBusiness();

    // A managed till leg: no order, no invoice, nothing to derive a store from.
    $sale = ws51rSale($business, 30_000);

    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    expect($business->fresh()->pending_balance)->toBe(30_000)
        ->and($first->fresh()->pending_balance)->toBe(0)
        ->and($sale->fresh()->custody_store_id)->toBeNull();
});

test('with the flag on a settled sale promotes in the store it was credited to', function () {
    config(['custody.per_store_wallets' => true]);

    [$business, $first, $second] = ws51rBusiness();
    $sale = ws51rSale($business, 50_000, $first);

    $custody = app(CustodyService::class);
    $custody->recordCollectedSale($sale, 'bachs');
    $custody->promoteSettled();

    expect($first->fresh()->pending_balance)->toBe(0)
        ->and($first->fresh()->available_balance)->toBe(50_000)
        ->and($second->fresh()->available_balance)->toBe(0)
        // The promote went to the store the credit went to. If these two could
        // disagree, the money would be stuck in one bucket with nothing to move
        // it — which is why the bucket is recorded at credit rather than derived
        // again here.
        ->and($business->fresh()->available_balance)->toBe(0)
        ->and($sale->fresh()->promoted_at)->not->toBeNull();
});

test('with the flag on a refund drives that store negative and leaves the others alone', function () {
    config(['custody.per_store_wallets' => true]);

    [$business, $first, $second] = ws51rBusiness();

    $custody = app(CustodyService::class);

    $refunded = ws51rSale($business, 50_000, $first);
    $custody->recordCollectedSale($refunded, 'bachs');
    $custody->promoteSettled();

    $kept = ws51rSale($business, 80_000, $second);
    $custody->recordCollectedSale($kept, 'bachs');
    $custody->promoteSettled();

    // The money has left: the sweep took it out of available when it reserved
    // the payout. This is the case the signed column exists for.
    $first->refresh()->debitAvailable(50_000);

    expect($first->fresh()->available_balance)->toBe(0);

    // The refund arrives after the store has been paid. It comes out of
    // available and is allowed to overdraw — Storify has paid the customer back
    // from its own balance, and the store now owes it. Refusing would leave the
    // debt unrecorded rather than recovered from the store's next sale.
    $custody->reverseCollectedSale($refunded->fresh());

    expect($first->fresh()->available_balance)->toBe(-50_000)
        // The other store's money is not the refund's business.
        ->and($second->fresh()->available_balance)->toBe(80_000);
});

test('with the flag on a refund before settlement comes out of the store pending', function () {
    config(['custody.per_store_wallets' => true]);

    [$business, $first] = ws51rBusiness();
    $sale = ws51rSale($business, 40_000, $first);

    $custody = app(CustodyService::class);
    $custody->recordCollectedSale($sale, 'bachs');

    // Not promoted, so the money is still pending — and pending never goes
    // negative, because this reverses a credit that is still sitting there.
    $custody->reverseCollectedSale($sale->fresh());

    expect($first->fresh()->pending_balance)->toBe(0)
        ->and($first->fresh()->available_balance)->toBe(0)
        ->and($business->fresh()->pending_balance)->toBe(0);
});

test('a sale pointing at another business store falls back to the business bucket', function () {
    config(['custody.per_store_wallets' => true]);

    [$business] = ws51rBusiness();
    [, , $otherStore] = ws51rBusiness();

    // Recorded on the row rather than derived — a store id from somewhere else,
    // which the bucket must refuse rather than credit.
    $sale = ws51rSale($business, 25_000);
    $sale->forceFill(['custody_store_id' => $otherStore->id])->save();

    expect(app(CustodyBucket::class)->storeIdFor($sale, $business))->toBeNull();
});
