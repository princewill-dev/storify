<?php

use App\Models\Business;
use App\Models\Store;

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
