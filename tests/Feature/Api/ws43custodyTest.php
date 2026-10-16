<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
| WS-43 — collecting for a business, and paying it out of what we collected.
|
| This is the half of the change where Storify stops being a bystander. Before it,
| the provider settled each business directly and our ledger recorded what someone
| else had done. After it, the money lands in our provider balance whole, the
| business is owed a figure we compute, and a job of ours moves it. Every mistake
| in this file is therefore a mistake about money we are holding for somebody else,
| and the tests are written around the three ways that goes wrong:
|
| 1. **Paying before we are paid.** A confirmed sale is owed immediately but is not
|    necessarily settled *to us*. So it lands in `pending`, and the sweep draws only
|    `available`. The test that matters most here is the one that proves an
|    unsettled credit is refused rather than paid.
| 2. **Paying twice.** A provider we could not reach is not a provider that refused.
|    Its reservation has to stay spent, or a retry sends a genuine second transfer
|    to a business that already has the money.
| 3. **Owning money we do not own.** The business's books must show the money held
|    by us, not sitting in a gateway account of theirs; ours must show it as a
|    liability, not as income. The journal is asserted against the same ₦2,500 sale
|    the plan walks through, and the platform's clearing balance is required to end
|    at exactly the commission.
|
| Nothing here runs against a live provider, and nothing here runs against a
| database other than `storify_test` — `tests/TestCase.php` refuses the rest.
*/

function ws43Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

/**
 * A business Storify collects for, with somewhere to send the money.
 *
 * `custody_enabled` and the bank are set directly rather than through
 * `enableCustody()` and the payout-account flow: those are admin actions with
 * their own steps, and a test about what the wallet does should not have to walk a
 * signup to reach it.
 *
 * @return array{0: Business, 1: StoreBank}
 */
function ws43CustodyBusiness(int $availableKobo = 0, int $pendingKobo = 0): array
{
    [, $business] = createBusinessOwner([], ['auto_payout' => true]);

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

    return [$business->fresh(), $bank];
}

function ws43PlatformBachs(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
    ]);
}

/**
 * A collected sale settled: ₦2,500 gross, ₦50 provider fee, ₦62.50 commission.
 *
 * The same figures the plan walks through, so the journal asserted below is the one
 * that was designed rather than one that happened to come out.
 */
function ws43Sale(Business $business, array $attributes = []): Transaction
{
    return Transaction::create(array_merge([
        'reference' => 'TXN-'.strtoupper(Str::random(12)),
        'business_id' => $business->id,
        'amount' => 2500.00,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'fee_kobo' => 5000,
        'platform_fee_kobo' => 6250,
        'metadata' => ['payment_mode' => 'platform_managed', 'leg_method' => 'bachs'],
    ], $attributes));
}

/**
 * A custody business with ₦2,387.50 genuinely settled and payable — reached by
 * selling and maturing, not by writing a balance on.
 *
 * @return array{0: Business, 1: StoreBank}
 */
function ws43FundedBusiness(): array
{
    [$business, $bank] = ws43CustodyBusiness();
    $custody = app(CustodyService::class);

    $custody->recordCollectedSale(ws43Sale($business, ['paid_at' => now()->subDays(3)]), 'bachs');
    $custody->promoteSettled();

    return [$business->fresh(), $bank];
}

/**
 * Journal lines keyed `code:dr` / `code:cr`, in the order they were posted.
 *
 * @return array<string, int>
 */
function ws43Lines(JournalEntry $entry): array
{
    return $entry->lines()->get()->mapWithKeys(fn ($line) => [
        $line->account->code.($line->debit_kobo > 0 ? ':dr' : ':cr') => $line->debit_kobo > 0
            ? (int) $line->debit_kobo
            : (int) $line->credit_kobo,
    ])->all();
}

test('a sale collected before the business was rolled back is still credited to it', function () {
    [$business] = ws43CustodyBusiness();

    // The sale went out on Storify's credentials, so the money is sitting in
    // Storify's provider balance — and it settles up to 48 hours later, which is
    // more than enough time for an operator to roll the business back.
    $transaction = ws43Sale($business);

    $business->forceFill(['custody_enabled' => false, 'custody_enabled_at' => null])->save();

    $credited = app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');

    // Credited anyway, because the question is not whether the business is still
    // being collected for — it is whether *this sale* was, and the row says it was.
    // Reading the flag here instead left the money un-credited while it sat in
    // Storify's own balance: the business was neither paid by the provider (there
    // is no account at the provider to be paid into any more) nor owed by us. The
    // books agreed with the mistake, because the same flag decided the posting.
    expect($credited)->toBeTrue()
        ->and($business->fresh()->pending_balance)->toBe(238750)
        ->and($transaction->fresh()->settle_at)->not->toBeNull();
});

test('a collected sale is credited to pending with a settlement date', function () {
    [$business] = ws43CustodyBusiness();

    $transaction = ws43Sale($business);

    expect(app(CustodyService::class)->recordCollectedSale($transaction, 'bachs'))->toBeTrue();

    $fresh = $transaction->fresh();

    // ₦2,500 less the ₦50 provider fee and the ₦62.50 commission.
    expect($business->fresh()->pending_balance)->toBe(238750)
        ->and($business->fresh()->available_balance)->toBe(0)
        ->and($fresh->settle_at)->not->toBeNull();

    // Due the moment it was paid, because the window is now a *floor* rather than
    // the gate: Bachs publishes a spendable balance and refuses a transfer drawn on
    // the unsettled part of it, so the provider's own answer decides when this
    // money is payable and a clock would only be a worse version of it. What holds
    // the sale back is `PayoutSweepService::promotionGate()`.
    expect((int) $fresh->paid_at->diffInHours($fresh->settle_at))->toBe(0);
});

test('crediting the same collected sale twice credits it once', function () {
    [$business] = ws43CustodyBusiness();

    $transaction = ws43Sale($business);
    $custody = app(CustodyService::class);

    // A webhook, its retry, and the customer's return-leg verify all land on the same
    // sale. All three are ordinary.
    $custody->recordCollectedSale($transaction, 'bachs');

    expect($custody->recordCollectedSale($transaction->fresh(), 'bachs'))->toBeFalse()
        ->and($custody->recordCollectedSale($transaction->fresh(), 'bachs'))->toBeFalse()
        ->and($business->fresh()->pending_balance)->toBe(238750);
});

test('two copies of one sale racing each other still credit it once', function () {
    [$business] = ws43CustodyBusiness();

    $transaction = ws43Sale($business);
    $custody = app(CustodyService::class);

    // The credit is made against *stale* copies — two instances of the row, read
    // before either call ran, exactly as two concurrent requests would each hold one.
    // The cheap `settle_at` check on both says "not yet credited", so what stops the
    // double credit is the conditional claim inside, not the read.
    $a = Transaction::query()->findOrFail($transaction->id);
    $b = Transaction::query()->findOrFail($transaction->id);

    expect($custody->recordCollectedSale($a, 'bachs'))->toBeTrue()
        ->and($custody->recordCollectedSale($b, 'bachs'))->toBeFalse()
        ->and($business->fresh()->pending_balance)->toBe(238750);
});

test('a sale whose provider fee is unknown is not credited at all', function () {
    [$business] = ws43CustodyBusiness();

    // A till leg: the provider does not report what it charged until the charge
    // settles, days later. Crediting the commission alone would understate what the
    // business is owed, and crediting the gross would overstate it by a fee that is
    // coming. Neither is a number a later correction would ever be visible as, so the
    // sale waits.
    $transaction = ws43Sale($business, ['fee_kobo' => null]);

    expect(app(CustodyService::class)->recordCollectedSale($transaction, 'bachs'))->toBeFalse()
        ->and($business->fresh()->walletBalance())->toBe(0)
        ->and($transaction->fresh()->settle_at)->toBeNull();
});

test('a sale that could not be priced is credited once the fee arrives', function () {
    [$business] = ws43CustodyBusiness();

    $transaction = ws43Sale($business, ['fee_kobo' => null, 'paid_at' => now()->subDays(4)]);
    $custody = app(CustodyService::class);

    expect($custody->recordCollectedSale($transaction, 'bachs'))->toBeFalse()
        ->and($custody->promoteSettled()['uncredited'])->toBe(1);

    // The provider settles and reports its charge.
    $transaction->forceFill(['fee_kobo' => 5000])->save();

    $counts = $custody->promoteSettled();

    expect($counts['uncredited'])->toBe(0)
        // Four days of settlement window are already behind it, so the catch-up pass
        // credits it and the same run matures it — the business is not made to wait a
        // second window for a sale that settled while we could not price it.
        ->and($counts['matured'])->toBe(1)
        ->and($business->fresh()->available_balance)->toBe(238750);
});

/*
|--------------------------------------------------------------------------
| The wallet's currency
|--------------------------------------------------------------------------
*/

test('a sale priced in another currency is not credited to the wallet', function () {
    [$business] = ws43CustodyBusiness();

    // A $5,000 sale collected into a naira account. The figure is chosen so the
    // arithmetic *would* produce a credit if it were let through — ₦4,887.50 — which
    // is the point: the danger is not an error, it is a number that looks like a
    // payment. The store's amount has been read as naira, and the provider fee and
    // commission beside it are the naira figures that were actually charged, so the
    // "net" is the difference between two currencies.
    $transaction = ws43Sale($business, ['currency' => 'USD', 'amount' => 5000.00]);

    expect(app(CustodyService::class)->recordCollectedSale($transaction, 'bachs'))->toBeFalse()
        ->and($business->fresh()->walletBalance())->toBe(0)
        // No settlement date, so the promote run cannot find it either: the marker
        // is the only way in, and it was never stamped.
        ->and($transaction->fresh()->settle_at)->toBeNull();
});

test('the platform books refuse a collection the wallet would not hold', function () {
    [$business] = ws43CustodyBusiness();

    $transaction = ws43Sale($business, ['currency' => 'USD', 'amount' => 5000.00]);

    // The same refusal, on the other half. Both have to refuse, and neither is
    // enough on its own: a wallet credited with no journal entry is money that
    // cannot be reconciled, and an entry with no credit is a liability to a
    // business the wallet says is owed nothing. Both mirror
    // `Naira::koboFromRounded($transaction->amount)`, which is why the mistake is
    // invisible in the books — they agree with it.
    expect(app(LedgerPostingService::class)->postCustodyCollection($transaction, 488750))->toBeNull()
        ->and(JournalEntry::query()->whereNull('business_id')->count())->toBe(0);
});

test('promotion moves money between buckets without changing the total', function () {
    [$business] = ws43CustodyBusiness();

    $transaction = ws43Sale($business, ['paid_at' => now()->subDays(3)]);
    $custody = app(CustodyService::class);

    $custody->recordCollectedSale($transaction, 'bachs');

    $beforeTotal = $business->fresh()->walletBalance();

    $counts = $custody->promoteSettled();

    expect($counts['matured'])->toBe(1)
        ->and($business->fresh()->available_balance)->toBe(238750)
        ->and($business->fresh()->pending_balance)->toBe(0)
        // Equal before and after, which is what makes it safe to run as often as the
        // scheduler likes.
        ->and($business->fresh()->walletBalance())->toBe($beforeTotal);
});

test('a sale the provider has not funded is not promoted, and matures when it has', function () {
    [$business] = ws43CustodyBusiness();

    $transaction = ws43Sale($business);
    $custody = app(CustodyService::class);

    $custody->recordCollectedSale($transaction, 'bachs');

    // What the settlement window used to do, now done by the provider's own
    // balance. The sale is due, and there is nothing spendable behind it, so
    // nothing moves — and nothing is lost, because the row stays payable.
    expect($custody->promoteSettled(0)['matured'])->toBe(0)
        ->and($business->fresh()->available_balance)->toBe(0)
        ->and($business->fresh()->pending_balance)->toBe(238750);

    // One kobo short is still short. The walk stops at the first sale it cannot
    // cover rather than paying it out of money that is not in the provider's
    // balance, which is the whole property the gate exists for.
    $short = $custody->promoteSettled(238749);

    expect($short['matured'])->toBe(0)
        ->and($short['budget_limited'])->toBe(1);

    expect($custody->promoteSettled(238750)['matured'])->toBe(1)
        ->and($business->fresh()->available_balance)->toBe(238750)
        ->and($business->fresh()->pending_balance)->toBe(0);
});

test('a provider whose balance cannot be read falls back to the settlement window', function () {
    // The one case the budget cannot cover, and the reason `default_hours` still
    // matters with every provider's own window set to nothing: a balance nobody
    // could read must degrade the run to the slow path, not to an unverified
    // instant payout.
    [$business] = ws43CustodyBusiness();

    $custody = app(CustodyService::class);
    $floor = now()->subHours(72);

    $custody->recordCollectedSale(ws43Sale($business, ['paid_at' => now()->subHours(2)]), 'bachs');

    expect($custody->promoteSettled(null, $floor)['matured'])->toBe(0);

    $custody->recordCollectedSale(ws43Sale($business, ['paid_at' => now()->subDays(5)]), 'bachs');

    // Past the floor, so it matures. An unaskable provider delays a business's
    // money rather than freezing it — the two errors are not symmetric and this is
    // the cheaper one.
    expect($custody->promoteSettled(null, $floor)['matured'])->toBe(1);
});

test('a sale is promoted once even when the promote job runs twice', function () {
    [$business] = ws43CustodyBusiness();

    $transaction = ws43Sale($business, ['paid_at' => now()->subDays(3)]);
    $custody = app(CustodyService::class);

    $custody->recordCollectedSale($transaction, 'bachs');

    expect($custody->promoteSettled()['matured'])->toBe(1)
        ->and($custody->promoteSettled()['matured'])->toBe(0)
        ->and($business->fresh()->available_balance)->toBe(238750);
});

/*
|--------------------------------------------------------------------------
| The promotion gate
|--------------------------------------------------------------------------
|
| What the provider is holding has to be turned into "how much may mature", and
| the arithmetic is where this can go wrong quietly. The balance is one pot shared
| by every business and by commission already earned, so two things are subtracted
| before it can be spent: what the wallets already say is owed, and what has
| already been sent to the provider against that balance. Counting either twice
| matures money that is not there, and the provider refuses the payout that follows
| — which reads as a broken bank account rather than as a mistake in this sum.
*/

test('the promote gate measures the provider balance against what is still owed', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43CustodyBusiness(availableKobo: 100000);

    // ₦500 already sent through this provider and still unresolved. It came out of
    // the same balance and is accounted for in the business's wallet, so it is
    // subtracted here as money that is spoken for rather than money we hold.
    Payout::create([
        'business_id' => $business->id,
        'provider' => 'bachs',
        'account_number' => '0123456789',
        'amount_kobo' => 50000,
        'currency' => 'NGN',
        'status' => Payout::STATUS_PROCESSING,
        'reference' => 'PO-GATE-INFLIGHT',
    ]);

    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'balances' => [['currency' => 'NGN', 'available_balance' => '5000.00']],
    ])]);

    $gate = app(PayoutSweepService::class)->promotionGate();

    // 500,000 held − 100,000 owed to wallets − 50,000 already sent − 10,000 of
    // fee headroom. The headroom is there because Bachs charges its fee on top of
    // the amount, so a budget that exactly equalled the balance would fund a
    // payout the provider then refuses.
    expect($gate['measured'])->toBeTrue()
        ->and($gate['floor'])->toBeNull()
        ->and($gate['budget'])->toBe(340000);
});

test('a provider holding nothing gates the run down to nothing', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    ws43CustodyBusiness(pendingKobo: 238750);

    // The provider answered, and the answer is "nothing spendable". This is the
    // one case where a zero is a real statement about money, and it must not be
    // confused with the unreadable case below.
    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'balances' => [['currency' => 'NGN', 'available_balance' => '0.00']],
    ])]);

    $gate = app(PayoutSweepService::class)->promotionGate();

    expect($gate['measured'])->toBeTrue()
        ->and($gate['budget'])->toBe(0);
});

test('a gate with no processor selected is inert rather than closed', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => null]);

    // Nothing is being collected for, so there is no payout for a gate to protect
    // and no balance to read. Maturing on the window alone is right here: the money
    // is owed to a business that will be paid once a provider is selected, and
    // stalling at the pre-cut window would be a delay with nothing behind it.
    $gate = app(PayoutSweepService::class)->promotionGate();

    expect($gate['budget'])->toBeNull()
        ->and($gate['floor'])->toBeNull()
        ->and($gate['measured'])->toBeFalse();
});

test('a provider balance that cannot be read gates the run on the fallback window', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['message' => 'Internal error'], 500)]);

    $gate = app(PayoutSweepService::class)->promotionGate();

    // No budget, and a floor instead. That pairing is the whole safety property:
    // an unaskable provider does not become an unverified instant payout, it
    // becomes the clock-driven path this used to be.
    expect($gate['measured'])->toBeFalse()
        ->and($gate['budget'])->toBeNull()
        ->and($gate['floor'])->not->toBeNull()
        ->and($gate['floor']->diffInMinutes(now()->subHours(72)))->toBeLessThan(1);

    expect(app(PayoutSweepService::class)->providerAvailableKobo())->toBeNull();
});

test('the promote run matures what the provider can cover and reports the rest', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43CustodyBusiness();
    $custody = app(CustodyService::class);

    // Two settled sales of ₦2,387.50 each — ₦4,775 owed — against ₦4,000 the
    // provider is actually holding. Enough for one of them, not both.
    $custody->recordCollectedSale(ws43Sale($business, ['paid_at' => now()->subDay()]), 'bachs');
    $custody->recordCollectedSale(ws43Sale($business, ['paid_at' => now()->subDay()]), 'bachs');

    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'balances' => [['currency' => 'NGN', 'available_balance' => '4000.00']],
    ])]);

    $this->artisan('custody:promote')
        ->expectsOutputToContain('Matured 1, still settling 1, unpriced 0.')
        ->expectsOutputToContain('past their window but the provider has not released the money behind them')
        ->assertSuccessful();

    // And nothing is lost by holding the second one back: it stays payable, and
    // the next run matures it once the provider has released the rest.
    expect($business->fresh()->available_balance)->toBe(238750)
        ->and($business->fresh()->pending_balance)->toBe(238750);
});

test('the promote run says when the provider could not be asked', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43CustodyBusiness();

    // Paid two hours ago: inside the 72h fallback window, so it does not mature —
    // but the sentence the operator needs is "we could not read the balance", not
    // "still settling", because those are different problems.
    app(CustodyService::class)->recordCollectedSale(ws43Sale($business), 'bachs');

    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['message' => 'Unauthorized'], 401)]);

    $this->artisan('custody:promote')
        ->expectsOutputToContain('The provider\'s balance could not be read')
        ->assertSuccessful();

    expect($business->fresh()->pending_balance)->toBe(238750);
});

test('the platform books recognise the collection, the commission and the fee', function () {
    [$business] = ws43CustodyBusiness();

    $transaction = ws43Sale($business);

    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');

    $entry = JournalEntry::query()
        ->whereNull('business_id')
        ->where('idempotency_key', 'managed:collect:txn:'.$transaction->id)
        ->firstOrFail();

    // The whole charge lands in clearing, and the three claims on it leave on three
    // lines: what the business is owed, what we earned, and what the provider has
    // already taken off the top.
    expect(ws43Lines($entry))->toBe([
        '1030:dr' => 250000,
        '2300:cr' => 238750,
        '4020:cr' => 6250,
        '2210:cr' => 5000,
    ]);
});

test('the collection entry refuses a net the wallet did not credit', function () {
    [$business] = ws43CustodyBusiness();

    $transaction = ws43Sale($business);

    // The figure the wallet hands over and the figures on the row have to agree,
    // because they are the same money seen twice. Absorbing a difference into a
    // balancing line would hide a drift between the wallet and the journal
    // permanently, and the only thing that would ever find it is a business being paid
    // the wrong amount.
    expect(fn () => app(LedgerPostingService::class)->postCustodyCollection($transaction, 238000))
        ->toThrow(RuntimeException::class);

    expect(JournalEntry::query()->whereNull('business_id')->count())->toBe(0);
});

test('the provider settling its fee moves it out of the payable account', function () {
    [$business] = ws43CustodyBusiness();

    $transaction = ws43Sale($business);
    $ledger = app(LedgerPostingService::class);

    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');
    $ledger->postCustodyProviderFeeSettled($transaction, 5000);

    $entry = JournalEntry::query()
        ->where('idempotency_key', 'managed:provider_fee:txn:'.$transaction->id)
        ->firstOrFail();

    // The fee stops being something we owe the provider and becomes something the
    // provider has taken. Clearing falls by exactly that much, which is the same
    // movement the provider made in its own balance.
    expect(ws43Lines($entry))->toBe(['2210:dr' => 5000, '1030:cr' => 5000]);
});

/*
|--------------------------------------------------------------------------
| Releasing a reservation
|--------------------------------------------------------------------------
*/

test('a payout the provider refused gives the money straight back to the business', function () {
    [$business] = ws43CustodyBusiness(availableKobo: 238750);
    $custody = app(CustodyService::class);

    $custody->releaseForPayout($business, 238750);

    expect($business->fresh()->available_balance)->toBe(0);

    $custody->restoreRefusedPayout($business->fresh(), 238750);

    // Back to `available`, not `pending`: the money had already settled, and making
    // the business wait a second settlement window for a transfer that was declined on
    // the spot would be a delay with no reason behind it.
    expect($business->fresh()->available_balance)->toBe(238750)
        ->and($business->fresh()->pending_balance)->toBe(0);
});

test('a payout can never overdraw the wallet', function () {
    [$business] = ws43CustodyBusiness(availableKobo: 100000);

    expect(fn () => app(CustodyService::class)->releaseForPayout($business, 238750))
        ->toThrow(Exception::class);

    // Refused, so nothing moved. The alternative — paying the difference — is Storify
    // advancing money it does not have.
    expect($business->fresh()->available_balance)->toBe(100000);
});

test('a refused payout restores a wallet a refund had pushed negative', function () {
    [$business] = ws43CustodyBusiness();
    $business = $business->fresh();

    // A refund taken after a payout: the business has the cash, so the debt is
    // recorded as a negative balance to be recovered from the next sale.
    $business->debitAvailable(50000, allowNegative: true);

    expect($business->fresh()->available_balance)->toBe(-50000);

    app(CustodyService::class)->restoreRefusedPayout($business->fresh(), 30000);

    // −₦500 plus ₦300 is −₦200, not a refusal. A restore that could not run below zero
    // would leave the failed payout's money unrecorded instead.
    expect($business->fresh()->available_balance)->toBe(-20000);
});

/*
|--------------------------------------------------------------------------
| Reversing a collected sale
|--------------------------------------------------------------------------
*/

test('a refund before promotion takes the money back out of pending', function () {
    [$business] = ws43CustodyBusiness();
    $custody = app(CustodyService::class);

    $transaction = ws43Sale($business);

    $custody->recordCollectedSale($transaction, 'bachs');
    $custody->reverseCollectedSale($transaction->fresh());

    expect($business->fresh()->pending_balance)->toBe(0)
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('a refund after promotion takes it out of available', function () {
    [$business] = ws43CustodyBusiness();
    $custody = app(CustodyService::class);

    $transaction = ws43Sale($business, ['paid_at' => now()->subDays(3)]);

    $custody->recordCollectedSale($transaction, 'bachs');
    $custody->promoteSettled();
    $custody->reverseCollectedSale($transaction->fresh());

    // Read from `promoted_at` rather than passed in by the caller: which bucket the
    // money is in is a fact about the row, and a caller that had to know it would be a
    // caller that could get it wrong.
    expect($business->fresh()->available_balance)->toBe(0)
        ->and($business->fresh()->pending_balance)->toBe(0);
});

test('a refund after payout leaves the business owing Storify', function () {
    [$business] = ws43CustodyBusiness();
    $custody = app(CustodyService::class);

    $transaction = ws43Sale($business, ['paid_at' => now()->subDays(3)]);

    $custody->recordCollectedSale($transaction, 'bachs');
    $custody->promoteSettled();
    $custody->releaseForPayout($business->fresh(), 238750);

    expect($business->fresh()->available_balance)->toBe(0);

    $custody->reverseCollectedSale($transaction->fresh());

    // The business has the cash and Storify refunded the customer from its own
    // balance. Refusing to record that would leave the debt unrecorded rather than
    // recovered from the next sale, which is the worse of the two.
    expect($business->fresh()->available_balance)->toBe(-238750);
});

/*
|--------------------------------------------------------------------------
| The sweep
|--------------------------------------------------------------------------
*/

test('the sweep pays a custody business out of the platform balance', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);

    $counts = app(PayoutSweepService::class)->sweep();

    expect($counts['sent'])->toBe(1)
        ->and($counts['failed'])->toBe(0);

    $payout = Payout::query()->where('business_id', $business->id)->firstOrFail();

    expect($payout->status)->toBe(Payout::STATUS_PROCESSING)
        ->and($payout->amount_kobo)->toBe(238750)
        ->and($payout->provider_payout_id)->toBe('pay_1')
        // The reservation is spent: the money left `available` when the row was
        // written, and it does not come back unless the provider refuses.
        ->and($business->fresh()->available_balance)->toBe(0);

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Account-Id') === false
        && str_ends_with($request->url(), '/payouts')
        && $request->data()['amount'] === '2387.50');
});

test('an unsettled credit is not payable, however much is owed', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    // ₦2,387.50 owed and still settling — the provider has not released it to us.
    [$business] = ws43CustodyBusiness(pendingKobo: 238750);

    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['id' => 'pd_new', 'is_usable' => true])]);

    $counts = app(PayoutSweepService::class)->sweep();

    // This is the safety property the whole two-bucket design exists for. The business
    // is owed the money; paying it now would be Storify advancing funds it is still
    // waiting on, and several businesses doing it at once overdraws the platform's own
    // provider balance.
    expect($counts['sent'])->toBe(0)
        ->and($counts['skipped'])->toBe(0)
        ->and(Payout::query()->count())->toBe(0)
        ->and($business->fresh()->pending_balance)->toBe(238750);

    Http::assertNothingSent();
});

test('a payout the provider refuses puts the reservation back', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response([
            'code' => 'INSUFFICIENT_BALANCE',
            'message' => 'Your balance is not enough for this payout.',
        ], 400),
    ]);

    $counts = app(PayoutSweepService::class)->sweep();

    $payout = Payout::query()->firstOrFail();

    expect($counts['failed'])->toBe(1)
        ->and($payout->status)->toBe(Payout::STATUS_FAILED)
        ->and($payout->failure_code)->toBe('INSUFFICIENT_BALANCE')
        // The provider answered and did not move the money, so the business keeps it
        // and the next sweep tries again.
        ->and($business->fresh()->available_balance)->toBe(238750);
});

test('a payout the provider never answered keeps its reservation', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response('upstream is down', 503),
    ]);

    app(PayoutSweepService::class)->sweep();

    $payout = Payout::query()->firstOrFail();

    // The one that costs money if it is wrong. The provider may have moved the funds;
    // releasing the reservation here would let the next run send a second, genuine
    // transfer to a business that already has the first.
    expect($payout->status)->toBe(Payout::STATUS_PROCESSING)
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('a second sweep does not pay a balance the first already took', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response([
            'code' => 'INSUFFICIENT_BALANCE',
            'message' => 'Your balance is not enough for this payout.',
        ], 400),
    ]);

    $sweep = app(PayoutSweepService::class);

    $sweep->sweep();
    $sweep->sweep();

    // Two payouts, because the first was refused and gave the money back — which is
    // correct, and the opposite of the bug. A wallet does not empty itself, so two
    // genuine payouts of the same amount to the same business are ordinary, and the
    // reference is what keeps them apart.
    $references = Payout::query()->pluck('reference')->all();

    expect($references)->toHaveCount(2)
        ->and(array_unique($references))->toHaveCount(2)
        ->and($business->fresh()->available_balance)->toBe(238750);
});

test('a business holding its money is not paid', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();
    $business->forceFill(['auto_payout' => false])->save();

    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['id' => 'pd_new'])]);

    $counts = app(PayoutSweepService::class)->sweep();

    expect($counts['held'])->toBe(1)
        ->and($counts['sent'])->toBe(0)
        ->and($business->fresh()->available_balance)->toBe(238750);

    Http::assertNothingSent();
});

test('a business with no bank on file is reported rather than skipped silently', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();
    $business->forceFill(['payout_bank_id' => null])->save();

    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['id' => 'pd_new'])]);

    $counts = app(PayoutSweepService::class)->sweep();

    // Counted, not filtered out of the query: this is Storify holding money its owner
    // cannot receive, and it does not resolve on its own.
    expect($counts['skipped'])->toBe(1)
        ->and($business->fresh()->available_balance)->toBe(238750);

    Http::assertNothingSent();
});

test('the sweep refuses to run when nothing is selected to pay through', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => null]);

    // Refused, not defaulted. Paying a business through a provider nobody selected is
    // worse than not paying it: the next sweep would try again through the right one,
    // and the business would be paid twice.
    expect(app(PayoutSweepService::class)->unavailableReason())
        ->toContain('No system payment processor is selected');
});

test('the sweep refuses to run on a provider with no platform keys', function () {
    ws43Settings(['system_payment_processor' => 'bachs']);
    config(['services.bachs.api_key' => null]);

    expect(app(PayoutSweepService::class)->unavailableReason())
        ->toContain('credentials are not configured');
});

/*
|--------------------------------------------------------------------------
| Reconciliation
|--------------------------------------------------------------------------
*/

test('a payout the provider confirms is settled and posted to both sets of books', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'completed']),
    ]);

    $sweep = app(PayoutSweepService::class);

    $sweep->sweep();

    // The provider accepted it minutes ago and no webhook arrived. Without this pass
    // the row would sit in `processing` forever while the business had the money —
    // paid, and not recorded as paid.
    Payout::query()->update(['requested_at' => now()->subHour()]);

    expect($sweep->reconcile()['settled'])->toBe(1);

    $payout = Payout::query()->firstOrFail();

    expect($payout->status)->toBe(Payout::STATUS_PAID)
        ->and($payout->paid_at)->not->toBeNull();

    $businessEntry = JournalEntry::query()
        ->where('idempotency_key', 'payout:'.$payout->reference)
        ->firstOrFail();

    // The business's money is in its bank and no longer held by us — 1020 against
    // 1040, not against a gateway account of its own.
    expect(ws43Lines($businessEntry))->toBe(['1020:dr' => 238750, '1040:cr' => 238750]);

    $platformEntry = JournalEntry::query()
        ->whereNull('business_id')
        ->where('idempotency_key', 'custody_payout:'.$payout->reference)
        ->firstOrFail();

    // We are no longer holding it, so the liability goes and clearing with it.
    expect(ws43Lines($platformEntry))->toBe(['2300:dr' => 238750, '1030:cr' => 238750]);
});

test('a payout the provider reversed gives the money back and unwinds the books', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::sequence()
            ->push(['id' => 'pay_1', 'status' => 'completed'])
            ->push(['id' => 'pay_1', 'status' => 'reversed']),
    ]);

    $sweep = app(PayoutSweepService::class);

    $sweep->sweep();
    Payout::query()->update(['requested_at' => now()->subHour()]);
    $sweep->reconcile();

    $payout = Payout::query()->firstOrFail();
    $paidEntry = JournalEntry::query()
        ->where('idempotency_key', 'payout:'.$payout->reference)
        ->firstOrFail();

    // It landed, and then the bank gave it back. The business no longer has the money
    // and Storify does, so both the wallet and the two entries that said "paid out"
    // have to be undone.
    //
    // Put back to `processing` first, because this is the *lookup* route: `reconcile()`
    // only ever asks about a row it still believes is moving, so a settled row is not
    // one it can find. A reversal of an already-paid payout therefore arrives by webhook
    // and nowhere else — `ws39connectwebhooksTest`, "a payout the provider reverses
    // after it landed" — and the status is set here so this test is about what
    // `resolve()` does with the provider's answer rather than about how the row got there.
    Payout::query()->update(['requested_at' => now()->subHour(), 'status' => Payout::STATUS_PROCESSING]);

    expect($sweep->reconcile()['reversed'])->toBe(1);

    expect($payout->fresh()->status)->toBe(Payout::STATUS_REVERSED)
        ->and($business->fresh()->available_balance)->toBe(238750)
        ->and($paidEntry->fresh()->status)->toBe(JournalEntry::STATUS_VOID);

    // The unwinding entry is the mirror of the one it voids, under its own key.
    $returned = JournalEntry::query()
        ->where('idempotency_key', 'payout_returned:'.$payout->reference)
        ->firstOrFail();

    expect(ws43Lines($returned))->toBe(['1020:cr' => 238750, '1040:dr' => 238750]);
});

test('a payout we cannot look up is left alone, not released', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        // An accepted send that never came back with an id. Bachs has no lookup by our
        // own reference, so there is nothing to ask.
        'sandbox-api.bachs.io/v1/payouts' => Http::response('upstream is down', 503),
    ]);

    $sweep = app(PayoutSweepService::class);

    $sweep->sweep();
    Payout::query()->update(['requested_at' => now()->subHour()]);

    expect($sweep->reconcile()['unresolved'])->toBe(1)
        ->and($business->fresh()->available_balance)->toBe(0)
        ->and(Payout::query()->firstOrFail()->status)->toBe(Payout::STATUS_PROCESSING);
});

test('a reservation whose send never completed is resent with the same reference', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43CustodyBusiness();

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);

    // A row reserved and never sent: the process died between the two. The money is
    // out of the wallet and we do not know whether the request went out.
    $payout = Payout::reserve(
        businessId: $business->id,
        storeId: null,
        provider: 'bachs',
        accountNumber: '0123456789',
        bankCode: '058',
        accountName: 'Ada Trading Ltd',
        amountKobo: 238750,
    );

    $payout->forceFill(['requested_at' => now()->subHour()])->save();

    expect(app(PayoutSweepService::class)->reconcile()['resent'])->toBe(1);

    // Resent, not cancelled. The reference is the idempotency key, so a provider that
    // already has it de-duplicates — and cancelling would release a reservation for a
    // transfer that may be in flight, which is the double payment this whole design
    // exists to prevent.
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/payouts')
        && $request->header('Idempotency-Key')[0] === $payout->reference);

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PROCESSING)
        ->and($payout->fresh()->attempts)->toBe(1);
});

test('a payout sent moments ago is not chased yet', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();

    // Only the destinations and the send are stubbed. A lookup would find nothing to
    // answer with, so anything that reaches the provider again shows up as an
    // `unresolved` rather than passing quietly.
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);

    $sweep = app(PayoutSweepService::class);

    expect($sweep->sweep()['sent'])->toBe(1);

    // The row was reserved seconds ago and the provider has only just been told. An
    // accepted transfer normally resolves by webhook within minutes, so a reconcile
    // that chased it immediately would ask the provider the same question on every
    // run and read back "still pending" each time.
    expect($sweep->reconcile())
        ->toMatchArray(['resent' => 0, 'failed' => 0, 'reversed' => 0, 'settled' => 0, 'unresolved' => 0]);

    // …and once it is old enough, it is chased.
    Payout::query()->update(['requested_at' => now()->subHour()]);

    expect($sweep->reconcile()['unresolved'])->toBe(1);
});

test('the sweep only pays what the promote run has made payable', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    // One business with money the provider has not funded, one already payable.
    [$unfunded] = ws43CustodyBusiness();
    [$payable] = ws43CustodyBusiness(availableKobo: 100000);

    app(CustodyService::class)->recordCollectedSale(ws43Sale($unfunded), 'bachs');

    // The promote run is handed a budget of nothing, standing in for a provider
    // balance that does not cover the new sale yet, so its money stays in
    // `pending`. That bucket is the one the sweep never draws on, and the property
    // this test has always pinned survives the settlement window going to zero:
    // whatever holds a credit out of `available_balance`, the sweep cannot reach it.
    app(CustodyService::class)->promoteSettled(0);

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);

    app(PayoutSweepService::class)->sweep();

    expect($unfunded->fresh()->available_balance)->toBe(0)
        ->and($unfunded->fresh()->pending_balance)->toBe(238750)
        ->and($payable->fresh()->available_balance)->toBe(0)
        ->and(Payout::query()->count())->toBe(1)
        ->and(Payout::query()->firstOrFail()->business_id)->toBe($payable->id);
});

/*
|--------------------------------------------------------------------------
| The flag itself
|--------------------------------------------------------------------------
*/

test('every business is collected for by default, and rolls back individually', function () {
    [, $first] = createBusinessOwner();
    [, $second] = createBusinessOwner();

    // On from the moment the business exists. The pilot that made this opt-in is
    // over: a business no admin has ever touched is already being collected for,
    // because there is no longer a rail for it to be on instead.
    expect($first->fresh()->isUnderCustody())->toBeTrue()
        ->and($second->fresh()->isUnderCustody())->toBeTrue();

    $first->disableCustody();

    // Per business, not one global switch: rolling one unhappy business back must
    // leave every other one exactly where it was, and a global boolean could not
    // express that.
    expect($first->fresh()->isUnderCustody())->toBeFalse()
        ->and($second->fresh()->isUnderCustody())->toBeTrue();

    $first->enableCustody();

    // And the rollback is itself reversible without dragging anybody else with it,
    // which is what makes it usable as the lever the runbook reaches for.
    expect($first->fresh()->isUnderCustody())->toBeTrue()
        ->and($second->fresh()->isUnderCustody())->toBeTrue();
});

test('rolling a business back leaves the money it is owed alone', function () {
    [$business] = ws43CustodyBusiness(238750);

    // The state a rollback is actually performed in, which is the only one where
    // this matters: a business that has been collected for and not yet paid.
    expect($business->walletBalance())->toBe(238750);

    $business->disableCustody();

    // A business rolled back after a sale but before its payout is still owed that
    // money, and zeroing the wallet would quietly keep it. Clearing a balance is not
    // a rollback's job — paying it out is, and the sweep goes on doing that: the
    // flag decides how the business is charged, not whether what it is already owed
    // gets sent.
    expect($business->fresh()->walletBalance())->toBe(238750)
        ->and($business->fresh()->isUnderCustody())->toBeFalse();
});

test('a sale charged on a business own keys is never held by Storify', function () {
    [$business] = ws43CustodyBusiness();

    // The same business, charging through keys of its own: the money lands in its own
    // provider account, so it is not ours to hold however the flag is set. Crediting
    // the wallet here would have Storify pay the business out of its own balance for
    // money the business already has — a double payment, not a delayed one.
    $transaction = ws43Sale($business, [
        'metadata' => ['payment_mode' => 'own_keys', 'leg_method' => 'paystack'],
    ]);

    expect(app(CustodyService::class)->recordCollectedSale($transaction, 'paystack'))->toBeFalse()
        ->and($business->fresh()->walletBalance())->toBe(0)
        // And no settlement date, so the promote run cannot find it either — the
        // marker is the only way in, and it was never stamped.
        ->and($transaction->fresh()->settle_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The destination ceiling
|--------------------------------------------------------------------------
*/

/**
 * Swap in a fresh faked provider.
 *
 * `Http::fake()` *merges* its stubs with whatever was faked before it and the first
 * matching one wins, so a second `Http::fake()` inside one test would still be
 * answered by the first. Swapping the binding is the only way to change the
 * provider's mind mid-test — which is how "the provider was wrong and now works" is
 * written at all.
 */
function ws43FakeProvider(array $stubs): void
{
    Http::swap(new Factory);
    Http::fake($stubs);
}

/** The provider saying it will not register this bank account. */
function ws43RefuseDestination(): void
{
    ws43FakeProvider([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response([
            'message' => 'This account number could not be resolved.',
        ], 422),
    ]);
}

/**
 * Run the sweep until the provider has refused the bank the ceiling allows.
 *
 * @return Business the business, refreshed
 */
function ws43RefuseUntilExhausted(Business $business, int $times = 3): Business
{
    $sweep = app(PayoutSweepService::class);

    foreach (range(1, $times) as $ignored) {
        expect($sweep->sweep()['failed'])->toBe(1);
    }

    return $business->fresh();
}

test('a bank the provider keeps refusing stops being retried', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();
    ws43RefuseDestination();

    ws43RefuseUntilExhausted($business);

    expect(Payout::query()->where('status', Payout::STATUS_FAILED)->count())->toBe(3)
        // The money never left, and every refusal put its reservation straight back.
        ->and($business->fresh()->available_balance)->toBe(238750);

    // The provider would accept anything now, and it makes no difference: the
    // sweep has already decided this destination is not one to keep asking about.
    ws43FakeProvider([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);

    $counts = app(PayoutSweepService::class)->sweep();

    expect($counts['exhausted'])->toBe(1)
        ->and($counts['failed'])->toBe(0)
        ->and($counts['sent'])->toBe(0)
        // Nothing was attempted, which is the whole point: four identical refusals
        // an hour apart buy nothing, and what they cost is a business that needs a
        // person looking at it looking exactly like one that is between payouts.
        ->and(Payout::query()->count())->toBe(3)
        ->and($business->fresh()->available_balance)->toBe(238750);
});

test('a provider refusing the send does not count against the destination', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();

    // The provider's own code, on a bank it accepted. `INSUFFICIENT_BALANCE` means
    // our balance has not settled yet, which is a reason to try again in an hour and
    // not a statement about the account.
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response([
            'code' => 'INSUFFICIENT_BALANCE',
            'message' => 'Your balance is not enough for this payout.',
        ], 400),
    ]);

    $sweep = app(PayoutSweepService::class);

    foreach (range(1, 3) as $ignored) {
        $sweep->sweep();
    }

    expect(Payout::query()->where('status', Payout::STATUS_FAILED)->count())->toBe(3)
        ->and($sweep->destinationIsExhausted($business->fresh()))->toBeFalse();

    // The fourth run is stopped — by the *retry* ceiling, not the destination one,
    // and the difference is the whole subject of this test. Three refusals is what
    // `custody.retry_attempts` allows, so the platform stops asking and waits for a
    // person; what must not happen is the destination being blamed for it, because
    // the account was never the problem.
    //
    // This assertion used to read the other way — "the fourth run really does go
    // out" — and it was right about the behaviour it described, because there was no
    // retry ceiling when it was written and a business could be refused forever. That
    // is the storm the ceiling exists to break, and the cost of breaking it is that a
    // provider having a long afternoon ends with an alert and a human rather than with
    // a fourth automatic attempt.
    $counts = $sweep->sweep();

    expect($counts['exhausted'])->toBe(1)
        ->and($counts['failed'])->toBe(0)
        ->and(Payout::query()->count())->toBe(3)
        ->and($sweep->destinationIsExhausted($business->fresh()))->toBeFalse();

    // And it is not a trapdoor: an operator who has looked retires the refusals, and
    // the very next run attempts again on a clean count.
    expect($sweep->clearFailures($business))->toBe(3);

    $counts = $sweep->sweep();

    expect($counts['failed'])->toBe(1)
        ->and($counts['exhausted'])->toBe(0)
        ->and(Payout::query()->where('status', Payout::STATUS_FAILED)->count())->toBe(1);
});

test('naming a different bank lifts the ceiling by itself', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business, $bank] = ws43FundedBusiness();
    ws43RefuseDestination();

    ws43RefuseUntilExhausted($business);

    $sweep = app(PayoutSweepService::class);

    expect($sweep->destinationIsExhausted($business->fresh()))->toBeTrue();

    // The refusals are matched against the destination *on the row*, so a bank
    // nobody has tried yet starts with no history — which is what makes "name a
    // different account" a fix that needs no command run for it.
    $bank->update(['account_number' => '9876543210']);

    expect($sweep->destinationIsExhausted($business->fresh()))->toBeFalse()
        ->and($sweep->destinationFailures($business->fresh()))->toBe(0);
});

test('custody:retry-payouts retires the refusals and the sweep tries the bank again', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();
    ws43RefuseDestination();

    ws43RefuseUntilExhausted($business);

    $this->artisan('custody:retry-payouts', ['businesses' => [(string) $business->id]])
        ->assertSuccessful()
        ->expectsOutputToContain('3 refusals retired');

    // Abandoned rather than deleted: the rows still say what the provider said and
    // when, which is what somebody reading the payout history weeks later needs.
    // What they stop being is evidence about the next attempt.
    expect(Payout::query()->where('status', Payout::STATUS_CANCELLED)->count())->toBe(3)
        ->and(app(PayoutSweepService::class)->destinationIsExhausted($business->fresh()))->toBeFalse()
        // No money moved, and none was lost: each of those rows returned its
        // reservation when it failed, so this was never a payment.
        ->and($business->fresh()->available_balance)->toBe(238750);

    ws43FakeProvider([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);

    $counts = app(PayoutSweepService::class)->sweep();

    expect($counts['sent'])->toBe(1)
        ->and($counts['exhausted'])->toBe(0);
});

test('the retry dry run reports what it would retire and retires nothing', function () {
    ws43PlatformBachs();
    ws43Settings(['system_payment_processor' => 'bachs']);

    [$business] = ws43FundedBusiness();
    ws43RefuseDestination();

    ws43RefuseUntilExhausted($business);

    $this->artisan('custody:retry-payouts', [
        'businesses' => [(string) $business->id],
        '--dry-run' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('3 refusals');

    expect(Payout::query()->where('status', Payout::STATUS_CANCELLED)->count())->toBe(0)
        ->and(app(PayoutSweepService::class)->destinationIsExhausted($business->fresh()))->toBeTrue();
});

test('a bank that has never been refused has nothing to retire', function () {
    [$business] = ws43CustodyBusiness(238750);

    $this->artisan('custody:retry-payouts', ['businesses' => [(string) $business->id]])
        ->assertSuccessful()
        ->expectsOutputToContain('no recorded refusals');
});

test('a business id that matches nothing fails before anything is retired', function () {
    $this->artisan('custody:retry-payouts', ['businesses' => ['NO-SUCH-BUSINESS']])
        ->assertFailed();
});
