<?php

use App\Mail\StoreActivated;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Money\Naira;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
| WS-53 — a plan change that costs money.
|
| `PlanSelectionService::change()` used to be one `UPDATE`: any business could
| move to the dearest plan mid-period for nothing, and the time it had already
| paid for was discarded without a word. These tests pin the four outcomes that
| replace it, and the three places the money could go wrong:
|
|   * the **proration** — the difference for what is left of the term, clamped at
|     zero so a rounding sliver never becomes a one-kobo gateway charge;
|   * `expires_at` **must not move** when the change settles, because the money
|     bought the remainder of a period that was already paid for;
|   * an **abandoned upgrade must not be paid for twice** — a payment settles the
|     plan it was quoted against, not whatever is pending when it clears.
*/

function ws53Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws53Plan(array $attributes = []): SubscriptionPlan
{
    return SubscriptionPlan::create(array_merge([
        'name' => 'Starter',
        'amount' => 5000,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_trial' => false,
        'tier' => 1,
        'sort_order' => 1,
    ], $attributes));
}

function ws53Subscribe(User $user, SubscriptionPlan $plan, array $attributes = []): Subscription
{
    return Subscription::create(array_merge([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'subscription_plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subDays(15),
        'expires_at' => now()->addDays(15),
    ], $attributes));
}

function ws53Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'WS53 Store',
        'slug' => 'ws53-'.Str::random(8),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

/**
 * The three Paystack calls the money path makes: initialize, the double verify
 * and the transaction list the second half of it reads.
 *
 * The list matters — the driver confirms a charge through two endpoints and
 * refuses when they disagree, so a fake that only answers `verify` produces a
 * verification failure rather than a settlement.
 */
function ws53FakeGateway(string $reference, int $amountKobo = 500000, string $status = 'success'): void
{
    // Http::fake() merges stub callbacks and the earliest match wins, so a
    // second call would be shadowed by the first. Swap in a fresh factory.
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($reference, $amountKobo, $status) {
        $url = $request->url();

        if (str_contains($url, 'transaction/initialize')) {
            return Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/ws53',
                    'access_code' => 'ws53_access',
                    // Echoing the request's own reference keeps the fake honest:
                    // the money path records a provider's name for a payment only
                    // when the provider really did rename it.
                    'reference' => $request->data()['reference'] ?? $reference,
                ],
            ]);
        }

        $transaction = [
            'id' => 553311,
            'status' => $status,
            'amount' => $amountKobo,
            'currency' => 'NGN',
            'reference' => $reference,
        ];

        if (str_contains($url, 'transaction/verify')) {
            return Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => $transaction,
            ]);
        }

        return Http::response([
            'status' => true,
            'data' => [$transaction],
        ]);
    });
}

test('a downgrade is booked for the period end and nothing is charged', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $enterprise = ws53Plan(['name' => 'Enterprise', 'amount' => 20000, 'tier' => 3]);
    $entry = ws53Plan(['name' => 'Entry', 'amount' => 5000, 'tier' => 1, 'sort_order' => 2]);
    $subscription = ws53Subscribe($owner, $enterprise);
    // Read back through the database: the stored precision is what the payload
    // will quote, and comparing against the in-memory model would be asserting
    // on a value the response never sees.
    $expiresAt = $subscription->fresh()->expires_at;

    $this->withToken(ws53Token($owner))
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $entry->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.subscription.plan.name', 'Enterprise')
        ->assertJsonPath('data.subscription.scheduled_plan.name', 'Entry')
        ->assertJsonPath('data.subscription.scheduled_effective_at', $expiresAt->toISOString());

    $fresh = $subscription->fresh();

    expect($fresh->subscription_plan_id)->toBe($enterprise->id)
        ->and($fresh->scheduled_plan_id)->toBe($entry->id)
        ->and($fresh->pending_plan_id)->toBeNull()
        ->and($fresh->pending_charge_kobo)->toBeNull()
        ->and($fresh->expires_at->equalTo($expiresAt))->toBeTrue();
});

test('a plan granting what the current one already grants is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    // Same tier and the same price: a different row, not a different plan.
    $starter = ws53Plan(['name' => 'Starter', 'amount' => 5000, 'tier' => 1]);
    $starterAnnual = ws53Plan(['name' => 'Starter Annual', 'amount' => 5000, 'tier' => 1, 'sort_order' => 2]);
    $subscription = ws53Subscribe($owner, $starter);

    $this->withToken(ws53Token($owner))
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $starterAnnual->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The Starter Annual plan includes the same as your current plan. There is nothing to change.');

    $fresh = $subscription->fresh();

    expect($fresh->subscription_plan_id)->toBe($starter->id)
        ->and($fresh->pending_plan_id)->toBeNull()
        ->and($fresh->scheduled_plan_id)->toBeNull();
});

test('a higher tier at the same price is applied at once with nothing to pay', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $entry = ws53Plan(['name' => 'Entry', 'amount' => 5000, 'tier' => 1]);
    $growth = ws53Plan(['name' => 'Growth', 'amount' => 5000, 'tier' => 2, 'sort_order' => 2]);
    $subscription = ws53Subscribe($owner, $entry);

    $this->withToken(ws53Token($owner))
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $growth->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'applied')
        ->assertJsonPath('data.subscription.plan.name', 'Growth');

    expect($subscription->fresh()->subscription_plan_id)->toBe($growth->id)
        ->and($subscription->fresh()->pending_plan_id)->toBeNull();
});

test('a difference that rounds away is applied rather than charged a kobo', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $entry = ws53Plan(['name' => 'Entry', 'amount' => 5000, 'tier' => 1]);
    $entryPlus = ws53Plan(['name' => 'Entry Plus', 'amount' => 5000.01, 'tier' => 1, 'sort_order' => 2]);
    // Half a day left, so both daily rates round to the same kobo figure.
    $subscription = ws53Subscribe($owner, $entry, ['expires_at' => now()->addHours(12)]);

    $this->withToken(ws53Token($owner))
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $entryPlus->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'applied');

    expect($subscription->fresh()->subscription_plan_id)->toBe($entryPlus->id)
        ->and($subscription->fresh()->pending_charge_kobo)->toBeNull();
});

test('the recorded charge is what the checkout quotes, not the plan price', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $starter = ws53Plan(['name' => 'Starter', 'amount' => 5000, 'tier' => 1]);
    // ₦20,000 a month, 15 days left: ₦10,000 of time at the new rate against
    // ₦2,500 at the old, so ₦7,500 is owed.
    $growth = ws53Plan(['name' => 'Growth', 'amount' => 20000, 'tier' => 2, 'sort_order' => 2]);
    ws53Subscribe($owner, $starter);

    $token = ws53Token($owner);

    $this->withToken($token)
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $growth->id])
        ->assertOk()
        ->assertJsonPath('data.charge_kobo', 750000);

    $this->withToken($token)
        ->getJson('/api/v1/management/subscription/payment?payment_type=plan_change')
        ->assertOk()
        ->assertJsonPath('data.plan.name', 'Growth')
        ->assertJsonPath('data.base_amount_kobo', 750000)
        ->assertJsonPath('data.total_kobo', 750000);
});

test('a plan change checkout is refused without a pending change', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $plan = ws53Plan();
    ws53Subscribe($owner, $plan);

    $this->withToken(ws53Token($owner))
        ->getJson('/api/v1/management/subscription/payment?payment_type=plan_change')
        ->assertStatus(422)
        ->assertJsonPath('message', 'There is no plan change waiting for payment.');
});

test('a plan change checkout is refused without an active subscription', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addDays(3)]);

    $this->withToken(ws53Token($owner))
        ->getJson('/api/v1/management/subscription/payment?payment_type=plan_change')
        ->assertStatus(422)
        ->assertJsonPath('message', 'A plan change needs an active subscription.');
});

test('the prorated charge is taken to the gateway and settles without moving the renewal date', function () {
    Mail::fake();
    $this->seed(PaymentMethodSeeder::class);

    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $starter = ws53Plan(['name' => 'Starter', 'amount' => 5000, 'tier' => 1]);
    $growth = ws53Plan(['name' => 'Growth', 'amount' => 9000, 'tier' => 2, 'sort_order' => 2]);
    $subscription = ws53Subscribe($owner, $starter);
    $expiresAt = $subscription->fresh()->expires_at;
    // Suspended on purpose by moderation. Paying for an upgrade must not quietly
    // undo that — it is a plan change, not the start of service.
    $store = ws53Store($owner, ['status' => Store::STATUS_SUSPENDED]);

    $token = ws53Token($owner);

    $this->withToken($token)
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $growth->id])
        ->assertOk()
        ->assertJsonPath('data.charge_kobo', 200000);

    ws53FakeGateway('ref_ws53_change');

    $this->withToken($token)
        ->postJson('/api/v1/management/subscription/process-payment', [
            'idempotency_key' => 'ws53-key-1',
            'payment_type' => 'plan_change',
        ])
        ->assertOk()
        ->assertJsonPath('data.total_kobo', 200000);

    $payment = Payment::latest('id')->first();

    expect($payment->payment_type)->toBe(Payment::TYPE_PLAN_CHANGE)
        // Filed against the term already running, not against a fresh one.
        ->and($payment->subscription_id)->toBe($subscription->id)
        ->and((string) $payment->amount)->toBe('2000.00');

    // The gateway is asked for the difference, not for the plan's price.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'transaction/initialize')
        && $request['amount'] === 200000);

    ws53FakeGateway($payment->reference, 200000);

    $this->withToken($token)
        ->getJson('/api/v1/management/subscription/callback?reference='.$payment->reference)
        ->assertOk();

    $fresh = $subscription->fresh();

    expect($fresh->subscription_plan_id)->toBe($growth->id)
        ->and($fresh->pending_plan_id)->toBeNull()
        ->and($fresh->pending_charge_kobo)->toBeNull()
        ->and($fresh->pending_requested_at)->toBeNull()
        // The money bought the rest of a period that was already paid for.
        ->and($fresh->expires_at->equalTo($expiresAt))->toBeTrue()
        ->and($store->fresh()->status)->toBe(Store::STATUS_SUSPENDED);

    expect(Subscription::where('business_id', $owner->business_id)->count())->toBe(1);

    // A plan change is not a store activation, and the admin mail says a store
    // was created — so neither may be sent.
    Mail::assertNotQueued(StoreActivated::class);
});

test('a payment settles the plan it was quoted against, not the one pending now', function () {
    Mail::fake();
    $this->seed(PaymentMethodSeeder::class);

    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $starter = ws53Plan(['name' => 'Starter', 'amount' => 5000, 'tier' => 1]);
    $growth = ws53Plan(['name' => 'Growth', 'amount' => 20000, 'tier' => 2, 'sort_order' => 2]);
    $scale = ws53Plan(['name' => 'Scale', 'amount' => 40000, 'tier' => 3, 'sort_order' => 3]);
    $subscription = ws53Subscribe($owner, $starter);

    $token = ws53Token($owner);

    // Quote and start paying for Growth...
    $this->withToken($token)
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $growth->id])
        ->assertOk();

    ws53FakeGateway('ref_ws53_superseded');

    $this->withToken($token)
        ->postJson('/api/v1/management/subscription/process-payment', [
            'idempotency_key' => 'ws53-key-2',
            'payment_type' => 'plan_change',
        ])
        ->assertOk();

    $abandoned = Payment::latest('id')->first();

    // ...then change your mind and ask for Scale instead, which overwrites the
    // pending plan while the first payment is still in flight.
    $this->withToken($token)
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $scale->id])
        ->assertOk();

    expect($subscription->fresh()->pending_plan_id)->toBe($scale->id);

    // ₦20,000 a month against ₦5,000 over 15 days: ₦7,500, and the gateway is
    // told exactly that.
    expect((string) $abandoned->amount)->toBe('7500.00');

    ws53FakeGateway($abandoned->reference, Naira::koboFromStrict($abandoned->amount));

    $this->withToken($token)
        ->getJson('/api/v1/management/subscription/callback?reference='.$abandoned->reference)
        ->assertOk();

    // The money bought Growth. Handing over Scale because it happens to be
    // pending would give away a plan nobody paid for.
    expect($subscription->fresh()->subscription_plan_id)->toBe($growth->id)
        ->and($subscription->fresh()->pending_plan_id)->toBeNull();
});

test('a booked downgrade takes effect once the paid period ends', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $enterprise = ws53Plan(['name' => 'Enterprise', 'amount' => 20000, 'tier' => 3]);
    $entry = ws53Plan(['name' => 'Entry', 'amount' => 5000, 'tier' => 1, 'sort_order' => 2]);
    // The period has just run out; the booking was made while it was still live.
    $subscription = ws53Subscribe($owner, $enterprise, ['expires_at' => now()->subMinute()]);

    $subscription->update(['scheduled_plan_id' => $entry->id, 'scheduled_at' => now()->subDay()]);
    $expiresAt = $subscription->fresh()->expires_at;

    $this->artisan('subscriptions:apply-scheduled')->assertExitCode(0);

    $fresh = $subscription->fresh();

    expect($fresh->subscription_plan_id)->toBe($entry->id)
        ->and($fresh->scheduled_plan_id)->toBeNull()
        ->and($fresh->scheduled_at)->toBeNull()
        // The plan a business is on does not decide when its paid period ends.
        ->and($fresh->expires_at->equalTo($expiresAt))->toBeTrue();

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'subscription_plan_downgrade_applied',
        'subject_id' => $subscription->id,
    ]);

    // Idempotent: a second nightly run finds nothing booked.
    $this->artisan('subscriptions:apply-scheduled')->expectsOutput('Applied 0 scheduled plan change(s).');
});

test('a booking is left alone until the period it was booked in has ended', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $enterprise = ws53Plan(['name' => 'Enterprise', 'amount' => 20000, 'tier' => 3]);
    $entry = ws53Plan(['name' => 'Entry', 'amount' => 5000, 'tier' => 1, 'sort_order' => 2]);
    $subscription = ws53Subscribe($owner, $enterprise, ['expires_at' => now()->addDays(10)]);

    $subscription->update(['scheduled_plan_id' => $entry->id, 'scheduled_at' => now()]);

    $this->artisan('subscriptions:apply-scheduled')->expectsOutput('Applied 0 scheduled plan change(s).');

    expect($subscription->fresh()->subscription_plan_id)->toBe($enterprise->id)
        ->and($subscription->fresh()->scheduled_plan_id)->toBe($entry->id);
});

test('a coupon covering the whole difference is refused rather than half honoured', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $starter = ws53Plan(['name' => 'Starter', 'amount' => 5000, 'tier' => 1]);
    $growth = ws53Plan(['name' => 'Growth', 'amount' => 9000, 'tier' => 2, 'sort_order' => 2]);
    $subscription = ws53Subscribe($owner, $starter);

    Coupon::create([
        'code' => 'WHOLE',
        'discount_type' => 'percentage',
        'discount_value' => 100,
        'is_active' => true,
    ]);

    $token = ws53Token($owner);

    $this->withToken($token)
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $growth->id])
        ->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/management/subscription/process-payment', [
            'idempotency_key' => 'ws53-key-3',
            'payment_type' => 'plan_change',
            'coupon_code' => 'WHOLE',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This coupon covers the whole amount, which a plan change cannot use. Choose a plan without it.');

    expect($subscription->fresh()->subscription_plan_id)->toBe($starter->id)
        ->and(Payment::where('business_id', $owner->business_id)->count())->toBe(0);
});

/* ------------------------------------------------ moving between cadences */

test('moving to the annual plan charges the year less the month still running, and starts a twelve-month term', function () {
    // The regression guard for the defect that seeding annual rows exposed.
    // `sameRank()` needs equal tier **and** equal amount, so the annual twin of
    // the business's own plan never matches it; the move then fell through to
    // the upgrade path, where it was priced as
    // `value(annual, daysLeft) - value(monthly, daysLeft)`. An annual plan's
    // daily rate is *lower* than its monthly twin's — that is the discount — so
    // that difference is negative every time, clamps to zero, and the customer
    // was moved onto the year for nothing and kept the fifteen days they had.
    // They bought a year and were given a fortnight.
    Mail::fake();
    $this->seed(PaymentMethodSeeder::class);

    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $monthly = ws53Plan(['name' => 'Entry', 'amount' => 5000, 'tier' => 1]);
    $annual = ws53Plan([
        'name' => 'Entry',
        'amount' => 48000,
        'interval' => 'yearly',
        'tier' => 1,
        'sort_order' => 4,
    ]);

    // Fifteen days in, fifteen left — `ws53Subscribe`'s own window.
    $subscription = ws53Subscribe($owner, $monthly);

    // A year of Entry costs ₦48,000 and the unused fortnight is worth ₦2,500, so
    // the year costs ₦45,500 to buy today.
    $token = ws53Token($owner);

    $this->withToken($token)
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $annual->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'payment_required')
        ->assertJsonPath('data.charge_kobo', 4550000);

    ws53FakeGateway('ref_ws53_annual');

    $this->withToken($token)
        ->postJson('/api/v1/management/subscription/process-payment', [
            'idempotency_key' => 'ws53-key-annual',
            'payment_type' => 'plan_change',
        ])
        ->assertOk();

    $payment = Payment::latest('id')->first();

    expect($payment->payment_type)->toBe(Payment::TYPE_PLAN_CHANGE)
        ->and((string) $payment->amount)->toBe('45500.00')
        // Recorded at the quote, because settlement re-derives nothing: the plan
        // that lands is the plan that was quoted against, and the flag that says
        // a fresh term was bought has to travel with it.
        ->and(data_get($payment->metadata, 'resets_period'))->toBeTrue();

    ws53FakeGateway($payment->reference, 4550000);

    $this->withToken($token)
        ->getJson('/api/v1/management/subscription/callback?reference='.$payment->reference)
        ->assertOk();

    $fresh = $subscription->fresh();

    expect($fresh->subscription_plan_id)->toBe($annual->id)
        ->and($fresh->pending_plan_id)->toBeNull()
        ->and($fresh->pending_charge_kobo)->toBeNull()
        // The half the bug got wrong. A tier upgrade leaves the period alone
        // because the money bought more of it; a cadence change buys a new one
        // and must move it, or the customer is charged for a year and served a
        // month.
        ->and((int) $fresh->starts_at->diffInDays($fresh->expires_at))->toBe(365)
        ->and($fresh->expires_at->greaterThan(now()->addMonths(11)))->toBeTrue();

    // And a plan change is still not a store activation.
    Mail::assertNotQueued(StoreActivated::class);
});

test('shortening the commitment is still booked for the period end rather than applied', function () {
    // The other direction, and it must not change: a business eleven months into
    // a year that drops to monthly takes effect when the year it paid for runs
    // out. Applying it now would take back time somebody bought.
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $monthly = ws53Plan(['name' => 'Entry', 'amount' => 5000, 'tier' => 1]);
    $annual = ws53Plan([
        'name' => 'Entry',
        'amount' => 48000,
        'interval' => 'yearly',
        'tier' => 1,
        'sort_order' => 4,
    ]);

    $subscription = ws53Subscribe($owner, $annual, ['expires_at' => now()->addDays(300)]);
    $expiresAt = $subscription->fresh()->expires_at;

    $this->withToken(ws53Token($owner))
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $monthly->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'scheduled');

    expect($subscription->fresh()->subscription_plan_id)->toBe($annual->id)
        ->and($subscription->fresh()->scheduled_plan_id)->toBe($monthly->id)
        ->and($subscription->fresh()->expires_at->equalTo($expiresAt))->toBeTrue()
        ->and(Payment::where('business_id', $owner->business_id)->count())->toBe(0);
});

test('a commitment extended against a term worth more than it costs is applied free, and the term still restarts', function () {
    // The arithmetic clamps at zero — a remainder valued at more than the new
    // period costs is not a refund this platform makes — so there is no figure
    // to send anybody to a gateway for and the change lands immediately. The
    // free path must reset the period exactly like the paid one: it is the same
    // purchase, and it is the reset the old code dropped.
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $monthly = ws53Plan(['name' => 'Entry', 'amount' => 5000, 'tier' => 1]);
    $annual = ws53Plan([
        'name' => 'Entry',
        'amount' => 48000,
        'interval' => 'yearly',
        'tier' => 1,
        'sort_order' => 4,
    ]);

    // A long term on a monthly plan — a promotional period, and the state a
    // switch made under the old code leaves behind. 350 days of ₦5,000 a month
    // is worth more than a year of Entry costs.
    $subscription = ws53Subscribe($owner, $monthly, [
        'starts_at' => now()->subDays(20),
        'expires_at' => now()->addDays(350),
    ]);

    $this->withToken(ws53Token($owner))
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $annual->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'applied');

    $fresh = $subscription->fresh();

    expect($fresh->subscription_plan_id)->toBe($annual->id)
        ->and($fresh->pending_charge_kobo)->toBeNull()
        // A fresh year from today, not the remainder of the promotional one.
        ->and((int) $fresh->starts_at->diffInDays($fresh->expires_at))->toBe(365)
        ->and((int) $fresh->starts_at->diffInDays(now()))->toBe(0)
        ->and(Payment::where('business_id', $owner->business_id)->count())->toBe(0);
});
