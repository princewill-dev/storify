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
use App\Services\Payments\Custody\WalletSummaryService;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
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
        'sandbox-api.bachs.io/v1/payouts' => $payoutStatus === 200
            ? Http::response(['id' => 'pay_1', 'status' => 'pending'])
            : Http::response(['message' => 'Transfer declined'], $payoutStatus),
    ]);
}

/** The job, run the way a worker would run it. */
function pq57Run(int $businessId): void
{
    (new SettlePayouts($businessId))->handle(
        app(InstantPayoutService::class),
        app(WalletSummaryService::class),
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
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
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
