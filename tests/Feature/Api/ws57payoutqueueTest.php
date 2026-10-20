<?php

use App\Enums\TransactionStatus;
use App\Exceptions\PayoutNotSentException;
use App\Jobs\SettlePayouts;
use App\Mail\AdminPayoutsStalledMail;
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
use App\Services\Payments\Custody\PayoutHoldService;
use App\Services\Payments\Custody\PayoutSweepService;
use App\Services\Payments\Custody\PlatformPayoutNotifier;
use App\Services\Payments\Custody\WalletSummaryService;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| WS-57 — the payout is a queued job with a budget, and nothing runs on a clock.
|
| The three pieces this pins, in the order the owner asked for them:
|
| **The request does not wait.** `SettlePayouts` is dispatched by every path that
| collects a sale, and the send happens on a worker. Asserted with `Queue::fake()`,
| because the suite runs the sync driver — without the fake, every assertion below
| would actually be asking the provider, and a test that calls the provider from
| inside the request cannot tell you whether the request would have.
|
| **The retry is the worker's, and the gaps are minutes.** `tries()` and `backoff()`
| are declared on the job and read `custody.retry_backoff_minutes`. The unit matters
| more than the numbers: the queue's own `QUEUE_BACKOFF` is seconds, so a job that
| read that key would retry a provider that is down ten seconds later and give up
| inside a minute — which is the opposite of what a down provider needs.
|
| **Nothing is scheduled.** `custody:promote` and `custody:payouts` still exist and
| are still the right tool during an incident, but nothing calls them on a clock.
| The absence is asserted rather than trusted, because re-adding a schedule entry is
| a one-line change that looks like a safety net and is actually a second payer.
*/

function pq57Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function pq57Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

function pq57Token(User $user, string $audience = 'admin'): string
{
    return $user->createToken('pq57-access', [$audience], now()->addHour())->plainTextToken;
}

function pq57Admin(string $role = 'Finance Admin'): User
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
function pq57Business(): array
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
        'slug' => 'pq57-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ]);

    return [$owner, $business->fresh(), $store];
}

/**
 * A confirmed managed sale, credited to the wallet, and left **unpromoted**.
 *
 * Deliberately not matured: half of these tests are about the job being the thing
 * that promotes, and a helper that had already promoted would hide exactly the
 * regression they exist for.
 */
function pq57Credited(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $order = $store !== null ? Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'PQ57-'.strtoupper(Str::random(10)),
        'subtotal' => $kobo / 100,
        'total' => $kobo / 100,
    ]) : null;

    $sale = Transaction::create([
        'reference' => 'API_PQ57_'.strtoupper(Str::random(10)),
        'business_id' => $business->id,
        'order_id' => $order?->id,
        'amount' => $kobo / 100,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        // Zero fees, so the sale's net is exactly its amount and every figure
        // asserted below is a number the test itself chose.
        'fee_kobo' => 0,
        'platform_fee_kobo' => 0,
        'metadata' => ['payment_mode' => 'platform_managed', 'leg_method' => 'bachs'],
    ]);

    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    return $sale->fresh();
}

/**
 * Bachs: a spendable balance the template can dial, and a payouts endpoint that
 * either accepts or refuses.
 */
function pq57Provider(int $availableKobo = 50000000, int $payoutStatus = 200): void
{
    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => Http::response([
            'account_id' => 'org_pq57',
            'balances' => [[
                'currency' => 'NGN',
                'available_balance' => number_format($availableKobo / 100, 2, '.', ''),
                'pending_balance' => '0.00',
            ]],
            'pending_settlements_by_day' => [],
        ]),
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        // `processing`, not `pending`: Bachs has no `pending` status for a
        // transfer. It answers `processing` on acceptance and `completed` when
        // the money lands — read off the live API, so a fixture that says
        // `pending` is testing a provider that does not exist.
        'sandbox-api.bachs.io/v1/payouts' => $payoutStatus === 200
            ? Http::response(['id' => 'pay_1', 'status' => 'processing'])
            : Http::response(['message' => 'Transfer declined'], $payoutStatus),
    ]);
}

/** The job, run the way a worker would run it. */
function pq57Run(int $businessId): void
{
    (new SettlePayouts($businessId))->handle(
        app(InstantPayoutService::class),
        app(WalletSummaryService::class),
        app(PayoutSweepService::class),
    );
}

/** Three refusals on the business's record — the ceiling, without three provider calls. */
function pq57Exhausted(Business $business): void
{
    for ($i = 0; $i < 3; $i++) {
        Payout::create([
            'business_id' => $business->id,
            'provider' => 'bachs',
            'bank_code' => '058',
            'account_number' => '0123456789',
            'amount_kobo' => 1000,
            'currency' => 'NGN',
            'status' => Payout::STATUS_FAILED,
            'reference' => 'PO_PQ57_'.strtoupper(Str::random(10)),
            'failure_code' => 'payout_error',
            'failure_reason' => 'Transfer declined',
            'requested_at' => now(),
            'failed_at' => now(),
        ]);
    }
}

// ---------------------------------------------------------------------------
// The schedule
// ---------------------------------------------------------------------------

test('the retry gaps are the owner\'s list, in minutes', function () {
    // `5,30,60` and the unit together, because the unit is the whole trap: the
    // queue's own `QUEUE_BACKOFF` is seconds, and the same string read from that key
    // would be five seconds. Pinned against the *shipped default* rather than a value
    // set here, so changing the config without meaning to is a failure.
    expect(config('custody.retry_backoff_minutes'))->toBe([5, 30, 60]);

    $job = new SettlePayouts(1);

    expect($job->backoff())->toBe([300, 1800, 3600])
        // The queue's budget and the sweep's own `retries_exhausted` ceiling are the
        // same number on purpose, so the job giving up and the business being named
        // as blocked happen together rather than one trailing the other.
        ->and($job->tries())->toBe(3)
        ->and($job->tries())->toBe((int) config('custody.retry_attempts'));
});

test('neither custody command is on the schedule', function () {
    // A second payer would not double-pay — the reservation refuses to overdraw —
    // but it would put a provider call back on a clock and make the log unreadable.
    // Asserted because adding the entry back is one plausible-looking line.
    $events = app(Schedule::class)->events();

    $commands = collect($events)
        ->map(fn ($event): string => (string) ($event->command ?? ''))
        ->implode(' | ');

    expect($events)->not->toBeEmpty()
        ->and($commands)->not->toContain('custody:payouts')
        ->and($commands)->not->toContain('custody:promote');
});

// ---------------------------------------------------------------------------
// The job retries a refusal, and refuses to retry a decision
// ---------------------------------------------------------------------------

test('a refused send comes back on the retry schedule', function () {
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider(payoutStatus: 422);

    pq57Credited($business, 100000, $store);

    // The throw is the whole mechanism, and it is worth naming: the worker's
    // `calculateBackoff()` and `markJobAsFailedIfWillExceedMaxAttempts()` are only
    // reached from the exception handler, so returning quietly here would mean a
    // refusal was never retried and never alerted on. The money is back in the
    // wallet by the time it throws — `fail()` credits it back — so the retry has
    // something to send.
    expect(fn () => pq57Run((int) $business->id))
        ->toThrow(PayoutNotSentException::class);

    expect((int) $business->fresh()->available_balance)->toBe(100000);
});

test('a business being withheld is not retried', function () {
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider();

    // A hold is a decision somebody made on purpose. Retrying it would spend the
    // attempts and send an operator an alert about a page with nothing wrong on it,
    // which is the thing `STALLING_BLOCKERS` exists to avoid.
    app(PayoutHoldService::class)->holdBusiness($business, 'Orders under review');

    pq57Credited($business, 100000, $store);

    // Returns rather than throws: `withheld` is an answer, and the clock changes
    // nothing about it.
    pq57Run((int) $business->id);

    expect(Payout::query()->where('business_id', $business->id)->count())->toBe(0)
        // And the money matured on the way through, because a hold stops money being
        // *sent* rather than changing what the wallet records as owed.
        ->and((int) $business->fresh()->available_balance)->toBe(100000);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/payouts/destinations'));
});

test('money the provider never moved is retried even when nothing was refused', function () {
    // The case the tally cannot see. With the balance unreadable the gate falls back
    // to the 72-hour floor, so a fresh sale stays in `pending_balance`; the sweep
    // then reports an empty tally, exactly as it does for a business that owes
    // nothing. Retrying on the tally alone would therefore give up silently on the
    // commonest outage there is.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => Http::response(['message' => 'Bad gateway'], 502),
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'processing']),
    ]);

    pq57Credited($business, 100000, $store);

    expect(fn () => pq57Run((int) $business->id))
        ->toThrow(PayoutNotSentException::class);

    // Nothing was sent, and nothing is lost: the sale is still the business's.
    expect(Payout::query()->where('business_id', $business->id)->count())->toBe(0)
        ->and((int) $business->fresh()->pending_balance)->toBe(100000);
});

test('a send that goes through does not come back', function () {
    // The control. Without it the two tests above would pass for a job that throws
    // unconditionally, which is a job that retries a payout it already sent.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider();
    pq57Credited($business, 100000, $store);

    pq57Run((int) $business->id);

    $payout = Payout::query()->where('business_id', $business->id)->sole();

    expect($payout->status)->toBe(Payout::STATUS_PROCESSING)
        ->and($payout->amount_kobo)->toBe(100000)
        // In flight, so out of both buckets: the money has left the wallet and has
        // not yet been confirmed as arrived.
        ->and((int) $business->fresh()->available_balance)->toBe(0)
        ->and((int) $business->fresh()->pending_balance)->toBe(0);
});

// ---------------------------------------------------------------------------
// The budget is spent, and a person is told
// ---------------------------------------------------------------------------

test('the last attempt spent, the admins are told', function () {
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider();
    pq57Credited($business, 100000, $store);

    Mail::fake();

    // `failed()` is reached by the worker and **not** through the container, so
    // everything it needs it resolves itself. Called directly here for the same
    // reason: this is the contract the worker has with the job.
    (new SettlePayouts((int) $business->id))->failed(
        new RuntimeException('the provider never answered'),
    );

    Mail::assertSent(AdminPayoutsStalledMail::class, 1);
});

test('the alert reports the money as still owed rather than as sent', function () {
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider();
    pq57Credited($business, 100000, $store);

    // Matured, so the wallet says it is owed — and then nothing sent it, because
    // every attempt went nowhere. This is the state an operator has to be able to
    // read off the alert: money in the wallet, no transfer, attempts spent.
    app(CustodyService::class)->promoteSettled();

    Mail::fake();

    (new SettlePayouts((int) $business->id))->failed(
        new RuntimeException('the provider never answered'),
    );

    Mail::assertSent(AdminPayoutsStalledMail::class, function (AdminPayoutsStalledMail $mail) use ($business): bool {
        return (int) $mail->business->id === (int) $business->id
            && $mail->heldKobo === 100000
            && $mail->failures === 3;
    });
});

// ---------------------------------------------------------------------------
// A redelivered webhook is the cheapest net, and it is taken
// ---------------------------------------------------------------------------

test('a redelivered webhook queues a settlement instead of stopping at already-processed', function () {
    // The webhook used to answer "already processed" and do nothing else, which made
    // the provider's own redelivery — free, and signed, and it arrives anyway — worth
    // nothing. It is now the net for a settlement that was never dispatched: the
    // queue write failed, the worker was down, instant payout was off at that moment.
    pq57Platform();
    pq57Settings([
        'system_payment_processor' => 'bachs',
        'platform_commission_percent' => 0,
        'platform_commission_fixed_kobo' => 0,
    ]);

    [, $business, $store] = pq57Business();

    $reference = 'API_PQ57_HOOK';

    Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'PQ57-HOOK',
        'subtotal' => 1000,
        'total' => 1000,
        'status' => 'pending',
    ]);

    $transaction = Transaction::create([
        'reference' => $reference,
        'business_id' => $business->id,
        'amount' => 1000,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'fee_kobo' => 0,
        'platform_fee_kobo' => 0,
        'metadata' => ['payment_mode' => 'platform_managed', 'leg_method' => 'bachs'],
    ]);

    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');

    Queue::fake();

    $body = json_encode([
        'id' => 'evt_pq57',
        'type' => 'collection.succeeded',
        'data' => ['checkout_id' => $reference, 'reference' => $reference, 'status' => 'SUCCESS'],
    ]);

    $timestamp = time();

    test()->call('POST', '/webhooks/connect/bachs', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_BACHS_SIGNATURE_V2' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_platform'),
    ], $body)->assertOk()->assertJsonPath('status', 'already processed');

    // Dispatched from the already-processed branch, which is the whole change. Safe
    // unconditionally: the settlement is idempotent and a sale that is not the
    // platform's is answered by `afterCollection()`'s guards before any provider call.
    Queue::assertPushed(
        SettlePayouts::class,
        fn (SettlePayouts $job): bool => $job->businessId === (int) $business->id,
    );
});

// ---------------------------------------------------------------------------
// The operator's retry
// ---------------------------------------------------------------------------

test('the retry clears the ceiling and queues the attempt that pays it', function () {
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider();

    // Matured and owed, with three refusals on the record. The money has been in
    // `available_balance` the whole time — a failed payout already returned its
    // reservation — so retiring the ceiling is all that stands between it and a send.
    $sale = pq57Credited($business, 100000, $store);
    app(CustodyService::class)->promoteSettled();
    pq57Exhausted($business);

    expect((int) $business->fresh()->available_balance)->toBe(100000)
        ->and(app(PayoutSweepService::class)->payoutBlocker($business)['code'] ?? null)
        ->toBe('retries_exhausted');

    Queue::fake();

    $admin = pq57Admin();

    $response = $this->withToken(pq57Token($admin))
        ->postJson('/api/v1/admin/businesses/'.$business->business_code.'/payouts/retry');

    $response->assertOk();

    // The sentence says "queued", not "sent", and that is the honest word: the
    // transfer has not been attempted yet, and an operator reading "sent" over a
    // payout that had not gone out is the failure this button exists to avoid.
    expect($response->json('data'))->toMatchArray([
        'business_id' => (int) $business->id,
        'cleared' => 3,
        'queued' => true,
        'blocker' => null,
    ]);

    Queue::assertPushed(
        SettlePayouts::class,
        fn (SettlePayouts $job): bool => $job->businessId === (int) $business->id
            // No transaction: the operator's retry is not about one sale, and the
            // job going straight to `settle()` is what makes the promotion happen
            // for money an outage stranded in `pending_balance`.
            && $job->transactionId === null,
    );

    expect(Payout::query()->where('business_id', $business->id)->where('status', Payout::STATUS_FAILED)->count())
        ->toBe(0);

    expect($sale->fresh()->promoted_at)->not->toBeNull();
});

test('a retry the platform does not trust queues nothing and says why', function () {
    // The button is not a lever. Given a business a hold or a ceiling it cannot
    // clear, it sends nothing and names the reason rather than reporting a queue
    // that will find nothing to do.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();
    [, $bankless] = pq57Business();

    pq57Provider();

    pq57Credited($business, 100000, $store);
    app(CustodyService::class)->promoteSettled();
    pq57Exhausted($business);

    pq57Credited($bankless, 100000);
    pq57Exhausted($bankless);

    // No bank on file: a destination nobody can send to is not a ceiling, and a
    // queued attempt against it would be a lie told by omission.
    $bankless->forceFill(['payout_bank_id' => null])->save();

    Queue::fake();

    $admin = pq57Admin();

    $queued = $this->withToken(pq57Token($admin))
        ->postJson('/api/v1/admin/businesses/'.$business->business_code.'/payouts/retry')
        ->assertOk();

    expect($queued->json('data.queued'))->toBe(true);

    $blocked = $this->withToken(pq57Token($admin))
        ->postJson('/api/v1/admin/businesses/'.$bankless->business_code.'/payouts/retry')
        ->assertOk();

    expect($blocked->json('data.queued'))->toBe(false)
        ->and($blocked->json('data.blocker.code'))->toBe('no_bank')
        ->and($blocked->json('message'))->toContain('Nothing will be sent');

    Queue::assertPushed(SettlePayouts::class, 1);
});

test('a business without the finance permission cannot press retry', function () {
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();
    pq57Credited($business, 100000, $store);
    app(CustodyService::class)->promoteSettled();
    pq57Exhausted($business);

    (new SpatiePermissionSeeder)->run();

    // A business "Super Admin" bundles the admin.* permission names, so the route
    // permission alone would let one through — which is why every controller here
    // also calls `EnsuresPlatformAdmin`.
    [$owner] = createBusinessOwner();
    setPermissionsTeamId($owner->business_id);
    $owner->assignRole('Super Admin');

    $this->withToken(pq57Token($owner))
        ->postJson('/api/v1/admin/businesses/'.$business->business_code.'/payouts/retry')
        ->assertForbidden();

    $this->withToken(pq57Token($owner))
        ->postJson('/api/v1/admin/payouts/retry')
        ->assertForbidden();

    // An admin whose role does not carry `admin.finance` is refused by the middleware.
    $support = pq57Admin('Support Admin');

    $this->withToken(pq57Token($support))
        ->postJson('/api/v1/admin/payouts/retry')
        ->assertForbidden();

    // The ceiling is exactly where the refusals left it, and nothing was queued.
    expect(app(PayoutSweepService::class)->payoutBlocker($business)['code'] ?? null)
        ->toBe('retries_exhausted');
});

test('retry-all queues every business the alert would have named', function () {
    // `RETRYABLE_BLOCKERS` is a superset of `STALLING_BLOCKERS` on purpose. If the
    // batch retry filtered on the narrower list it would skip exactly the businesses
    // the job's own `settlement_failed` alert had just named — an email saying "tell
    // somebody" sitting beside a button that ignored them.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $first, $store] = pq57Business();
    [, $second] = pq57Business();

    pq57Provider();

    pq57Credited($first, 100000, $store);
    pq57Credited($second, 60000);
    app(CustodyService::class)->promoteSettled();

    pq57Exhausted($first);
    pq57Exhausted($second);

    Queue::fake();

    $admin = pq57Admin();

    $response = $this->withToken(pq57Token($admin))
        ->postJson('/api/v1/admin/payouts/retry')
        ->assertOk();

    expect($response->json('data.queued'))->toBe(2)
        ->and($response->json('data.blocked'))->toBe(0);

    Queue::assertPushed(SettlePayouts::class, 2);
});

test('a business the provider stranded in the wallet is paid by the retry, not merely told', function () {
    // The regression this whole file exists for. `sweepBusiness()` sends what is in
    // `available_balance` and never consults the promotion gate, so an operator
    // retry that called it directly would find money still in `pending_balance` after
    // a provider outage, send nothing, and report success — a button that appears to
    // work while the business stays unpaid.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider();

    // Credited and payable, and deliberately **not** promoted: whatever promotion
    // happens from here is the thing under test.
    $sale = pq57Credited($business, 100000, $store);

    expect((int) $business->fresh()->pending_balance)->toBe(100000)
        ->and((int) $business->fresh()->available_balance)->toBe(0);

    pq57Run((int) $business->id);

    // Promoted on the way through, and then sent.
    expect($sale->fresh()->promoted_at)->not->toBeNull();

    $payout = Payout::query()->where('business_id', $business->id)->sole();

    expect($payout->amount_kobo)->toBe(100000)
        ->and($payout->status)->toBe(Payout::STATUS_PROCESSING)
        ->and((int) $business->fresh()->available_balance)->toBe(0);
});

// ---------------------------------------------------------------------------
// Dispatch sites
// ---------------------------------------------------------------------------

test('a sale that is collected queues its settlement rather than sending from the request', function () {
    // The point of the whole move. Asserted with the queue faked, which is the only
    // way to see it: the suite runs the sync driver, so without the fake the job
    // would run inline and this would be asserting the opposite of what it means to.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    Queue::fake();

    // Standing in for the three collection sites, each of which dispatches exactly
    // this. They differ in where the payment came from and not at all in what they do
    // with it, which is why the assertion lives on the job's own door.
    SettlePayouts::forSale(pq57Credited($business, 100000, $store));

    Queue::assertPushed(
        SettlePayouts::class,
        fn (SettlePayouts $job): bool => $job->businessId === (int) $business->id,
    );

    // The provider was never asked inside the request, and the money is credited but
    // not moved. This is the shape that makes a slow provider cost the checkout
    // nothing.
    Http::assertNothingSent();

    expect((int) $business->fresh()->pending_balance)->toBe(100000)
        ->and(Payout::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// The operator's view of money that is moving
// ---------------------------------------------------------------------------

test('money in flight reads as one shape to the operator and another to the business', function () {
    // `platformInFlight()` and `inFlightPayload()` are two payloads, not one with a
    // flag: the queue is platform-wide, so its rows name the business and carry no
    // account digits (an operator scanning every transfer has no reconciliation to do
    // against one business's bank account), and it renames `since` to `oldest_since`
    // and `destinations` to `transfers`. A client that assumes one shape for both is
    // a screen that renders an empty table and reports no error, so the difference is
    // asserted here rather than left to whoever reads the service next.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider();
    pq57Credited($business, 100000, $store);

    pq57Run((int) $business->id);

    $payout = Payout::query()->where('business_id', $business->id)->sole();
    $admin = pq57Admin();
    $token = pq57Token($admin);

    // The queue's copy. A business whose money is entirely reserved has an
    // `available_balance` of zero, so it is not a candidate and does not appear on
    // the list below this block at all — this is the only thing that accounts for
    // money an operator would otherwise read as missing.
    $flight = $this->withToken($token)
        ->getJson('/api/v1/admin/payout-queue')
        ->assertOk()
        ->json('data.queue.in_flight');

    expect($flight['amount_kobo'])->toBe(100000)
        ->and($flight['amount'])->toEqual(1000.0)
        ->and($flight['count'])->toBe(1)
        ->and($flight['oldest_since'])->toBe($payout->requested_at->toIso8601String())
        ->and($flight['stale'])->toBeFalse()
        // The totals above are whole even when the row list is capped, and this is
        // what says the list is short.
        ->and($flight['truncated'])->toBeFalse()
        ->and($flight)->toHaveKey('transfers')
        ->and($flight)->not->toHaveKey('destinations')
        ->and($flight['transfers'])->toHaveCount(1);

    $row = $flight['transfers'][0];

    expect($row['business_id'])->toBe((int) $business->id)
        ->and($row['business_name'])->toBe($business->fresh()->name)
        ->and($row['business_code'])->toBe($business->business_code)
        ->and($row['reference'])->toBe($payout->reference)
        ->and($row['amount_kobo'])->toBe(100000)
        ->and($row['status'])->toBe(Payout::STATUS_PROCESSING)
        ->and($row)->not->toHaveKey('account_last_four')
        ->and($row)->not->toHaveKey('account_number');

    // And the business's own copy of the same fact, which is about where *its* money
    // went and so does carry the destination.
    $own = $this->withToken($token)
        ->getJson('/api/v1/admin/businesses/'.$business->business_code.'/wallet')
        ->assertOk()
        ->json('data.wallet.in_flight');

    expect($own['since'])->toBe($payout->requested_at->toIso8601String())
        ->and($own)->toHaveKey('destinations')
        ->and($own)->not->toHaveKey('transfers')
        ->and($own['destinations'])->toHaveCount(1)
        ->and($own['destinations'][0]['account_last_four'])->toBe('6789')
        ->and($own['destinations'][0]['bank_code'])->toBe('058');
});

// ---------------------------------------------------------------------------
// A stall says which stall it is
// ---------------------------------------------------------------------------
//
// Everything above pins *that* the job retries. These pin what anybody reading
// the log or the alert afterwards can actually learn from it.
//
// The problem they were written for is narrow and was found in production: a
// sale collected, `custody.payout.instant` reporting `matured: 0`, an empty
// outcome, and a job retrying — with nothing anywhere saying why the money had
// not moved. Every way of arriving at that state produces the same record, and
// the ways need opposite responses. A provider that has not settled a customer's
// charge yet will fix itself; a platform balance every naira of which is already
// promised somewhere else will not. Only the terms of the promotion budget
// distinguish them, so those terms are now carried out with the answer.

test('the promotion gate hands back the terms it subtracted', function () {
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business] = pq57Business();

    // The provider is holding ₦3,000. ₦1,000 of that is already owed to this
    // business and promoted, and ₦500 is out in a transfer on its way — so only
    // what is left over is available to mature a fresh sale from.
    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => Http::response([
            'balances' => [['currency' => 'NGN', 'available_balance' => '3000.00']],
        ]),
    ]);

    $business->forceFill(['available_balance' => 100000])->save();

    Payout::create([
        'business_id' => $business->id,
        'provider' => 'bachs',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'amount_kobo' => 50000,
        'currency' => 'NGN',
        'status' => Payout::STATUS_PROCESSING,
        'reference' => 'PO_PQ57_'.strtoupper(Str::random(10)),
        'requested_at' => now(),
    ]);

    $headroom = (int) config('custody.payout_fee_headroom_kobo', 0);
    $gate = app(PayoutSweepService::class)->promotionGate();

    expect($gate['measured'])->toBeTrue()
        ->and($gate['components'])->toBe([
            'available' => 300000,
            'liability' => 100000,
            'in_flight' => 50000,
            'headroom' => $headroom,
        ])
        // Stated as the subtraction rather than as a number, so a change in the
        // headroom default moves this test into failing rather than into agreeing.
        ->and($gate['budget'])->toBe(300000 - 100000 - 50000 - $headroom);
});

test('a gate that could not measure reports no terms at all', function () {
    // The other half of the contract, and the one a caller has to branch on: no
    // budget, no terms, and a floor instead. A provider that cannot be read has
    // not been shown to be empty, and reporting zeros here would say it had.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['message' => 'Bad gateway'], 502)]);

    $gate = app(PayoutSweepService::class)->promotionGate();

    expect($gate['components'])->toBeNull()
        ->and($gate['measured'])->toBeFalse()
        ->and($gate['budget'])->toBeNull()
        ->and($gate['floor'])->not->toBeNull();
});

test('a provider holding nothing is named as a settlement, not as a lost transfer', function () {
    // The production shape exactly: Bachs answers, and answers '0.00'. That is a
    // successful read of an empty balance — a customer's charge that has arrived
    // but not settled — and it is a state that clears itself. It must not read
    // like the platform failing to pay somebody, which is what "the money never
    // left the wallet" said about it.
    //
    // This runs on the `sync` connection, so `recheckLater()` stands down and the
    // throw happens — which is what makes the reason string and the log context
    // observable here at all. The quiet path is pinned separately, under
    // `Queue::fake()`, where a delay means something.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider(0);
    pq57Credited($business, 100000, $store);

    Log::spy();

    $caught = null;

    try {
        pq57Run((int) $business->id);
    } catch (PayoutNotSentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(PayoutNotSentException::class)
        ->and($caught->reason)->toBe('the provider is not holding a released balance for these sales yet');

    // The retry log carries the arithmetic, which is what makes the line worth
    // reading: `measured: true` with an `available` of zero is a provider that
    // answered and had nothing, and is a completely different incident from
    // `measured: false`.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => $message === 'custody.payout.retry'
            && ($context['measured'] ?? null) === true
            && ($context['budget_kobo'] ?? null) === 0
            && ($context['gate']['available'] ?? null) === 0
            && ($context['gate']['liability'] ?? null) === 0
            && ($context['gate']['in_flight'] ?? null) === 0
            && ($context['pending_kobo'] ?? null) === 100000)
        ->atLeast()->once();
});

test('a balance spoken for elsewhere is named differently from a provider that has not paid', function () {
    // Read, holding money, and committed anyway — the provider holds exactly what
    // the platform owes it and not a naira more, so the fee reserve leaves nothing
    // to send with. Worth retrying, and for a different reason than a settlement
    // lag: it clears when the other money moves, not when the provider settles.
    // Two incidents, two sentences, one retry.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    // Holding ₦100 and owing ₦100 — the provider is readable and has money, and
    // the money is not this business's.
    pq57Provider(10000);

    $other = Business::create([
        'user_id' => createBusinessOwner()[0]->id,
        'name' => 'Other PQ57 Trader',
        'status' => 'active',
    ]);

    // Promoted and not yet sent, which is what makes it a liability: the platform
    // owes this to somebody else out of the same provider balance.
    $other->forceFill(['available_balance' => 10000])->save();

    pq57Credited($business, 100000, $store);

    $caught = null;

    try {
        pq57Run((int) $business->id);
    } catch (PayoutNotSentException $e) {
        $caught = $e;
    }

    expect($other->id)->not->toBe($business->id)
        ->and($caught)->toBeInstanceOf(PayoutNotSentException::class)
        ->and($caught->reason)->toBe(
            'the provider\'s balance is already spoken for — NGN 100.00 held, NGN 100.00 owed, '
            .'the rest reserved for transfers and fees',
        );
});

test('a platform whose wallets overstate the provider names the shortfall in naira', function () {
    // The production shape, and the fault this vocabulary was widened for. Bachs
    // answers truthfully with what it holds; the wallets record several times that
    // as owed. Nothing about the state improves with time, every business is gated
    // off behind it, and the sentence has to carry the size of the gap — it is the
    // one stall of the five an operator cannot act on from the words alone.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    // Holding ₦1,382.15, and — across wallets the platform has already credited —
    // owing ₦11,434.18.
    pq57Provider(138215);

    $other = Business::create([
        'user_id' => createBusinessOwner()[0]->id,
        'name' => 'Overstated PQ57 Trader',
        'status' => 'active',
    ]);

    $other->forceFill(['available_balance' => 1143418])->save();

    pq57Credited($business, 110250, $store);

    Log::spy();

    $caught = null;

    try {
        pq57Run((int) $business->id);
    } catch (PayoutNotSentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(PayoutNotSentException::class)
        ->and($caught->reason)->toBe(
            'the provider holds NGN 1,382.15 but the platform owes NGN 11,434.18 across all businesses, '
            .'NGN 10,052.03 short',
        );

    // The two figures that make the line actionable, on the retry log as well as in
    // the alert: `provider_can_fund` is what stopped the job waiting quietly, and
    // `shortfall_kobo` is the amount to reconcile.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => $message === 'custody.payout.retry'
            && ($context['provider_can_fund'] ?? null) === false
            && ($context['shortfall_kobo'] ?? null) === 1005203
            && ($context['gate']['available'] ?? null) === 138215
            && ($context['gate']['liability'] ?? null) === 1143418)
        ->atLeast()->once();
});

test('a provider that cannot fund a payout is not waited on quietly', function () {
    // The routing half of the same fault, and the reason the figures alone were not
    // enough. Before this, a shortfall produced the identical record to a settlement
    // lag and took the identical path: the job stood itself down and re-checked in
    // an hour, then four, then twelve, and only then told anybody — by which point
    // seventeen hours of an operator's chance to reconcile it had gone.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider(138215);

    $other = Business::create([
        'user_id' => createBusinessOwner()[0]->id,
        'name' => 'Overstated PQ57 Trader',
        'status' => 'active',
    ]);

    $other->forceFill(['available_balance' => 1143418])->save();

    pq57Credited($business, 110250, $store);

    Queue::fake();

    $caught = null;

    try {
        pq57Run((int) $business->id);
    } catch (PayoutNotSentException $e) {
        $caught = $e;
    }

    // A real queue that could have deferred — and no deferral was queued, because
    // this stall is not a wait.
    expect($caught)->toBeInstanceOf(PayoutNotSentException::class);

    Queue::assertNotPushed(SettlePayouts::class, fn (SettlePayouts $job): bool => $job->lagAttempt > 0);
});

test('a provider that cannot be read is named as the settlement window, not as a refusal', function () {
    // The third shape, and the one whose retry genuinely pays off: nothing was
    // asked successfully, so a fresh sale is inside the conservative floor. The
    // job comes back because the provider might answer next time.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['message' => 'Bad gateway'], 502)]);

    pq57Credited($business, 100000, $store);

    $caught = null;

    try {
        pq57Run((int) $business->id);
    } catch (PayoutNotSentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(PayoutNotSentException::class)
        ->and($caught->reason)->toBe('the provider\'s balance could not be read, so a fresh sale is inside the conservative settlement window');
});

test('the alert an operator receives carries the cause, not just the count', function () {
    // The last reader in the chain, and the only one who cannot go and look. When
    // the attempts are spent the mail says which of the stalls this was, because
    // "nothing was sent" is equally true of all of them and therefore tells the
    // person deciding whether to press Retry nothing at all.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider(0);
    pq57Credited($business, 100000, $store);

    Mail::fake();

    $job = new SettlePayouts((int) $business->id);

    $job->failed(new PayoutNotSentException(
        $business,
        'the provider is not holding a released balance for these sales yet',
        3,
        3,
    ));

    Mail::assertSent(AdminPayoutsStalledMail::class, function (AdminPayoutsStalledMail $mail) use ($business): bool {
        return (int) $mail->business->id === (int) $business->id
            && $mail->blocker['code'] === PlatformPayoutNotifier::SETTLEMENT_FAILED
            && str_contains($mail->blocker['label'], 'the provider is not holding a released balance for these sales yet');
    });
});

// ---------------------------------------------------------------------------
// Two clocks, because a broken provider and a slow one are not the same event
// ---------------------------------------------------------------------------
//
// Everything above puts a provider that answered "not yet" on the same five-minute
// clock as a provider that is down. It is the one thing the owner's log showed to be
// wrong in practice: a card charge settles at Bachs on Bachs' own schedule, which is
// hours, so the retry budget — five then thirty then the third attempt — was spent
// inside the first hour, and the admins were emailed about a payment that had never
// been sendable and that needed nobody.
//
// These pin the split. `Queue::fake()` is required rather than incidental: `delay()`
// is meaningless on the `sync` connection, and `recheckLater()` deliberately stands
// down there rather than recursing down its own schedule inside one call, so the
// quiet path only exists where a queue can actually defer — which is exactly the
// production condition, and so exactly the condition worth testing.

test('the settlement wait is hours, where the provider-failure retry is minutes', function () {
    // The two lists side by side, because the whole distinction is their magnitude,
    // and a change that quietly made this one [5, 30, 60] would put the fault back
    // without failing anything else in this file.
    expect(config('custody.settlement_lag_backoff_minutes'))->toBe([60, 240, 720])
        ->and(config('custody.retry_backoff_minutes'))->toBe([5, 30, 60]);
});

test('money the provider has not released yet is waited on quietly', function () {
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    // Bachs answers, and answers zero. A successful read of an unsettled balance —
    // the production shape.
    pq57Provider(0);
    pq57Credited($business, 100000, $store);

    Queue::fake();
    Mail::fake();

    pq57Run((int) $business->id);

    // Nothing thrown, so the queue does not retry it on the fast clock, and nothing
    // mailed, so nobody is paged. The money is still owed and still in the wallet,
    // which is what the business sees and what the next check will send.
    expect((int) $business->fresh()->pending_balance)->toBe(100000)
        ->and(Payout::query()->where('business_id', $business->id)->count())->toBe(0);

    Queue::assertPushed(SettlePayouts::class, fn (SettlePayouts $job): bool => (int) $job->businessId === (int) $business->id
        && $job->lagAttempt === 1);

    Mail::assertNothingSent();
});

test('the first re-check is an hour out, and the last one twelve', function () {
    // The schedule is read by index, so the first quiet look after the sale is the
    // one an hour later and the last is twelve hours after that. Asserted through the
    // actual delay on the pushed job rather than through the config, so a bug in the
    // indexing — the reason `lagAttempt` exists at all — cannot pass.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider(0);
    pq57Credited($business, 100000, $store);

    Queue::fake();

    pq57Run((int) $business->id);

    Queue::assertPushed(
        SettlePayouts::class,
        fn (SettlePayouts $job): bool => $job->lagAttempt === 1
            && $job->delay !== null
            && $job->delay->between(now()->addMinutes(59), now()->addMinutes(61)),
    );

    // And the second and third, from the same chain, land on their own gaps.
    $second = new SettlePayouts((int) $business->id, null, 1);
    $third = new SettlePayouts((int) $business->id, null, 2);

    Queue::fake();
    $second->handle(app(InstantPayoutService::class), app(WalletSummaryService::class), app(PayoutSweepService::class));

    Queue::assertPushed(
        SettlePayouts::class,
        fn (SettlePayouts $job): bool => $job->lagAttempt === 2
            && $job->delay->between(now()->addMinutes(239), now()->addMinutes(241)),
    );

    Queue::fake();
    $third->handle(app(InstantPayoutService::class), app(WalletSummaryService::class), app(PayoutSweepService::class));

    Queue::assertPushed(
        SettlePayouts::class,
        fn (SettlePayouts $job): bool => $job->lagAttempt === 3
            && $job->delay->between(now()->addMinutes(719), now()->addMinutes(721)),
    );
});

test('when the settlement wait runs out, a person is told', function () {
    // The end of the chain. Not routine any more: a charge that has not settled in
    // seventeen hours is a thing to look at, and this is the moment the platform
    // stops being patient about it.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider(0);
    pq57Credited($business, 100000, $store);

    Queue::fake();
    Mail::fake();

    $spent = count((array) config('custody.settlement_lag_backoff_minutes'));

    $caught = null;

    try {
        // The job the last re-check pushed: the chain is spent, so this one is the
        // fast-path attempt that ends in the alert.
        (new SettlePayouts((int) $business->id, null, $spent))->handle(
            app(InstantPayoutService::class),
            app(WalletSummaryService::class),
            app(PayoutSweepService::class),
        );
    } catch (PayoutNotSentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(PayoutNotSentException::class)
        ->and($caught->reason)->toBe('the provider is not holding a released balance for these sales yet');

    // No further re-check: the patience is used up.
    Queue::assertNotPushed(SettlePayouts::class);

    // And the alert an operator gets says how long this has been going on, not just
    // that it failed.
    $job = new SettlePayouts((int) $business->id, null, $spent);
    $job->failed($caught);

    Mail::assertSent(AdminPayoutsStalledMail::class, function (AdminPayoutsStalledMail $mail) use ($spent): bool {
        return str_contains($mail->blocker['label'], 'the provider is not holding a released balance for these sales yet')
            && str_contains($mail->blocker['label'], $spent.' settlement re-checks');
    });
});

test('a provider that is actually broken gets the fast clock, not the patient one', function () {
    // The control, and the distinction the whole split rests on. An unreadable
    // provider is the provider being *down* — the one thing the owner said they
    // wanted to worry about — so it must be retried in minutes and escalated, not
    // quietly given seventeen hours.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['message' => 'Bad gateway'], 502)]);

    pq57Credited($business, 100000, $store);

    Queue::fake();

    expect(fn () => pq57Run((int) $business->id))
        ->toThrow(PayoutNotSentException::class);

    Queue::assertNotPushed(SettlePayouts::class);
});

test('an operator retry starts the patience over', function () {
    // A person pressing Retry has decided this is worth looking at again now. It must
    // not resume three hours into a quiet chain it was never told about, and it must
    // not inherit the spent flag — otherwise the button would do nothing on exactly
    // the businesses an operator is worried about.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider(0);
    pq57Credited($business, 100000, $store);

    Queue::fake();

    // What `PayoutRetryService` pushes — no lag attempt carried.
    SettlePayouts::forBusiness((int) $business->id);

    Queue::assertPushed(SettlePayouts::class, fn (SettlePayouts $job): bool => $job->lagAttempt === 0);

    Queue::fake();

    (new SettlePayouts((int) $business->id))->handle(
        app(InstantPayoutService::class),
        app(WalletSummaryService::class),
        app(PayoutSweepService::class),
    );

    // First quiet look, an hour out — the chain restarted rather than continuing.
    Queue::assertPushed(
        SettlePayouts::class,
        fn (SettlePayouts $job): bool => $job->lagAttempt === 1
            && $job->delay->between(now()->addMinutes(59), now()->addMinutes(61)),
    );
});

// ---------------------------------------------------------------------------
// Money that is owed and unsendable is still money somebody is looking for
// ---------------------------------------------------------------------------
//
// The last piece of the same fault. A business whose provider has not settled its
// customer's charge holds money the platform is liable for, is owed it, cannot be
// paid, and — before this — was on no screen an operator opens. `candidates()`
// listed available balances, so this business was not on the queue; the dry-run
// report printed "No custody business is holding a payable balance." while the
// platform held exactly that. Everything about the state was correct; only the
// ability to *see* it was missing, which for the person answering the phone is the
// same as it not being true.

test('a business whose money has not settled is still on the operator queue', function () {
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    // Bachs answers, and answers zero: the charge is pending at the provider.
    // Nothing is wrong, nothing is sendable, and the sale is still the business's.
    pq57Provider(0);
    pq57Credited($business, 110250, $store);

    $admin = pq57Admin();
    $token = pq57Token($admin);

    $queue = $this->withToken($token)
        ->getJson('/api/v1/admin/payout-queue')
        ->assertOk()
        ->json('data.queue');

    $row = collect($queue['businesses'])->firstWhere('id', (int) $business->id);

    expect($row)->not->toBeNull()
        ->and($row['payable_kobo'])->toBe(0)
        // The figure that makes the zero above readable rather than alarming.
        ->and($row['pending_kobo'])->toBe(110250)
        ->and($row['available_kobo'])->toBe(0)
        // And nothing is blocking it. There is no hold, no missing bank, no
        // exhausted destination: this business is simply waiting on its provider,
        // which is why the row must not be dressed up as a problem to fix.
        ->and($row['blocker'])->toBeNull();
});

test('a business that is owed nothing at all is still not a candidate', function () {
    // The control. Widening the query to include `pending_balance` must not turn
    // the queue into a list of every business on the platform — a business with
    // nothing owed in either bucket has no business being on a payouts screen, and
    // a list that grew to include them is a list nobody reads.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business] = pq57Business();

    expect(app(PayoutSweepService::class)->candidates()->pluck('id')->all())
        ->not->toContain((int) $business->id);

    $admin = pq57Admin();

    $queue = $this->withToken(pq57Token($admin))
        ->getJson('/api/v1/admin/payout-queue')
        ->assertOk()
        ->json('data.queue');

    expect(collect($queue['businesses'])->pluck('id')->all())
        ->not->toContain((int) $business->id);
});

// ---------------------------------------------------------------------------
// What the provider was sent, and what it said back
// ---------------------------------------------------------------------------
//
// The user-visible gap this closes: a business's money had not moved, its log
// showed the platform deciding not to send (a zero promotion budget), and nothing
// anywhere recorded whether a payout request had reached the provider at all. The
// provider's own dashboard — the first place anybody looks — said no such request
// existed, and no line here could confirm or deny it.

test('a payout request and the provider\'s answer to it are both logged', function () {
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    // Deliberately *not* `pq57Provider()`: `Http::fake()` merges stubs rather
    // than replacing them, and the earlier registration for the same URL wins —
    // so calling the shared template here and then overriding it below silently
    // keeps the template's two-key body and the test asserts against a response
    // nobody wrote.
    //
    // The acceptance body, as Bachs actually shapes it: an envelope around the
    // payout, carrying the destination it was drawn against — account number,
    // account holder, the lot.
    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => Http::response([
            'account_id' => 'org_pq57',
            'balances' => [['currency' => 'NGN', 'available_balance' => '500000.00', 'pending_balance' => '0.00']],
        ]),
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response([
            'id' => 'pay_ack_1',
            'reference' => 'ack-reference',
            'status' => 'processing',
            'amount' => '1000.00',
            'currency' => 'NGN',
            'destination' => [
                'id' => 'pd_new',
                'account_number' => '0123456789',
                'account_name' => 'ADA OKONKWO',
                'bank_code' => '058',
            ],
            'customer' => ['email' => 'ada@example.com', 'phone' => '08031234567'],
        ]),
    ]);

    pq57Credited($business, 100000, $store);

    Log::spy();

    pq57Run((int) $business->id);

    // The request, before it went out. Its presence is what makes a missing answer
    // legible: an attempt with a start and no acknowledgement is a request that is
    // still out there, which is a different fact from one that was never made.
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []) => $message === 'custody.payout.requesting'
            && ($context['reference'] ?? null) === ($context['reference'] ?? '')
            && ($context['amount_kobo'] ?? null) === 100000
            && ($context['provider'] ?? null) === 'bachs'
            && ($context['attempt'] ?? null) === 1
            // Four digits, which is what a support call can match on, and not a
            // digit more.
            && ($context['account_last_four'] ?? null) === '6789'
            && ! str_contains(json_encode($context), '0123456789'))
        ->atLeast()->once();

    // And the answer, carrying the provider's own status — the words its dashboard
    // will be showing — beside ours.
    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context = []): bool {
            if ($message !== 'custody.payout.acknowledged') {
                return false;
            }
            $encoded = json_encode($context);

            return ($context['outcome'] ?? null) === 'accepted'
                && ($context['provider_status'] ?? null) === 'processing'
                && ($context['transfer_id'] ?? null) === 'pay_ack_1'
                // The acknowledgement body is there…
                && ($context['ack']['id'] ?? null) === 'pay_ack_1'
                && ($context['ack']['status'] ?? null) === 'processing'
                && ($context['ack']['amount'] ?? null) === '1000.00'
                // …and every field that could name a person or their account is not.
                && ($context['ack']['destination']['account_number'] ?? null) === '[redacted]'
                && ($context['ack']['destination']['account_name'] ?? null) === '[redacted]'
                // `customer` is scrubbed whole rather than field by field — the
                // block is a person, and a nested walk of it would only be a longer
                // way of saying the same thing.
                && ($context['ack']['customer'] ?? null) === '[redacted]'
                // The bank code is not a person and is not scrubbed — an operator
                // checking whether the money went to the right bank needs it.
                && ($context['ack']['destination']['bank_code'] ?? null) === '058'
                && ! str_contains((string) $encoded, '0123456789')
                && ! str_contains((string) $encoded, 'ADA OKONKWO')
                && ! str_contains((string) $encoded, 'ada@example.com')
                && ! str_contains((string) $encoded, '08031234567');
        })
        ->atLeast()->once();
});

test('a refusal is acknowledged with the provider\'s own code and a scrubbed sentence', function () {
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => Http::response([
            'account_id' => 'org_pq57',
            'balances' => [['currency' => 'NGN', 'available_balance' => '500000.00', 'pending_balance' => '0.00']],
        ]),
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        // Bachs' refusal prose is the shape that quotes the account back at us,
        // which is exactly why it cannot go to the log as it arrived.
        'sandbox-api.bachs.io/v1/payouts' => Http::response([
            'code' => 'ACCOUNT_NAME_MISMATCH',
            'message' => 'The account name for 0123456789 does not match. Contact ada@example.com or 08031234567.',
        ], 422),
    ]);

    pq57Credited($business, 100000, $store);

    Log::spy();

    try {
        pq57Run((int) $business->id);
    } catch (PayoutNotSentException) {
        // The refusal is the job's own retry, which is not what this test is about.
    }

    // The provider answered and refused, so this is the fast path — an incident,
    // not a wait.
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'custody.payout.acknowledged'
            && ($context['outcome'] ?? null) === 'refused'
            && ($context['failure_code'] ?? null) === 'ACCOUNT_NAME_MISMATCH'
            && ! str_contains((string) $context['message'], '0123456789')
            && ! str_contains((string) $context['message'], 'ada@example.com')
            && ! str_contains((string) $context['message'], '08031234567'))
        ->atLeast()->once();

    // The scrubbing is the log's, not the record's. The row keeps the provider's
    // real words, because an operator acting on a refusal needs them.
    $payout = Payout::query()->where('business_id', $business->id)->latest('id')->first();

    expect($payout->failure_reason)->toContain('0123456789')
        ->and($payout->failure_code)->toBe('ACCOUNT_NAME_MISMATCH');
});

test('a provider that accepts nothing and says nothing is still acknowledged', function () {
    // Bachs answering an empty body and Bachs not answering at all are different
    // facts about the same request — "it took it and told us nothing" versus "we
    // never heard" — and the acknowledgement line has to be able to say which.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => Http::response([
            'account_id' => 'org_pq57',
            'balances' => [['currency' => 'NGN', 'available_balance' => '500000.00', 'pending_balance' => '0.00']],
        ]),
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response([], 200),
    ]);

    pq57Credited($business, 100000, $store);

    Log::spy();

    pq57Run((int) $business->id);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'custody.payout.acknowledged'
            && ($context['outcome'] ?? null) === 'accepted'
            && ($context['transfer_id'] ?? null) === null
            && array_key_exists('ack', $context)
            && $context['ack'] === null)
        ->atLeast()->once();
});

test('a payout that never reaches the provider says so, and why', function () {
    // The line that was missing when this was reported. No request appears in the
    // provider's dashboard, and the log's own record of the sale — matured: 0,
    // outcome: [] — is equally consistent with three unrelated causes. The cause is
    // named on the sale's own line now, in the same words the alert uses, so the
    // first thing anybody reads answers the question they came with.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider(138215);

    $other = Business::create([
        'user_id' => createBusinessOwner()[0]->id,
        'name' => 'Overstated PQ57 Trader',
        'status' => 'active',
    ]);

    $other->forceFill(['available_balance' => 1143418])->save();

    $sale = pq57Credited($business, 110250, $store);

    Log::spy();

    // Entered the way production enters it — through the sale, which is what the
    // webhook, the storefront return leg and the till all dispatch — rather than
    // through `settle()` directly. The line this asserts on belongs to that path.
    app(InstantPayoutService::class)->afterCollection($sale);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'custody.payout.instant'
            && ($context['matured'] ?? null) === 0
            && ($context['outcome'] ?? null) === []
            && ($context['nothing_sent'] ?? null) === 'the provider holds NGN 1,382.15 but the platform '
                .'owes NGN 11,434.18 across all businesses, NGN 10,052.03 short'
            && ($context['shortfall_kobo'] ?? null) === 1005203)
        ->atLeast()->once();

    // And no request was made. The absence is the assertion: before this change the
    // only way to establish it was to go and look in the provider's dashboard.
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/v1/payouts'));
});

test('a settlement lag says nothing was sent for the ordinary reason', function () {
    // The other side of the same line, and the one that must not be described as a
    // shortfall: the provider is readable, owes nothing to anybody, and has simply
    // not released this charge yet. It fixes itself, and the log should read like
    // it.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider(0);

    $sale = pq57Credited($business, 100000, $store);

    Log::spy();

    app(InstantPayoutService::class)->afterCollection($sale);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'custody.payout.instant'
            && ($context['nothing_sent'] ?? null) === 'the provider is not holding a released balance for these sales yet'
            && ($context['shortfall_kobo'] ?? null) === 0)
        ->atLeast()->once();
});

test('a send that goes through says nothing about nothing being sent', function () {
    // The null that keeps a grep honest: `nothing_sent` is present on every sale
    // line, so only a value distinguishes a stall from a payout that worked.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider();

    $sale = pq57Credited($business, 100000, $store);

    Log::spy();

    app(InstantPayoutService::class)->afterCollection($sale);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'custody.payout.instant'
            && array_key_exists('nothing_sent', $context)
            && $context['nothing_sent'] === null
            && ($context['outcome']['sent'] ?? null) === 1)
        ->atLeast()->once();
});

test('a payout that empties the wallet is not reported as a sale-less balance', function () {
    // The job's post-settlement wallet read used to run on the `Business` it
    // loaded at the top of `handle()`, while `afterCollection()` settles through
    // `$transaction->business` — a second instance. The stale copy still held the
    // pre-payout available balance, so the fresh FIFO walk found every sale the
    // payout had just claimed, attributed nothing to that cap, and logged
    // `custody.payout.allocation_short` for the whole amount that had in fact
    // just been paid. A fully paid-out wallet is the ordinary case, not a gap.
    pq57Platform();
    pq57Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = pq57Business();

    pq57Provider();

    // Money already payable, and a newer sale still pending. Together they are
    // exactly what the payout will send, and the stale read saw only the first:
    // the gap it logged was the part that had already left.
    pq57Credited($business, 257400, $store);

    app(CustodyService::class)->promoteForBusiness($business->fresh());

    $sale = pq57Credited($business, 48510, $store);

    $business->refresh();

    expect($business->available_balance)->toBe(257400)
        ->and($business->pending_balance)->toBe(48510);

    Log::spy();

    // Through the sale, with its id, because that is the path the webhook, the
    // return leg and the till dispatch — and the only one where the job and the
    // settlement hold different model instances.
    (new SettlePayouts((int) $business->id, (int) $sale->id))->handle(
        app(InstantPayoutService::class),
        app(WalletSummaryService::class),
        app(PayoutSweepService::class),
    );

    $payout = Payout::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

    expect($payout->amount_kobo)->toBe(305910)
        ->and($business->fresh()->available_balance)->toBe(0)
        ->and($business->fresh()->pending_balance)->toBe(0);

    Log::shouldNotHaveReceived('warning', fn (string $message): bool => $message === 'custody.payout.allocation_short');

    // And no retry: nothing is left behind, so the job is finished with this
    // business rather than treating a completed payout as a stall.
    Log::shouldNotHaveReceived('warning', fn (string $message): bool => $message === 'custody.payout.retry');
});
