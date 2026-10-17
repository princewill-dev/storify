<?php

use App\Enums\TransactionStatus;
use App\Jobs\CheckPayoutStatus;
use App\Jobs\SettlePayouts;
use App\Mail\AdminPayoutUnconfirmedMail;
use App\Models\Business;
use App\Models\JournalEntry;
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
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| WS-59 — ask the provider what became of a transfer, starting five seconds after we send it.
|
| The owner's request, verbatim: *"let's reduce the settlement status check. Let's start from
| 5 seconds. 60 seconds is quite too wide for a gap for a payment that is almost near-instant.
| Make it an ENV variable so that I can set the intervals."*
|
| What this file pins, and why each one is worth a test:
|
| **Five seconds, and seconds rather than minutes.** The unit is the whole trap: the two lists
| beside `payout_status_backoff_seconds` in the same config file are *minutes*, so the same
| string read from the wrong key is five minutes. Both are asserted together so a change to
| either one's unit fails visibly instead of quietly waiting sixty times too long.
|
| **The index arithmetic.** `$check` is the number of the question the job is about to ask —
| deliberately the opposite of `SettlePayouts::$lagAttempt`, which counts questions already
| asked because its first look is immediate. Copying that convention here would put the first
| question a rung late, which is exactly the owner's complaint. `delayBefore()` is the only
| place the arithmetic lives, so it is pinned at both ends and one past the end.
|
| **The clock starts after a send, and only after a send.** It is asserted through the delay
| on the pushed job rather than through the config, so the wiring between them is covered
| rather than assumed. `apply()` is the only writer of `processing`, so this one line covers
| the sweep's send, the reconciler's resend and an accepted-then-silent provider alike.
|
| **One email, at the end, and never a second one.** The ladder cannot run past its last
| rung, so "exactly one alert per payout" is structural. The alert is also the one piece of
| this change an operator reads, so the copy that stops them resending a landed transfer is
| asserted against the rendered mail rather than trusted to a reviewer.
|
| **Nothing here can send money.** Every check reaches `settle()`, `fail()` or `undo()` and
| nothing else, and a row that is no longer `processing` is answered `skipped` without the
| provider being asked at all. Both are pinned, because "polling cannot double-pay" is the
| safety argument for shipping this at all.
|
| **The ladder has its own lane.** A check can occupy a worker for a provider client's full
| thirty-second timeout, and there are nine per payout, so sharing `default` with
| `SettlePayouts` would let a silent provider stop money moving. The lane is named, and it is
| asserted to differ from the send job's. WS-60 covers what happens when that provider is
| unreachable — the circuit breaker in front of the rail.
*/

function ps59Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function ps59Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

function ps59Token(User $user, string $audience = 'admin'): string
{
    return $user->createToken('ps59-access', [$audience], now()->addHour())->plainTextToken;
}

function ps59Admin(string $role = 'Finance Admin'): User
{
    (new SpatiePermissionSeeder)->run();

    $admin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $admin->assignRole($role);

    return $admin;
}

/**
 * A custody business with a bank on file and one store.
 *
 * @return array{0: User, 1: Business, 2: Store}
 */
function ps59Business(): array
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
        'slug' => 'ps59-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ]);

    return [$owner, $business->fresh(), $store];
}

/**
 * A confirmed managed sale, credited to the wallet, and left **unpromoted**.
 *
 * Zero fees, so the sale's net is exactly its amount and every figure asserted below is a
 * number this file chose.
 */
function ps59Credited(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $order = $store !== null ? Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'PS59-'.strtoupper(Str::random(10)),
        'subtotal' => $kobo / 100,
        'total' => $kobo / 100,
    ]) : null;

    $sale = Transaction::create([
        'reference' => 'API_PS59_'.strtoupper(Str::random(10)),
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
 * Bachs: a spendable balance, a usable destination, and a send that either accepts or fails.
 *
 * `processing`, not `pending`: Bachs has no `pending` status for a transfer. It answers
 * `processing` on acceptance and `completed` when the money lands — read off the live API,
 * so a fixture that says `pending` is testing a provider that does not exist.
 *
 * The send's URL is stubbed without a trailing segment on purpose. `Http::fake()` matches a
 * pattern against the *end* of the URL, so this stub cannot answer the later
 * `GET /v1/payouts/pay_1` lookup — a test that wants an answer registers that URL itself,
 * and a test that wants silence simply does not.
 */
function ps59Provider(int $availableKobo = 50000000, int $payoutStatus = 200): void
{
    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => Http::response([
            'account_id' => 'org_ps59',
            'balances' => [[
                'currency' => 'NGN',
                'available_balance' => number_format($availableKobo / 100, 2, '.', ''),
                'pending_balance' => '0.00',
            ]],
            'pending_settlements_by_day' => [],
        ]),
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => $payoutStatus === 200
            ? Http::response(['id' => 'pay_1', 'status' => 'processing'])
            : Http::response(['message' => 'Bad gateway'], $payoutStatus),
    ]);
}

/** The send job, run the way a worker would run it. */
function ps59Run(int $businessId): void
{
    (new SettlePayouts($businessId))->handle(
        app(InstantPayoutService::class),
        app(WalletSummaryService::class),
        app(PayoutSweepService::class),
    );
}

/**
 * A payout the provider has accepted, made the way a real one is made.
 *
 * Credited, promoted and sent by the actual job rather than inserted as a row: the whole
 * point of this feature is that the clock starts where the send happens, and a fixture that
 * wrote its own `processing` row would skip the very line under test. This is also why
 * every test that asserts the push fakes the queue *before* calling it.
 */
function ps59InFlight(Business $business, ?Store $store = null): Payout
{
    ps59Credited($business, 100000, $store);
    ps59Run((int) $business->id);

    return Payout::query()->where('business_id', $business->id)->latest('id')->sole();
}

/** One question, asked the way a worker would ask it. */
function ps59Check(Payout $payout, int $check): void
{
    (new CheckPayoutStatus((int) $payout->id, $check))->handle(
        app(PayoutSweepService::class),
        app(PlatformPayoutNotifier::class),
    );
}

/**
 * Was the transfer itself asked about?
 *
 * Read off the recorder rather than asserted with `assertSent()`, and read against the lookup
 * URL rather than with a bare `assertNothingSent()`. The fixtures here stub the balance and
 * the destination as well, so a bare "nothing was sent" would be false in half of these tests
 * for reasons that have nothing to do with the transfer — and a bare `assertSent()` cannot
 * express "the provider was not asked", which is the assertion that matters most.
 */
function ps59LookupWasAsked(?string $providerPayoutId = 'pay_1'): bool
{
    return Http::recorded()->contains(
        fn (array $pair): bool => str_ends_with($pair[0]->url(), '/payouts/'.(string) $providerPayoutId),
    );
}

/**
 * Journal lines keyed `code:dr` / `code:cr`, in the order they were posted.
 *
 * @return array<string, int>
 */
function ps59Lines(JournalEntry $entry): array
{
    return $entry->lines()->get()->mapWithKeys(fn ($line) => [
        $line->account->code.($line->debit_kobo > 0 ? ':dr' : ':cr') => $line->debit_kobo > 0
            ? (int) $line->debit_kobo
            : (int) $line->credit_kobo,
    ])->all();
}

// ---------------------------------------------------------------------------
// The ladder itself
// ---------------------------------------------------------------------------

test('the check gaps are the owner\'s list, in seconds', function () {
    // The unit is the whole trap. `5,30,60` two keys away in the same file is *minutes* —
    // the same string read from the wrong key waits five minutes instead of five seconds,
    // and nothing about the code would look wrong. Both are pinned here together, against
    // the *shipped* defaults rather than a value set in the test, so a silent edit to either
    // one is a failure.
    expect(config('custody.payout_status_backoff_seconds'))
        ->toBe([5, 15, 30, 60, 120, 300, 900, 1800, 3600])
        ->and(config('custody.retry_backoff_minutes'))->toBe([5, 30, 60]);

    // And the job reads that key, with a fallback that repeats the same default rather than
    // meaning "ask forever" when the config resolves to nothing.
    expect(CheckPayoutStatus::schedule())->toBe([5, 15, 30, 60, 120, 300, 900, 1800, 3600]);
});

test('the ladder is indexed by the question it is about to ask', function () {
    // The off-by-one the owner would have felt rather than seen. Entry 1 is the wait before
    // the *first* question, so a payout's first look is five seconds out; entry 9 is the gap
    // before the ninth and last; a tenth has no gap at all, which is how the chain knows it
    // is finished and how "one email per payout" is made structural.
    expect(CheckPayoutStatus::delayBefore(1))->toBe(5)
        ->and(CheckPayoutStatus::delayBefore(2))->toBe(15)
        ->and(CheckPayoutStatus::delayBefore(9))->toBe(3600)
        ->and(CheckPayoutStatus::delayBefore(10))->toBeNull()
        ->and(CheckPayoutStatus::delayBefore(0))->toBeNull();
});

// ---------------------------------------------------------------------------
// Starting the clock
// ---------------------------------------------------------------------------

test('a send the provider accepts starts the clock five seconds out', function () {
    // The owner's actual request, asserted on the pushed job rather than on the config: the
    // gap that matters is the one the queue is actually holding.
    ps59Platform();
    ps59Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = ps59Business();
    ps59Provider();

    Queue::fake();

    $payout = ps59InFlight($business, $store);

    expect($payout->status)->toBe(Payout::STATUS_PROCESSING)
        ->and($payout->provider_payout_id)->toBe('pay_1');

    Queue::assertPushed(CheckPayoutStatus::class, 1);
    Queue::assertPushed(
        CheckPayoutStatus::class,
        fn (CheckPayoutStatus $job): bool => (int) $job->payoutId === (int) $payout->id
            && $job->check === 1
            && $job->delay !== null
            && $job->delay->between(now()->addSeconds(4), now()->addSeconds(6)),
    );

    // Nothing asked the provider about the transfer inline. The clock is a queued question
    // five seconds out, not a `sleep()` on the checkout thread that collected the sale.
    expect(ps59LookupWasAsked())->toBeFalse();
});

test('a send that was never answered starts the clock too, and asking about it costs nothing', function () {
    // A 5xx from the send, so the provider may or may not have moved the money and we have no
    // id to ask about. That is the case a question is worth the most rather than the least —
    // and for Bachs it costs nothing at all, because `lookUp()` returns "cannot answer" before
    // it makes a request when the id is missing. So the ladder is cheap, and what it buys is
    // that a person is told about a transfer nobody can account for.
    ps59Platform();
    ps59Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = ps59Business();
    ps59Provider(50000000, 503);

    Queue::fake();

    $payout = ps59InFlight($business, $store);

    expect($payout->status)->toBe(Payout::STATUS_PROCESSING)
        ->and($payout->provider_payout_id)->toBeNull()
        ->and($payout->failure_reason)->not->toBeNull();

    Queue::assertPushed(
        CheckPayoutStatus::class,
        fn (CheckPayoutStatus $job): bool => $job->check === 1,
    );

    // Walk the whole ladder by hand, since no worker is going to. Every rung finds nothing to
    // ask about, so not one request reaches the provider.
    Mail::fake();

    $total = count(CheckPayoutStatus::schedule());

    for ($check = 1; $check <= $total; $check++) {
        Queue::fake();
        ps59Check($payout->fresh(), $check);
    }

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PROCESSING)
        ->and(ps59LookupWasAsked('pay_1'))->toBeFalse();

    // And it still ends where it should: one person told, once.
    Queue::assertNotPushed(CheckPayoutStatus::class);
    Mail::assertSent(AdminPayoutUnconfirmedMail::class, 1);

    // The provider never gave a status, so the mail says exactly that rather than leaving an
    // empty cell that reads as a field the view forgot to fill.
    Mail::assertSent(AdminPayoutUnconfirmedMail::class, function (AdminPayoutUnconfirmedMail $mail): bool {
        expect($mail->checks)->toBe(9)
            ->and($mail->reviewUrl)->toContain('/payouts');

        return true;
    });
});

test('the ladder is queued on its own lane, away from the money worker', function () {
    // The chain is only as good as the worker that drains it, so which lane the question
    // lands in is part of the feature rather than an implementation detail. A check can
    // spend a provider client's full thirty-second timeout and there are nine per payout,
    // so these do **not** share the lane `SettlePayouts` uses: a slow provider must delay
    // status checks, never the sending of money. The price is that the lane only works if a
    // worker drains it, which the README and the runbook both spell out, and which
    // `CUSTODY_PAYOUT_CHECK_QUEUE=default` overrides for a deployment that cannot add one.
    ps59Platform();
    ps59Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = ps59Business();
    ps59Provider();

    Queue::fake();

    ps59InFlight($business, $store);

    // The send job is the control: the one that settles money, whose lane is the
    // connection's own. The check must be somewhere else, and named — a `null` here would
    // mean it went back to sharing the money lane.
    SettlePayouts::dispatch((int) $business->id);

    $pushed = Queue::pushedJobs();

    expect($pushed)->toHaveKey(CheckPayoutStatus::class)
        ->and($pushed[CheckPayoutStatus::class][0]['queue'])
        ->toBe(CheckPayoutStatus::queueName())
        ->toBe('payout-checks')
        ->and($pushed[CheckPayoutStatus::class][0]['queue'])
        ->not->toBe($pushed[SettlePayouts::class][0]['queue']);
});

test('a deployment that cannot run a second worker can put the checks back on default', function () {
    config(['custody.payout_check_queue' => '']);

    // A blank value must not produce a job on the empty-string queue, which no worker
    // drains: the fallback is the documented lane, and the escape hatch is the word
    // `default`, not nothing at all.
    expect(CheckPayoutStatus::queueName())->toBe('payout-checks');

    config(['custody.payout_check_queue' => 'default']);

    expect(CheckPayoutStatus::queueName())->toBe('default');
});

// ---------------------------------------------------------------------------
// Writing the answer to the row
// ---------------------------------------------------------------------------

test('an answer of completed settles the row and posts to both sets of books', function () {
    ps59Platform();
    ps59Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = ps59Business();
    ps59Provider();

    $payout = ps59InFlight($business, $store);

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'completed']),
    ]);

    // The call the job makes, asked directly so the result itself can be pinned. What the
    // *job* does with that result is the next test.
    $result = app(PayoutSweepService::class)->checkPayout($payout);

    expect($result)->toBe('settled');

    $fresh = $payout->fresh();

    expect($fresh->status)->toBe(Payout::STATUS_PAID)
        ->and($fresh->paid_at)->not->toBeNull();

    // The business's money is in its bank and no longer held by us — 1020 against 1040, and
    // the platform's liability leaves with it.
    $businessEntry = JournalEntry::query()
        ->where('idempotency_key', 'payout:'.$fresh->reference)
        ->firstOrFail();

    $platformEntry = JournalEntry::query()
        ->whereNull('business_id')
        ->where('idempotency_key', 'custody_payout:'.$fresh->reference)
        ->firstOrFail();

    expect(ps59Lines($businessEntry))->toBe(['1020:dr' => 100000, '1040:cr' => 100000])
        ->and(ps59Lines($platformEntry))->toBe(['2300:dr' => 100000, '1030:cr' => 100000]);
});

test('a refusal fails the row and puts the money back in the wallet', function () {
    ps59Platform();
    ps59Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = ps59Business();
    ps59Provider();

    $payout = ps59InFlight($business, $store);

    expect((int) $business->fresh()->available_balance)->toBe(0);

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response([
            'id' => 'pay_1',
            'status' => 'failed',
            'failure_code' => 'BANK_REJECTED',
            'failure_reason' => 'The beneficiary bank rejected this transfer.',
        ]),
    ]);

    $result = app(PayoutSweepService::class)->checkPayout($payout);

    expect($result)->toBe('failed');

    $fresh = $payout->fresh();

    // The provider answered and did not move the money, so the business keeps it. `failed`
    // and not `reversed`: there was nothing to unwind, only a reservation to release.
    expect($fresh->status)->toBe(Payout::STATUS_FAILED)
        ->and($fresh->failure_code)->toBe('BANK_REJECTED')
        ->and((int) $business->fresh()->available_balance)->toBe(100000);

    expect(JournalEntry::query()->where('idempotency_key', 'payout:'.$fresh->reference)->exists())
        ->toBeFalse();
});

test('a reversal unwinds the payout and credits the wallet back', function () {
    // A reversal is the one thing the provider tells us *later* that undoes something it
    // already told us: the transfer completed, and then the bank sent it back. So the row is
    // taken all the way to `paid` first — a reversal against a row that was never settled has
    // no entries to void, which is a different case and not the one this test is about.
    ps59Platform();
    ps59Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = ps59Business();
    ps59Provider();

    $payout = ps59InFlight($business, $store);

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::sequence()
            ->push(['id' => 'pay_1', 'status' => 'completed'])
            ->push(['id' => 'pay_1', 'status' => 'reversed', 'failure_reason' => 'Returned by the beneficiary bank.']),
    ]);

    $sweep = app(PayoutSweepService::class);

    expect($sweep->checkPayout($payout))->toBe('settled');

    // Put back to `processing`, because this is the *lookup* route and `checkPayout()` only
    // asks about a row it still believes is moving — a reversal of an already-paid payout
    // therefore arrives by webhook and nowhere else (`ws39connectwebhooksTest`). The status
    // is set here so this test is about what the answer does to the row, not how the row got
    // back into the provider's sight.
    Payout::query()->whereKey($payout->id)->update(['status' => Payout::STATUS_PROCESSING]);

    $paidEntry = JournalEntry::query()
        ->where('idempotency_key', 'payout:'.$payout->reference)
        ->firstOrFail();

    expect($sweep->checkPayout($payout->fresh()))->toBe('reversed');

    $fresh = $payout->fresh();

    // The money is owed again, and nothing left standing says it was paid out.
    expect($fresh->status)->toBe(Payout::STATUS_REVERSED)
        ->and((int) $business->fresh()->available_balance)->toBe(100000)
        ->and($paidEntry->fresh()->status)->toBe(JournalEntry::STATUS_VOID);

    // The unwinding entry is the mirror of the one it voids, under its own key.
    $returned = JournalEntry::query()
        ->where('idempotency_key', 'payout_returned:'.$fresh->reference)
        ->firstOrFail();

    expect(ps59Lines($returned))->toBe(['1020:cr' => 100000, '1040:dr' => 100000]);
});

test('a payout somebody else has already settled is left alone', function () {
    // The race the webhook wins in practice. The guard is not what makes the write safe —
    // every terminal write claims the row conditionally — but it is what stops the ladder, so
    // a transfer that quietly reached a final state ten seconds after it was sent does not go
    // on being asked about, and does not end in an "unconfirmed" alert.
    ps59Platform();
    ps59Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = ps59Business();
    ps59Provider();

    $payout = ps59InFlight($business, $store);

    $payout->forceFill(['status' => Payout::STATUS_PAID, 'paid_at' => now()])->save();

    Queue::fake();
    Mail::fake();

    expect(app(PayoutSweepService::class)->checkPayout($payout->fresh()))->toBe('skipped');

    ps59Check($payout->fresh(), 1);

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID)
        ->and(ps59LookupWasAsked())->toBeFalse();

    Queue::assertNotPushed(CheckPayoutStatus::class);
    Mail::assertNothingSent();
});

test('a question that resolves the row ends the chain there', function () {
    // The same stop from the other direction: the provider answers, and there is nothing left
    // to ask about. Driving it through the job rather than the service is the point — this is
    // the only test that proves the job itself does not push a tenth question at a row it has
    // just settled.
    ps59Platform();
    ps59Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = ps59Business();
    ps59Provider();

    $payout = ps59InFlight($business, $store);

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'completed']),
    ]);

    Queue::fake();
    Mail::fake();

    ps59Check($payout, 1);

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID);

    Queue::assertNotPushed(CheckPayoutStatus::class);

    // The business is told its money landed — that is `settle()` doing its job, not noise.
    // What must not happen is an operator being told the opposite: the alert about a transfer
    // nobody can account for, fired at a row that has just been accounted for.
    Mail::assertNotSent(AdminPayoutUnconfirmedMail::class);
});

// ---------------------------------------------------------------------------
// The end of the ladder
// ---------------------------------------------------------------------------

test('the ladder widens to an hour and then ends in exactly one email', function () {
    ps59Platform();
    ps59Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = ps59Business();
    ps59Provider();

    $payout = ps59InFlight($business, $store);

    // From here on the provider will not say: a 404 on the lookup, which `lookUp()` reads as
    // "cannot answer" and never as "not found". Releasing the reservation on a missing answer
    // would be releasing it on a transfer that may well have landed.
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response(['message' => 'Not found'], 404),
    ]);

    Mail::fake();

    $schedule = CheckPayoutStatus::schedule();
    $total = count($schedule);

    // Rung by rung, each one questioning, hearing nothing, and pushing the next. The delay is
    // read off the pushed job, so the index arithmetic is covered where it is used and not
    // merely where it is defined.
    for ($check = 1; $check < $total; $check++) {
        $next = $check + 1;
        $gap = $schedule[$check];

        Queue::fake();
        ps59Check($payout->fresh(), $check);

        Queue::assertPushed(
            CheckPayoutStatus::class,
            fn (CheckPayoutStatus $job): bool => $job->check === $next
                && $job->delay !== null
                && $job->delay->between(now()->addSeconds($gap - 1), now()->addSeconds($gap + 1)),
        );

        expect($payout->fresh()->status)->toBe(Payout::STATUS_PROCESSING);
    }

    // The last rung has nothing after it. It asks, hears nothing, and tells a person — and
    // because there is no tenth gap, it cannot push a tenth question and cannot pay for a
    // second email.
    Queue::fake();
    ps59Check($payout->fresh(), $total);

    Queue::assertNotPushed(CheckPayoutStatus::class);
    Mail::assertSent(AdminPayoutUnconfirmedMail::class, 1);

    Mail::assertSent(AdminPayoutUnconfirmedMail::class, function (AdminPayoutUnconfirmedMail $mail) use ($payout): bool {
        expect($mail->checks)->toBe(9)
            ->and((int) $mail->payout->id)->toBe((int) $payout->id)
            ->and($mail->business->id)->toBe($payout->business_id)
            ->and($mail->since)->not->toBeNull();

        return true;
    });

    // The copy is the load-bearing part of this change, so it is read rather than reviewed.
    // `AdminPayoutsStalledMail` says the money never left the wallet and offers a retry, and
    // every word of that is false — or expensive — for a transfer that may have landed.
    Mail::assertSent(AdminPayoutUnconfirmedMail::class, function (AdminPayoutUnconfirmedMail $mail): bool {
        $html = $mail->render();

        expect($html)->toContain('Do not resend this transfer yet')
            ->and($html)->toContain('The money is out of the wallet and we cannot confirm where it went')
            ->and($html)->not->toContain('Retry sends it again immediately')
            ->and($html)->not->toContain('never left their wallet')
            ->and($html)->toContain($mail->payout->reference);

        return true;
    });
});

// ---------------------------------------------------------------------------
// The clock on a sync deployment
// ---------------------------------------------------------------------------

test('on a sync queue the clock never starts and the provider is never asked', function () {
    // Deliberately **without** `Queue::fake()`, and that is the only way to see this: the
    // suite runs the sync driver, so a "delayed" job would run inline — inside the checkout
    // that collected the sale — and recurse down the whole ladder in that one call. If the
    // stand-down were missing, the lookup below would have been sent and a mail dispatched.
    ps59Platform();
    ps59Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = ps59Business();
    ps59Provider();

    Mail::fake();

    $payout = ps59InFlight($business, $store);

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'completed']),
    ]);

    expect($payout->status)->toBe(Payout::STATUS_PROCESSING)
        ->and(ps59LookupWasAsked())->toBeFalse();

    // The credit that funded the payout mails the business, so the assertion is about the
    // alert rather than about the mailbox being empty.
    Mail::assertNotSent(AdminPayoutUnconfirmedMail::class);
});

// ---------------------------------------------------------------------------
// The operator's button
// ---------------------------------------------------------------------------

test('an operator can ask the provider about one transfer and be told what happened', function () {
    ps59Platform();
    ps59Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = ps59Business();
    ps59Provider();

    $payout = ps59InFlight($business, $store);
    $admin = ps59Admin();

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'completed']),
    ]);

    $response = $this->withToken(ps59Token($admin))
        ->postJson('/api/v1/admin/payouts/'.$payout->reference.'/check')
        ->assertOk();

    // Addressed by the reference, which is the model's route key: a URL is the same thing an
    // operator reads off the console and quotes to the provider.
    expect($response->json('data.status'))->toBe(Payout::STATUS_PAID)
        ->and($response->json('data.result'))->toBe('settled')
        ->and($response->json('data.checked'))->toBeTrue()
        ->and($response->json('data.reference'))->toBe($payout->reference)
        ->and($response->json('message'))->toContain('recorded as paid');

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID);
});

test('an operator asking about a finished transfer is told, and the provider is not bothered', function () {
    ps59Platform();
    ps59Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = ps59Business();
    ps59Provider();

    $payout = ps59InFlight($business, $store);
    $payout->forceFill(['status' => Payout::STATUS_PAID, 'paid_at' => now()])->save();

    $admin = ps59Admin();

    Http::fake();

    $response = $this->withToken(ps59Token($admin))
        ->postJson('/api/v1/admin/payouts/'.$payout->reference.'/check')
        ->assertOk();

    expect($response->json('data.checked'))->toBeFalse()
        ->and($response->json('data.result'))->toBeNull()
        ->and($response->json('message'))->toContain('already paid')
        ->and($response->json('message'))->toContain('not sent to the provider again');

    // A stale answer put against a finished row is the one thing re-asking could do, so it
    // does not ask at all.
    expect(ps59LookupWasAsked())->toBeFalse();
});

test('checking a transfer is gated like the rest of the money screens', function () {
    ps59Platform();
    ps59Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = ps59Business();
    ps59Provider();

    $payout = ps59InFlight($business, $store);

    (new SpatiePermissionSeeder)->run();

    // A business "Super Admin" bundles the admin.* permission names, so the route permission
    // alone would let one through — which is why the controller also calls `EnsuresPlatformAdmin`.
    [$owner] = createBusinessOwner();
    setPermissionsTeamId($owner->business_id);
    $owner->assignRole('Super Admin');

    $this->withToken(ps59Token($owner))
        ->postJson('/api/v1/admin/payouts/'.$payout->reference.'/check')
        ->assertForbidden();

    // An admin whose role does not carry `admin.finance` is refused by the middleware.
    $support = ps59Admin('Support Admin');

    $this->withToken(ps59Token($support))
        ->postJson('/api/v1/admin/payouts/'.$payout->reference.'/check')
        ->assertForbidden();

    // Both refusals happen before the provider is named, let alone asked.
    expect($payout->fresh()->status)->toBe(Payout::STATUS_PROCESSING)
        ->and(ps59LookupWasAsked())->toBeFalse();
});
