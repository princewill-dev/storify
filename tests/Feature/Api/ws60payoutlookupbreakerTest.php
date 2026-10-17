<?php

use App\Enums\TransactionStatus;
use App\Jobs\CheckPayoutStatus;
use App\Jobs\SettlePayouts;
use App\Mail\AdminPayoutUnconfirmedMail;
use App\Models\Business;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\InstantPayoutService;
use App\Services\Payments\Custody\PayoutSweepService;
use App\Services\Payments\Custody\PlatformPayoutNotifier;
use App\Services\Payments\Custody\WalletSummaryService;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Payouts\BachsPayoutRail;
use App\Services\Payments\Payouts\PayoutLookupBreaker;
use App\Services\Payments\Payouts\PayoutLookupUnreachable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| WS-60 — one silent provider must not spend the whole worker asking it questions.
|
| Every provider client times out after thirty seconds, and the ladder asks nine questions per
| payout. A provider that accepts sends and then stops answering lookups therefore costs up to
| thirty seconds per question, per payout, to learn nothing — and those jobs share a FIFO queue
| with the money. The breaker in front of the rail is what turns that into a pause.
|
| What this file pins:
|
| **The two kinds of silence are different.** `null` from `lookUp()` means the provider was
| reached and has no answer for *this transfer*; only an unreachable provider, a 5xx or a 429
| is a statement about *every* transfer. Only the second may stop the questions, because a
| per-transfer silence must keep its ladder and still reach a person.
|
| **The breaker saves the questions, never the alert.** A full ladder walked against a 5xx
| must attempt exactly one lookup and still end in exactly one email. That is the whole safety
| property: if this test ever fails by sending zero emails, the breaker has swallowed the one
| notification that says money is out and unaccounted for.
|
| **Half-open is one probe, not a herd.** When the window expires every check due at that
| second wakes; without the probe gate they would all ask at once and reproduce the burst the
| breaker exists to prevent. One asks, and its answer decides for everybody.
|
| **A rail that never made a request is not evidence.** Bachs cannot look a payout up without
| the id it issued, so a check on such a row asks nothing. It must not close the breaker,
| because "we did not ask" would otherwise read as "the provider is back".
*/

function br60Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function br60Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

/**
 * A custody business with a bank on file and one store.
 *
 * @return array{0: User, 1: Business, 2: Store}
 */
function br60Business(): array
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
    ])->save();

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'br60-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ]);

    return [$owner, $business->fresh(), $store];
}

/** A confirmed managed sale credited to the wallet, with no fees, and left unpromoted. */
function br60Credited(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $order = $store !== null ? Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'BR60-'.strtoupper(Str::random(10)),
        'subtotal' => $kobo / 100,
        'total' => $kobo / 100,
    ]) : null;

    $sale = Transaction::create([
        'reference' => 'API_BR60_'.strtoupper(Str::random(10)),
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

    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    return $sale->fresh();
}

/**
 * Bachs: a spendable balance, a usable destination, and a send that is accepted.
 *
 * The send stub deliberately cannot answer the later `/payouts/pay_1` lookup — `Http::fake()`
 * matches a pattern against the end of the URL — so each test registers what the lookup says,
 * and a test that wants silence simply does not.
 */
function br60Provider(int $availableKobo = 50000000): void
{
    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => Http::response([
            'account_id' => 'org_br60',
            'balances' => [[
                'currency' => 'NGN',
                'available_balance' => number_format($availableKobo / 100, 2, '.', ''),
                'pending_balance' => '0.00',
            ]],
            'pending_settlements_by_day' => [],
        ]),
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'processing']),
    ]);
}

/** What the provider says about the transfer when it is asked about it. */
function br60Lookup(int $status = 200, array $body = ['id' => 'pay_1', 'status' => 'processing']): void
{
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response($body, $status),
    ]);
}

/** The send job, run the way a worker would run it. */
function br60Run(int $businessId): void
{
    (new SettlePayouts($businessId))->handle(
        app(InstantPayoutService::class),
        app(WalletSummaryService::class),
        app(PayoutSweepService::class),
    );
}

/** A payout the provider has accepted, made by the real send rather than inserted. */
function br60InFlight(Business $business, ?Store $store = null): Payout
{
    br60Credited($business, 100000, $store);
    br60Run((int) $business->id);

    // `firstOrFail()`, not `sole()`: several tests here create more than one payout for the
    // same business, and `sole()` refuses to answer when it matches more than one row.
    return Payout::query()->where('business_id', $business->id)->latest('id')->firstOrFail();
}

/** One question, asked the way a worker would ask it. */
function br60Check(Payout $payout, int $check = 1): void
{
    (new CheckPayoutStatus((int) $payout->id, $check))->handle(
        app(PayoutSweepService::class),
        app(PlatformPayoutNotifier::class),
    );
}

/**
 * How many times the provider was actually asked about the transfer.
 *
 * Read off the recorder against the lookup URL, not with `assertNothingSent()`: the fixtures
 * also read the balance and register a destination, and those requests are not the question
 * under test. A 5xx *is* recorded as an attempt — the provider answered — which is what makes
 * "exactly one" a meaningful number here.
 */
function br60Lookups(): int
{
    return Http::recorded()->filter(
        fn (array $pair): bool => str_ends_with($pair[0]->url(), '/payouts/pay_1'),
    )->count();
}

// ---------------------------------------------------------------------------
// The state machine
// ---------------------------------------------------------------------------

test('the breaker pauses for a minute, and one provider being down says nothing about the other', function () {
    // The shipped default, pinned rather than set: a long window delays resolving every payout
    // behind an outage, and a window of zero would turn the breaker off without saying so.
    expect(config('custody.payout_lookup_breaker_seconds'))->toBe(60)
        ->and(PayoutLookupBreaker::windowSeconds())->toBe(60);

    $breaker = app(PayoutLookupBreaker::class);

    // A healthy provider: every check may ask.
    expect($breaker->allows('bachs'))->toBeTrue();

    $breaker->recordUnreachable('bachs');

    expect($breaker->allows('bachs'))->toBeFalse()
        ->and($breaker->allows('paystack'))->toBeTrue();

    $breaker->recordAnswered('bachs');

    expect($breaker->allows('bachs'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// What counts as an outage
// ---------------------------------------------------------------------------

test('a 5xx on a lookup opens the breaker, and the next payout is not asked', function () {
    br60Platform();
    br60Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = br60Business();

    br60Provider();
    br60Lookup(503, ['message' => 'Bad gateway']);

    $first = br60InFlight($business, $store);
    $second = br60InFlight($business, $store);

    br60Check($first);
    br60Check($second);

    // One question reached Bachs. The second payout was answered without a request, which is
    // the entire point: the provider was unreachable, and asking it again would have cost the
    // same thirty seconds to learn the same nothing.
    expect(br60Lookups())->toBe(1)
        ->and($first->fresh()->status)->toBe(Payout::STATUS_PROCESSING)
        ->and($second->fresh()->status)->toBe(Payout::STATUS_PROCESSING);
});

test('a 404 is an answer about one transfer, not an outage', function () {
    br60Platform();
    br60Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = br60Business();

    br60Provider();
    br60Lookup(404, ['message' => 'Not found']);

    $first = br60InFlight($business, $store);
    $second = br60InFlight($business, $store);

    br60Check($first);
    br60Check($second);

    // The provider was reached both times and said it had nothing for this reference. That is
    // exactly the case the ladder exists for, so suppressing the second question would hide a
    // real per-transfer problem behind an unrelated one.
    expect(br60Lookups())->toBe(2)
        ->and($first->fresh()->status)->toBe(Payout::STATUS_PROCESSING);
});

test('a transport failure raises the same signal as a 5xx', function () {
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/pay_1' => fn () => throw new ConnectionException('Connection timed out'),
    ]);

    // The rail is where the distinction is drawn, so it is pinned there. `null` is reserved
    // for "asked and no answer"; a connection that never established did not ask anybody.
    expect(fn () => (new BachsPayoutRail)->lookUp(br60Bachs(), 'po-7-abcdef', 'pay_1'))
        ->toThrow(PayoutLookupUnreachable::class);

    expect(fn () => (new BachsPayoutRail)->lookUp(br60Bachs(), 'po-7-abcdef'))
        ->not->toThrow(PayoutLookupUnreachable::class);
});

test('the breaker closes once the provider answers again', function () {
    br60Platform();
    br60Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = br60Business();

    br60Provider();

    // One sequence rather than a second `Http::fake()` mid-test: registering a stub resets the
    // recorder, and the count of attempts is half of what this test asserts. The first lookup
    // fails, and the probe and everything after it succeed.
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::sequence()
            ->push(['message' => 'Bad gateway'], 503)
            ->push(['id' => 'pay_1', 'status' => 'processing'])
            ->push(['id' => 'pay_1', 'status' => 'processing']),
    ]);

    $first = br60InFlight($business, $store);
    $second = br60InFlight($business, $store);
    $third = br60InFlight($business, $store);

    br60Check($first);

    // Still open: the second check is answered without a request.
    br60Check($second);

    expect(br60Lookups())->toBe(1);

    // The open window expires while the episode marker is still live, so exactly one check is
    // allowed to probe. Here the provider is healthy again, and its answer closes the breaker.
    $this->travel(61)->seconds();

    br60Check($second);

    // The probe succeeded, so the third payout is asked normally rather than being suppressed
    // until the episode marker lapses.
    br60Check($third);

    expect(br60Lookups())->toBe(3);
});

test('a payout the rail cannot ask about does not close the breaker', function () {
    br60Platform();
    br60Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = br60Business();

    br60Provider();

    $payout = br60InFlight($business, $store);

    // An outage, simulated directly: this test is about what a check does *not* count as
    // evidence, not about how the breaker opened.
    app(PayoutLookupBreaker::class)->recordUnreachable('bachs');

    $payout->forceFill(['provider_payout_id' => null])->save();

    br60Check($payout);

    // Bachs needs the id it issued, so no request was made. If that were read as an answer, the
    // breaker would have closed and the next wave of checks would hang on a provider still down.
    expect(br60Lookups())->toBe(0)
        ->and(app(PayoutLookupBreaker::class)->allows('bachs'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// What the breaker must never do
// ---------------------------------------------------------------------------

test('the full ladder still ends in exactly one email while the provider is down', function () {
    br60Platform();
    br60Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = br60Business();

    br60Provider();
    br60Lookup(503);

    Mail::fake();

    $payout = br60InFlight($business, $store);
    $schedule = CheckPayoutStatus::schedule();

    // The queue is faked so the chain is not standing down on `SyncQueue`: on that driver
    // `checkLater()` returns before it can reach the alert, and the whole point here is that
    // the alert still happens when every question is suppressed.
    Queue::fake();

    foreach (array_keys($schedule) as $index) {
        br60Check($payout, $index + 1);
    }

    // Nine rungs, one request. Every later rung was answered from the breaker, and the last one
    // still reached `alert()` — the breaker suppresses the question, never the warning.
    expect(br60Lookups())->toBe(1)
        ->and($payout->fresh()->status)->toBe(Payout::STATUS_PROCESSING);

    Mail::assertSent(AdminPayoutUnconfirmedMail::class, 1);
});

/** Bachs credentials, for the rail-level assertion above. */
function br60Bachs(): GatewayCredentials
{
    return GatewayCredentials::make('bachs', [
        'api_key' => 'sk_sandbox_platform1234',
        'webhook_secret' => 'whsec_platform',
    ], ['api_key', 'webhook_secret']);
}
