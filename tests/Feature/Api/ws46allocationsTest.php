<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Payout;
use App\Models\PayoutAllocation;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
| WS-46 — which sales a payout paid.
|
| A payout moves one amount for a whole business, out of a balance the business has
| earned across many sales. Both questions a person then asks — "what was this
| ₦2,500 made of" and "which payout paid me for this order" — had no answer
| anywhere in the system before the `payout_allocations` table, and the naive fix
| (a `payout_id` on the sale) answers one of them wrongly.
|
| The reason is a designed state rather than an edge case. A refund taken after a
| payout deliberately drives the wallet negative, and later sales recover it, so a
| single transfer can be made of part of one sale and part of another. Every test
| below that mentions a refund exists to pin that: the amount is the money, and the
| attribution is what the money was made of, and they are two different facts.
|
| The other half is the invariant. A payout's allocations must never claim more
| than the payout sent, and must account for all of it whenever the sales behind it
| are known — otherwise a sale can be paid twice, which is the one failure this
| whole subsystem exists to make impossible.
*/

function al46Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function al46Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
    ]);
}

/**
 * A business Storify collects for, with somewhere to send the money.
 *
 * The flag, the balances and the bank are written directly: this suite is about
 * how a balance is attributed once it is owed, not about how it was earned.
 *
 * @return array{0: Business, 1: Store}
 */
function al46Business(int $availableKobo = 0, int $pendingKobo = 0): array
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
        'available_balance' => $availableKobo,
        'pending_balance' => $pendingKobo,
    ])->save();

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'al46-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ]);

    return [$business->fresh(), $store];
}

/**
 * A collected sale whose share is exactly `$kobo`.
 *
 * Zero fees, deliberately: the assertions below are about how a set of shares is
 * split across payouts, and a fee would put a second piece of arithmetic between
 * the figure written here and the figure asserted.
 */
function al46Sale(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $order = $store !== null ? Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'ORD-'.strtoupper(Str::random(10)),
        'subtotal' => $kobo / 100,
        'total' => $kobo / 100,
    ]) : null;

    return Transaction::create([
        'reference' => 'TXN-'.strtoupper(Str::random(12)),
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

/** Collected, credited and matured — the state a sale is in when it becomes payable. */
function al46Funded(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $sale = al46Sale($business, $kobo, $store);
    $custody = app(CustodyService::class);

    $custody->recordCollectedSale($sale, 'bachs');
    $custody->promoteSettled();

    return $sale->fresh();
}

/** The provider accepting everything it is asked. */
function al46ProviderAccepts(): void
{
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);
}

function al46Payouts(?Business $business = null): Collection
{
    return Payout::query()
        ->when($business !== null, fn ($query) => $query->where('business_id', $business->id))
        ->orderBy('id')
        ->get();
}

test('a payout records the sales it paid, oldest first, and they add up to the amount', function () {
    al46Platform();
    al46Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = al46Business();

    $first = al46Funded($business, 100000, $store);
    $second = al46Funded($business, 150000, $store);

    al46ProviderAccepts();

    app(PayoutSweepService::class)->sweep();

    $payout = al46Payouts($business)->firstOrFail();
    $rows = $payout->allocations()->get();

    expect($payout->amount_kobo)->toBe(250000)
        ->and($rows)->toHaveCount(2)
        // Oldest sale first, which is the order the money was taken in.
        ->and($rows[0]->transaction_id)->toBe((int) $first->id)
        ->and($rows[0]->amount_kobo)->toBe(100000)
        ->and($rows[1]->transaction_id)->toBe((int) $second->id)
        ->and($rows[1]->amount_kobo)->toBe(150000)
        // The whole amount is accounted for. This is the invariant every other
        // test in this file is a variation on.
        ->and((int) $rows->sum('amount_kobo'))->toBe((int) $payout->amount_kobo)
        ->and($rows[0]->status)->toBe(PayoutAllocation::STATUS_RESERVED);
});

test('a store is recorded on each allocation, and a sale with no order has none', function () {
    al46Platform();
    al46Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = al46Business();

    al46Funded($business, 100000, $store);
    // A managed till leg has neither an order nor an invoice, so there is no store
    // to attribute it to. Null is the honest answer and it is what a store-scoped
    // report has to be able to see — the alternative, guessing a store, would
    // attribute one store's takings to another.
    al46Funded($business, 50000, null);

    al46ProviderAccepts();

    app(PayoutSweepService::class)->sweep();

    $rows = al46Payouts($business)->firstOrFail()->allocations()->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->store_id)->toBe((int) $store->id)
        ->and($rows[1]->store_id)->toBeNull();
});

test('a refund taken after a payout leaves the next one drawing part of a single sale', function () {
    al46Platform();
    al46Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = al46Business();

    $refunded = al46Funded($business, 100000, $store);
    al46Funded($business, 100000, $store);

    al46ProviderAccepts();

    $sweep = app(PayoutSweepService::class);
    $sweep->sweep();

    $paid = al46Payouts($business)->firstOrFail();

    expect($paid->amount_kobo)->toBe(200000);

    // The customer is refunded *after* the money went out. The business owes the
    // ₦1,000 back, so the wallet goes negative — which is the whole reason a sale
    // cannot simply point at the payout that paid it.
    app(CustodyService::class)->unwindCollectedSale($refunded, 'Refunded in full');

    expect($business->fresh()->available_balance)->toBe(-100000);

    // A later sale covers the debt, leaving ₦500 genuinely payable — and it is
    // ₦500 *of that sale*, not a rounding of the balance.
    $later = al46Funded($business, 150000, $store);

    expect($business->fresh()->available_balance)->toBe(50000);

    $sweep->sweep();

    $second = al46Payouts($business)->last();

    expect($second->id)->not->toBe($paid->id)
        ->and($second->amount_kobo)->toBe(50000)
        ->and($second->allocations()->count())->toBe(1)
        ->and($second->allocations()->first()->transaction_id)->toBe((int) $later->id)
        // Part of the sale, not all of it: the other ₦1,000 is still owed and the
        // row says so by being ₦500 rather than ₦1,500.
        ->and($second->allocations()->first()->amount_kobo)->toBe(50000);

    // And the refund did not rewrite history. The first payout really did pay both
    // sales, and a record that released the refunded sale would claim it paid
    // ₦1,000 when it sent ₦2,000 — money that left with nothing to explain it.
    expect((int) $paid->allocations()->sum('amount_kobo'))->toBe(200000);
});

test('a sale that comes back is never allocated to a payout again', function () {
    al46Platform();
    al46Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = al46Business();

    $refunded = al46Funded($business, 100000, $store);

    // Refunded before anything was ever reserved, so there is no payout to unwind
    // and no allocation to release — the money simply stops being owed.
    app(CustodyService::class)->unwindCollectedSale($refunded, 'Refunded in full');

    $kept = al46Funded($business, 40000, $store);

    al46ProviderAccepts();

    app(PayoutSweepService::class)->sweep();

    $payout = al46Payouts($business)->firstOrFail();

    // The refunded sale is not in the walk at all, and the payout is the ₦400 that
    // is still owed rather than the ₦1,400 it once was.
    expect($payout->amount_kobo)->toBe(40000)
        ->and($payout->allocations()->pluck('transaction_id')->all())->toBe([(int) $kept->id]);
});

test('a refused payout gives its sales back to the next one', function () {
    al46Platform();
    al46Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = al46Business();

    $sale = al46Funded($business, 100000, $store);

    // First attempt refused, second accepted — the sequence is what makes that
    // possible in one test, since a `Http::fake()` with the same URL pattern does
    // not replace an earlier one.
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::sequence()
            ->push(['code' => 'INSUFFICIENT_BALANCE', 'message' => 'Not enough.'], 400)
            ->push(['id' => 'pay_1', 'status' => 'pending']),
    ]);

    $sweep = app(PayoutSweepService::class);
    $sweep->sweep();

    $failed = al46Payouts($business)->firstOrFail();

    expect($failed->status)->toBe(Payout::STATUS_FAILED)
        // The money is back in the wallet, so the claim on the sale has to be gone
        // with it — otherwise the sale is unpayable: the wallet says it is owed and
        // no walk will ever pick it up.
        ->and($failed->allocations()->firstOrFail()->status)->toBe(PayoutAllocation::STATUS_RELEASED)
        ->and($failed->allocations()->firstOrFail()->released_at)->not->toBeNull();

    $sweep->sweep();

    $second = al46Payouts($business)->last();

    expect($second->amount_kobo)->toBe(100000)
        ->and($second->allocations()->firstOrFail()->transaction_id)->toBe((int) $sale->id)
        ->and($second->allocations()->firstOrFail()->status)->toBe(PayoutAllocation::STATUS_RESERVED);
});

test('a landed payout finalises its allocations, and a recall reopens them', function () {
    al46Platform();
    al46Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = al46Business();
    al46Funded($business, 100000, $store);

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'completed']),
    ]);

    $sweep = app(PayoutSweepService::class);
    $sweep->sweep();

    Payout::query()->update(['requested_at' => now()->subHour()]);

    expect($sweep->reconcile()['settled'])->toBe(1);

    $payout = al46Payouts($business)->firstOrFail();
    $row = $payout->allocations()->firstOrFail();

    expect($row->status)->toBe(PayoutAllocation::STATUS_SETTLED)
        ->and($row->settled_at)->not->toBeNull();

    // The bank sends it back a week later. The provider is the only party that can
    // tell us that, and it does — which is why the recall has to reopen the
    // allocation as well as credit the wallet, or the sale is marked paid with the
    // money back in Storify's balance.
    expect($sweep->applyPayoutWebhook([
        'reference' => $payout->reference,
        'status' => 'reversed',
        'reason' => 'Recalled by the bank',
    ], 'payout.reversed'))->toBeTrue();

    $row = $payout->allocations()->firstOrFail();

    expect($payout->fresh()->status)->toBe(Payout::STATUS_REVERSED)
        ->and($business->fresh()->available_balance)->toBe(100000)
        ->and($row->status)->toBe(PayoutAllocation::STATUS_REVERSED)
        // Kept, not cleared: it was settled, and then it was given up. Both facts
        // are worth having.
        ->and($row->settled_at)->not->toBeNull()
        ->and($row->released_at)->not->toBeNull();
});

test('a wallet with no sale behind it is still paid, and the gap is recorded', function () {
    al46Platform();
    al46Settings(['system_payment_processor' => 'bachs']);

    // A balance nothing can be drawn from: no matured sale exists. Nothing the
    // wallet itself can do produces this state, but a business is never left
    // unpaid because the attribution is thin — the wallet is the authority on what
    // is owed, and the walk is only the explanation of where it came from.
    [$business] = al46Business(availableKobo: 75000);

    al46ProviderAccepts();

    app(PayoutSweepService::class)->sweep();

    $payout = al46Payouts($business)->firstOrFail();

    expect($payout->amount_kobo)->toBe(75000)
        ->and($payout->allocations()->count())->toBe(0)
        ->and($business->fresh()->available_balance)->toBe(0);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/payouts')
        && $request->data()['amount'] === '750.00');
});
