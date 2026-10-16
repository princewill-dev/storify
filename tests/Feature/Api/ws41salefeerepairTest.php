<?php

use App\Enums\ProviderFeeBearer;
use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Payments\Custody\CustodyService;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
| WS-41 (repair) — pricing the collected sales whose provider fee never arrived.
|
| A collected sale is credited to its business's wallet by `recordCollectedSale()`,
| which refuses to work from a fee it does not have and returns `false` — so the
| sale waits. Two things make that wait permanent rather than temporary: the fee
| was read off a field of the provider's payload it was never on, and
| `PaymentWebhookProcessor::settle()` short-circuits on the transaction's status,
| so the delivery that would have carried the fee is a no-op by the time it
| arrives. `creditAwaitingSales()` selects `whereNotNull('fee_kobo')`, so it can
| never reach the row either.
|
| `custody:repair-sale-fees` is the way out: read the provider's charge again, write
| what it says, and let the promote pass credit the wallet — which is where the
| money moves, and stays the only place it moves.
|
| The figures here are the ones a real sandbox sale produced: a ₦24,725.00 order,
| collected as ₦25,095.88 with a ₦370.88 fee, settled at ₦24,725.00.
*/

function rp41Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

/**
 * Write the singleton settings row the platform reads, by the same `first()`-then-
 * `update` route the rest of the suite uses: `id` is not fillable on `Setting`, so
 * an `updateOrCreate(['id' => 1])` never matches its second call and inserts a new
 * row every time.
 */
function rp41Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function rp41Business(): Business
{
    [, $business] = createBusinessOwner([], []);

    $business->forceFill(['custody_enabled' => true, 'custody_enabled_at' => now()])->save();

    return $business->fresh();
}

/**
 * Bachs, answering the one call the repair makes: read the session's charge back.
 */
function rp41FakeCharge(array $charge): void
{
    // A fresh factory first, not just another `Http::fake()`: a second fake for a
    // URL a test has already stubbed does not displace the first, and the balance
    // read below was answered by the charge payload — which parses as "the
    // provider has no balances" and quietly turns the gate off rather than failing.
    Http::swap(new Factory);

    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'checkout_id' => 'chk_rp41',
        'status' => 'completed',
        'charge' => $charge,
    ])]);
}

/**
 * What the provider is holding, as the promote run's gate reads it.
 */
function rp41FakeBalances(string $available = '1000000.00'): void
{
    Http::swap(new Factory);

    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'balances' => [['currency' => 'NGN', 'available_balance' => $available, 'pending_balance' => '0.00']],
    ])]);
}

/**
 * The row the old writer left behind: confirmed, paid, and priced with no provider
 * fee at all — the state `fee_kobo IS NULL` names.
 *
 * `business`-bearing, and stated rather than defaulted into. These are the rows
 * the command was written for, where the missing fee is the reason the business
 * has not been credited — `net = amount − fee − commission` cannot be answered
 * without it. A test that wants the other rule passes the bearer it wants, which
 * is what makes the difference between the two visible in the test rather than
 * implied by a column default.
 */
function rp41StuckSale(Business $business, array $attributes = []): Transaction
{
    return Transaction::create(array_merge([
        'reference' => 'API_'.strtoupper(Str::random(12)),
        'business_id' => $business->id,
        'payment_method_id' => PaymentMethod::query()->where('code', 'bachs')->value('id'),
        'amount' => 24725.00,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'gateway_reference' => 'chk_rp41',
        'paid_at' => now(),
        'fee_kobo' => null,
        'platform_fee_kobo' => 61813,
        'provider_fee_bearer' => ProviderFeeBearer::BUSINESS,
        'metadata' => ['payment_mode' => 'platform_managed', 'platform_fee_kobo' => 61813],
    ], $attributes));
}

/**
 * The charge as Bachs reports it now that the platform bears the fee: the customer
 * is charged the order total and the fee comes out of it.
 */
function rp41Charged(array $overrides = []): array
{
    return array_merge([
        'id' => 'chg_rp41',
        'status' => 'succeeded',
        'amount' => '24725.00',
        'currency' => 'NGN',
        'fees' => [
            'amount' => '370.88',
            'currency' => 'NGN',
            'breakdown' => [['type' => 'processing', 'amount' => '370.88']],
        ],
    ], $overrides);
}

/**
 * The platform's own entry for this sale's provider fee, if there is one.
 *
 * Found by idempotency key rather than by account, because the key is the thing
 * that makes the two posting paths — at collection and from the promote sweep —
 * one entry instead of two.
 */
function rp41FeeEntry(Transaction $transaction): ?JournalEntry
{
    return JournalEntry::query()
        ->where('idempotency_key', 'managed:provider_fee:txn:'.$transaction->id)
        ->first();
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

test('the repair reports what it would write and moves nothing until it is told to', function () {
    rp41Platform();
    rp41Settings(['system_payment_processor' => 'bachs', 'platform_commission_percent' => 2.5, 'platform_commission_fixed_kobo' => 0]);

    $sale = rp41StuckSale(rp41Business());

    rp41FakeCharge(rp41Charged());

    $this->artisan('custody:repair-sale-fees')
        ->expectsOutputToContain('Collected sales with no fee recorded: 1')
        ->expectsOutputToContain('Priceable and to write: 1')
        ->assertSuccessful();

    // Read, and written nowhere. The column the whole defect lives in is still
    // empty, which is what makes this safe to run against production to see what
    // it would do.
    $sale->refresh();

    expect($sale->fee_kobo)->toBeNull()
        ->and($sale->net_kobo)->toBeNull()
        ->and($sale->settle_at)->toBeNull();
});

test('the repair prices a stuck sale and the next promote run credits the wallet', function () {
    rp41Platform();
    rp41Settings(['system_payment_processor' => 'bachs', 'platform_commission_percent' => 2.5, 'platform_commission_fixed_kobo' => 0]);

    $business = rp41Business();
    $sale = rp41StuckSale($business);

    rp41FakeCharge(rp41Charged());

    $this->artisan('custody:repair-sale-fees --apply')->assertSuccessful();

    $sale->refresh();

    expect($sale->fee_kobo)->toBe(37088)
        // ₦24,725.00 − ₦370.88 − ₦618.13, through the same rule every other writer
        // uses, so the row is indistinguishable from one the webhook had priced.
        ->and($sale->net_kobo)->toBe(2373599)
        ->and($sale->netKobo())->toBe(2373599)
        // Nothing is credited here. The claim the money hangs on belongs to
        // `recordCollectedSale()`, and a repair that credited the wallet itself
        // would be a second way to move money.
        ->and($sale->settle_at)->toBeNull();

    // Enough at the provider for the gate to release it, so the run does both
    // halves in one pass: `promoteSettled()` credits first and matures second, and
    // the sale it has just credited is in the query that follows.
    rp41FakeBalances();

    $this->artisan('custody:promote')->assertSuccessful();

    $sale->refresh();

    expect($sale->settle_at)->not->toBeNull()
        ->and($sale->promoted_at)->not->toBeNull()
        ->and($business->fresh()->available_balance)->toBe(2373599)
        ->and($business->fresh()->pending_balance)->toBe(0);
});

test('a sale Storify charged is credited without its fee, and the fee is recognised afterwards', function () {
    // The rule change, on the one path where the delay used to be visible. A sale
    // the platform charged is credited the moment it is paid, because the
    // provider's fee was never part of the business's share — so the wallet holds
    // the money while the row carries no fee at all, and the repair learns that
    // fee for *our* books rather than the business's.
    //
    // The command's own contract is unchanged and is what the middle half of this
    // test pins: it writes two derived columns and posts nothing. Recognising our
    // cost is the promote sweep's job, on the same idempotency key.
    rp41Platform();
    rp41Settings(['system_payment_processor' => 'bachs', 'platform_commission_percent' => 2.5, 'platform_commission_fixed_kobo' => 0]);

    $business = rp41Business();
    $sale = rp41StuckSale($business, ['provider_fee_bearer' => ProviderFeeBearer::PLATFORM]);

    // Credited already, with no fee on the row and nothing promoted yet.
    expect(app(CustodyService::class)->recordCollectedSale($sale, 'bachs'))->toBeTrue();

    $sale->refresh();

    expect($sale->fee_kobo)->toBeNull()
        // ₦24,725.00 − our ₦618.13, and the provider's ₦370.88 nowhere in it. Read
        // through the accessor rather than the column: nothing has written
        // `net_kobo` yet — `recordCollectedSale()` computes the share and credits
        // it, and the column is the *writers'* record, which here has not run.
        // The wallet is the proof the figure exists.
        ->and($sale->netKobo())->toBe(2410687)
        ->and($sale->net_kobo)->toBeNull()
        ->and($business->fresh()->pending_balance)->toBe(2410687)
        ->and(rp41FeeEntry($sale))->toBeNull();

    rp41FakeCharge(rp41Charged());

    $this->artisan('custody:repair-sale-fees --apply')->assertSuccessful();

    $sale->refresh();

    expect($sale->fee_kobo)->toBe(37088)
        // Unchanged, and that is the point: the business was credited before this
        // fee existed, and the fee was never going to move what it was owed.
        ->and($sale->net_kobo)->toBe(2410687)
        // Still nothing posted. The repair stays journal-neutral, as its docblock
        // promises, so a run of it against production cannot write an entry
        // nobody was expecting.
        ->and(rp41FeeEntry($sale))->toBeNull();

    // And the next promote pass recognises our own cost — as an expense, because
    // this sale raised no payable to the provider.
    rp41FakeBalances();

    $this->artisan('custody:promote')->assertSuccessful();

    $entry = rp41FeeEntry($sale);

    expect($entry)->not->toBeNull()
        ->and((int) $entry->lines()
            ->whereHas('account', fn ($query) => $query->where('subtype', 'provider_processing_fees'))
            ->sum('debit_kobo'))->toBe(37088)
        ->and($entry->lines()
            ->whereHas('account', fn ($query) => $query->where('subtype', 'provider_fees_payable'))
            ->count())->toBe(0)
        // The wallet matured, and still for the figure it was credited with.
        ->and($business->fresh()->available_balance)->toBe(2410687);
});

test('a sale whose fee the customer paid is priced without it, not short by a fee', function () {
    // The received defect. While the Bachs organisation was `customer_pays_fee` the
    // charge was grossed up: the customer was debited ₦25,095.88 on a ₦24,725.00
    // order, the ₦370.88 was added on top, and ₦24,725.00 — the whole order —
    // reached the platform. Subtracting the fee would hand the business ₦370.88 less
    // than the platform is holding for it and keep the difference.
    rp41Platform();
    rp41Settings(['system_payment_processor' => 'bachs', 'platform_commission_percent' => 2.5, 'platform_commission_fixed_kobo' => 0]);

    $business = rp41Business();
    $sale = rp41StuckSale($business);

    rp41FakeCharge([
        'id' => 'chg_rp41',
        'status' => 'succeeded',
        'amount' => '25095.88',
        'currency' => 'NGN',
        'fees' => [
            'amount' => '370.88',
            'currency' => 'NGN',
            'breakdown' => [['type' => 'processing', 'amount' => '370.88']],
        ],
        // What actually landed, and the evidence the fee was never the sale's:
        // `amount − fees.amount`, and equal to the whole order.
        'settlement_amount' => '24725.00',
        'settlement_currency' => 'NGN',
        'fee_paid_by' => 'merchant',
        'merchant_bears_cost' => false,
    ]);

    $this->artisan('custody:repair-sale-fees --apply')->assertSuccessful();

    $sale->refresh();

    // The fee the *sale* bore is zero, and the net is the order total less our cut.
    // Not 2336711, which is what the fee subtracted would have produced.
    expect($sale->fee_kobo)->toBe(0)
        ->and($sale->net_kobo)->toBe(2410687)
        ->and($sale->processingFeeKobo())->toBe(61813);
});

test('a sale the provider still has not priced is left alone and said so', function () {
    // The honest wait, and the reason this command is not a failure when it finds
    // one: Bachs reports `fees.amount: null` while it works the settlement out, and
    // a zero there would subtract nothing and credit the business with the
    // provider's money.
    rp41Platform();
    rp41Settings(['system_payment_processor' => 'bachs', 'platform_commission_percent' => 2.5, 'platform_commission_fixed_kobo' => 0]);

    $sale = rp41StuckSale(rp41Business());

    rp41FakeCharge(rp41Charged(['fees' => ['amount' => null, 'currency' => 'NGN']]));

    $this->artisan('custody:repair-sale-fees --apply')
        ->expectsOutputToContain('provider still has not reported a fee: 1')
        ->assertSuccessful();

    expect($sale->fresh()->fee_kobo)->toBeNull()
        ->and($sale->fresh()->net_kobo)->toBeNull();
});

test('a managed sale with no commission recorded is not priced at today\'s rate', function () {
    // The other thing a net cannot be derived from. Inferring the commission from
    // the setting as it stands would price an old sale at whatever the rate happens
    // to be now, and the ledger posts revenue against that number.
    rp41Platform();
    rp41Settings(['system_payment_processor' => 'bachs', 'platform_commission_percent' => 2.5, 'platform_commission_fixed_kobo' => 0]);

    $sale = rp41StuckSale(rp41Business(), [
        'platform_fee_kobo' => null,
        'metadata' => ['payment_mode' => 'platform_managed'],
    ]);

    rp41FakeCharge(rp41Charged());

    $this->artisan('custody:repair-sale-fees --apply')
        ->expectsOutputToContain('no commission recorded: 1')
        ->assertSuccessful();

    expect($sale->fresh()->fee_kobo)->toBeNull();
});

test('a charge the provider does not call paid is reported rather than priced', function () {
    // No fee is going to be reported for a charge that has not settled, and a net
    // written from one would be a claim about money that was never taken.
    rp41Platform();
    rp41Settings(['system_payment_processor' => 'bachs', 'platform_commission_percent' => 2.5, 'platform_commission_fixed_kobo' => 0]);

    $sale = rp41StuckSale(rp41Business());

    rp41FakeCharge(rp41Charged(['status' => 'processing']));

    $this->artisan('custody:repair-sale-fees --apply')
        ->expectsOutputToContain('provider says not paid: 1')
        ->assertSuccessful();

    expect($sale->fresh()->fee_kobo)->toBeNull();
});

test('a charge collected for less than we billed is a question, not a fee to subtract', function () {
    rp41Platform();
    rp41Settings(['system_payment_processor' => 'bachs', 'platform_commission_percent' => 2.5, 'platform_commission_fixed_kobo' => 0]);

    $sale = rp41StuckSale(rp41Business());

    rp41FakeCharge(rp41Charged(['amount' => '2000.00']));

    $this->artisan('custody:repair-sale-fees --apply')
        ->expectsOutputToContain('collected less than billed: 1')
        ->assertSuccessful();

    expect($sale->fresh()->fee_kobo)->toBeNull();
});

test('a sale with no provider on the row is named rather than guessed at', function () {
    // A POS leg whose payment method never recorded a provider. Nothing here can
    // say which provider to ask, and asking the wrong one would either fail or —
    // worse — answer about a different payment.
    rp41Platform();
    rp41Settings(['system_payment_processor' => 'bachs', 'platform_commission_percent' => 2.5, 'platform_commission_fixed_kobo' => 0]);

    $sale = rp41StuckSale(rp41Business(), ['payment_method_id' => null]);

    rp41FakeCharge(rp41Charged());

    $this->artisan('custody:repair-sale-fees --apply')
        ->expectsOutputToContain('no provider on the row: 1')
        ->assertSuccessful();

    expect($sale->fresh()->fee_kobo)->toBeNull();
});

test('a second run finds nothing left to repair', function () {
    // Idempotent by the same condition the first run wrote under: once a sale has a
    // fee it is no longer `salesAwaitingFee()`, so a re-run is a no-op rather than a
    // second write of the same numbers.
    rp41Platform();
    rp41Settings(['system_payment_processor' => 'bachs', 'platform_commission_percent' => 2.5, 'platform_commission_fixed_kobo' => 0]);

    $sale = rp41StuckSale(rp41Business());

    rp41FakeCharge(rp41Charged());

    $this->artisan('custody:repair-sale-fees --apply')->assertSuccessful();
    $this->artisan('custody:repair-sale-fees --apply')
        ->expectsOutputToContain('Collected sales with no fee recorded: 0')
        ->assertSuccessful();

    expect($sale->fresh()->fee_kobo)->toBe(37088)
        ->and($sale->fresh()->net_kobo)->toBe(2373599);
});
