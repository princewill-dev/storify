<?php

use App\Http\Middleware\EnsureManagementSubscription;
use App\Jobs\ProcessTrialExpirations;
use App\Mail\TrialExpiredMail;
use App\Mail\TrialExpiryReminderMail;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionGate;
use App\Services\SubscriptionTrialSettings;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

function ws09Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws09Plan(array $attributes = []): SubscriptionPlan
{
    return SubscriptionPlan::create(array_merge([
        'name' => 'Starter',
        'description' => 'All the essentials to get started.',
        'amount' => 5000,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_trial' => false,
        'features' => ['Sell online', 'POS'],
        'sort_order' => 1,
    ], $attributes));
}

function ws09Subscribe(User $user, SubscriptionPlan $plan, array $attributes = []): Subscription
{
    return Subscription::create(array_merge([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'subscription_plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subMonth(),
        'expires_at' => now()->addMonth(),
    ], $attributes));
}

function ws09Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Main Store',
        'slug' => 'ws09-'.strtolower(str()->random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws09RunTrialJob(): void
{
    (new ProcessTrialExpirations)->handle(app(SubscriptionTrialSettings::class));
}

/**
 * Invoke the gate middleware directly: the whole-fleet build wires it onto the
 * management route group at integration time, so a unit call is the only way
 * to prove the contract without editing a shared route file.
 */
function ws09GateCall(User $user, ?string $routeName): Response
{
    $request = Request::create('/api/v1/management/probe', 'GET');
    $request->setUserResolver(fn () => $user);

    $route = new RoutingRoute(['GET'], '/api/v1/management/probe', []);

    if ($routeName !== null) {
        $route->name($routeName);
    }

    $request->setRouteResolver(fn () => $route);

    return app(EnsureManagementSubscription::class)
        ->handle($request, fn () => response()->json(['passed' => true]));
}

// ---------------------------------------------------------------------------
// Gate state + dashboard banners
// ---------------------------------------------------------------------------

test('a subscribed business sees no banner and an unblocked gate', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $plan = ws09Plan(['name' => 'Growth']);
    ws09Subscribe($owner, $plan);

    $this->withToken(ws09Token($owner))
        ->getJson('/api/v1/management/subscription/status')
        ->assertOk()
        ->assertJsonPath('data.gate.state', 'active')
        ->assertJsonPath('data.gate.blocked', false)
        ->assertJsonPath('data.gate.message', null)
        ->assertJsonPath('data.banner', null)
        ->assertJsonPath('data.subscription.plan_name', 'Growth')
        ->assertJsonPath('data.is_owner', true);
});

test('a healthy trial renders no banner and reports the trial notice settings', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addDays(5)]);
    Setting::create(['trial_enabled' => true, 'trial_days' => 7]);

    $response = $this->withToken(ws09Token($owner))
        ->getJson('/api/v1/management/subscription/status')
        ->assertOk()
        ->assertJsonPath('data.gate.state', 'trial')
        ->assertJsonPath('data.gate.blocked', false)
        ->assertJsonPath('data.banner', null)
        ->assertJsonPath('data.trial.enabled', true)
        ->assertJsonPath('data.trial.days', 7)
        ->assertJsonPath('data.trial.active', true)
        ->assertJsonPath('data.lifecycle.reminder_window_days', 3)
        ->assertJsonPath('data.lifecycle.pause_after_expiry_days', 3);

    expect($response->json('data.trial.days_left'))->toBeGreaterThanOrEqual(3);
});

test('a trial ending within two days renders the blue upgrade banner', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addDays(2)]);

    $this->withToken(ws09Token($owner))
        ->getJson('/api/v1/management/subscription/status')
        ->assertOk()
        ->assertJsonPath('data.gate.state', 'trial_ending')
        ->assertJsonPath('data.banner.variant', 'trial_ending')
        ->assertJsonPath('data.banner.tone', 'blue')
        ->assertJsonPath('data.banner.cta_label', 'Upgrade Now')
        ->assertJsonPath('data.banner.cta_to', '/subscription/payment');

    expect($this->withToken(ws09Token($owner))->getJson('/api/v1/management/subscription/status')->json('data.banner.title'))
        ->toContain('free trial ends');
});

test('an expired trial inside the three-day grace window shows the pause countdown', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->subDay()]);
    ws09Store($owner);

    $this->withToken(ws09Token($owner))
        ->getJson('/api/v1/management/subscription/status')
        ->assertOk()
        ->assertJsonPath('data.gate.state', 'trial_expired')
        ->assertJsonPath('data.gate.blocked', true)
        ->assertJsonPath('data.gate.message', 'Please select a plan to continue.')
        ->assertJsonPath('data.gate.redirect', '/plans')
        ->assertJsonPath('data.banner.variant', 'trial_expired')
        ->assertJsonPath('data.banner.tone', 'red')
        ->assertJsonPath('data.banner.cta_label', 'Subscribe Now')
        ->assertJsonPath('data.lifecycle.store_pause_pending', true)
        ->assertJsonPath('data.stores.active', 1);

    // Legacy claimed the stores were already paused the moment the trial
    // lapsed, three days before the job actually pauses them.
    expect($this->withToken(ws09Token($owner))->getJson('/api/v1/management/subscription/status')->json('data.banner.body'))
        ->toContain('pause on');
});

test('an expired trial past the grace window uses the paused copy', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->subDays(5)]);

    $this->withToken(ws09Token($owner))
        ->getJson('/api/v1/management/subscription/status')
        ->assertOk()
        ->assertJsonPath('data.banner.variant', 'trial_expired')
        ->assertJsonPath('data.banner.body', 'Your stores are paused. Subscribe to reactivate them.')
        ->assertJsonPath('data.lifecycle.store_pause_pending', false);
});

test('a selected but unpaid plan shows the pay-now banner', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $plan = ws09Plan(['name' => 'Growth']);
    $owner->update(['selected_plan_id' => $plan->id]);

    $this->withToken(ws09Token($owner->fresh()))
        ->getJson('/api/v1/management/subscription/status')
        ->assertOk()
        ->assertJsonPath('data.gate.state', 'plan_selected_unpaid')
        ->assertJsonPath('data.banner.variant', 'plan_selected_unpaid')
        ->assertJsonPath('data.banner.tone', 'amber')
        ->assertJsonPath('data.banner.cta_label', 'Pay Now')
        ->assertJsonPath('data.banner.body', 'You chose the Growth plan. Pay now to activate your stores.')
        ->assertJsonPath('data.selected_plan.name', 'Growth');
});

test('an owner with no plan at all sees the choose-plan banner', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);

    $this->withToken(ws09Token($owner))
        ->getJson('/api/v1/management/subscription/status')
        ->assertOk()
        ->assertJsonPath('data.gate.state', 'no_plan')
        ->assertJsonPath('data.banner.variant', 'no_plan')
        ->assertJsonPath('data.banner.cta_label', 'Choose Plan')
        // Legacy: the "Choose Plan" button pointed at the in-dashboard
        // subscription page, not the onboarding grid.
        ->assertJsonPath('data.banner.cta_to', '/subscription')
        ->assertJsonPath('data.stores.total', 0);
});

test('the status payload never exposes another business subscription or stores', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    ws09Store($owner);
    ws09Subscribe($owner, ws09Plan(['name' => 'Starter']));

    [$otherOwner] = createBusinessOwner(['trial_ends_at' => null]);
    ws09Store($otherOwner);
    ws09Store($otherOwner);
    ws09Subscribe($otherOwner, ws09Plan(['name' => 'Enterprise']));

    $this->withToken(ws09Token($owner))
        ->getJson('/api/v1/management/subscription/status')
        ->assertOk()
        ->assertJsonPath('data.subscription.plan_name', 'Starter')
        ->assertJsonPath('data.stores.total', 1);

    $this->withToken(ws09Token($otherOwner))
        ->getJson('/api/v1/management/subscription/status')
        ->assertOk()
        ->assertJsonPath('data.subscription.plan_name', 'Enterprise')
        ->assertJsonPath('data.stores.total', 2);
});

test('the status endpoint requires authentication', function () {
    $this->getJson('/api/v1/management/subscription/status')->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// The gate itself
// ---------------------------------------------------------------------------

test('a gated owner is refused on a non-exempt route with the legacy contract', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);

    $response = ws09GateCall($owner, 'api.management.orders.index');

    expect($response->getStatusCode())->toBe(403)
        ->and($response->json())->toMatchArray([
            'message' => 'Please select a plan to continue.',
            'code' => 'subscription_required',
            'redirect' => '/plans',
        ]);
});

test('staff, trials and subscribed businesses all pass the gate', function () {
    [, $business] = createBusinessOwner(['trial_ends_at' => null]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    [$trialing] = createBusinessOwner(['trial_ends_at' => now()->addDays(3)]);
    [$subscribed] = createBusinessOwner(['trial_ends_at' => null]);
    ws09Subscribe($subscribed, ws09Plan());

    foreach ([$staff, $trialing, $subscribed] as $user) {
        $response = ws09GateCall($user, 'api.management.orders.index');
        expect($response->getStatusCode())->toBe(200)
            ->and($response->json('passed'))->toBeTrue();
    }
});

test('the exempt list keeps the plans and payment family reachable', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);

    $exempt = [
        'api.management.dashboard',
        'api.management.setup',
        'api.management.auth.me',
        'api.management.profile.update',
        'api.management.kyc.show',
        'api.management.plans.index',
        'api.management.plans.validate-coupon',
        // Verify fix: legacy forgot this one, so a gated owner could never
        // remove an applied coupon.
        'api.management.plans.remove-coupon',
        'api.management.subscription.status',
        'api.management.subscription.select-plan',
        'api.management.subscription.payment',
        'api.management.subscription.process-payment',
        'api.management.subscription.callback',
        'api.management.subscription.check-early-pass',
    ];

    foreach ($exempt as $routeName) {
        expect(ws09GateCall($owner, $routeName)->getStatusCode())->toBe(200, "{$routeName} should be exempt");
    }

    // And the holes stay closed for everything else.
    foreach (['api.management.orders.index', 'api.management.products.index', 'api.management.staff.index'] as $routeName) {
        expect(ws09GateCall($owner, $routeName)->getStatusCode())->toBe(403, "{$routeName} should be gated");
    }
});

test('an unverified owner is refused with the verification contract', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null, 'is_verified' => false]);

    $response = ws09GateCall($owner, 'api.management.orders.index');

    expect($response->getStatusCode())->toBe(403)
        ->and($response->json())->toMatchArray([
            'code' => 'verify_email',
            'redirect' => '/verify-otp',
        ]);
});

test('an owner without a business passes so onboarding can continue', function () {
    $owner = User::factory()->create([
        'role' => User::ROLE_BUSINESS_OWNER,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    expect(ws09GateCall($owner, 'api.management.orders.index')->getStatusCode())->toBe(200);
});

test('the gate reports the exempt route in the status payload', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);

    // The status endpoint itself is exempt, but the owner is still blocked
    // from everything outside the exempt list — the SPA reads `blocked` to
    // decide whether to move them.
    $this->withToken(ws09Token($owner))
        ->getJson('/api/v1/management/subscription/status')
        ->assertOk()
        ->assertJsonPath('data.gate.blocked', true)
        ->assertJsonPath('data.gate.current_route_exempt', true)
        ->assertJsonPath('data.gate.code', 'subscription_required');
});

// ---------------------------------------------------------------------------
// Trial lifecycle job
// ---------------------------------------------------------------------------

test('the reminder for a three-day-left trial fires once, not once per run', function () {
    Mail::fake();
    $this->freezeTime();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addDays(3)]);

    ws09RunTrialJob();
    ws09RunTrialJob();

    Mail::assertQueued(TrialExpiryReminderMail::class, 1);
    Mail::assertNotQueued(TrialExpiredMail::class);

    // The dormant marker is used when the user has a subscription row.
    expect(Subscription::query()->where('user_id', $owner->id)->exists())->toBeFalse();
});

test('the reminder marker on a subscription row blocks a re-send', function () {
    Mail::fake();
    $this->freezeTime();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addDays(1)]);
    $subscription = Subscription::create([
        'business_id' => $owner->business_id,
        'user_id' => $owner->id,
        'subscription_plan_id' => ws09Plan()->id,
        'status' => Subscription::STATUS_PENDING,
    ]);

    ws09RunTrialJob();

    expect($subscription->fresh()->trial_reminder_day7_sent_at)->not->toBeNull();
    Mail::assertQueued(TrialExpiryReminderMail::class, 1);

    ws09RunTrialJob();
    Mail::assertQueued(TrialExpiryReminderMail::class, 1);
});

test('a pre-set reminder marker suppresses the send entirely', function () {
    Mail::fake();
    $this->freezeTime();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addDays(1)]);
    Subscription::create([
        'business_id' => $owner->business_id,
        'user_id' => $owner->id,
        'subscription_plan_id' => ws09Plan()->id,
        'status' => Subscription::STATUS_PENDING,
        'trial_reminder_day7_sent_at' => now()->subDay(),
    ]);

    ws09RunTrialJob();

    Mail::assertNothingQueued();
});

test('the day-zero reminder fires once the trial lapses', function () {
    Mail::fake();
    $this->freezeTime();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->subHour()]);
    $store = ws09Store($owner);

    ws09RunTrialJob();
    ws09RunTrialJob();

    Mail::assertQueued(TrialExpiryReminderMail::class, 1);
    Mail::assertNotQueued(TrialExpiredMail::class);

    // The grace window keeps the storefront live.
    expect($store->fresh()->status)->toBe(Store::STATUS_ACTIVE);
});

test('stores pause and the expiry mail goes out once after the grace window', function () {
    Mail::fake();
    $this->freezeTime();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->subDays(3)->subHour()]);
    $active = ws09Store($owner, ['status' => Store::STATUS_ACTIVE]);
    $second = ws09Store($owner, ['status' => Store::STATUS_ACTIVE, 'name' => 'Second Store']);
    $alreadyPending = ws09Store($owner, ['status' => Store::STATUS_PENDING, 'name' => 'Pending Store']);

    ws09RunTrialJob();
    ws09RunTrialJob();

    Mail::assertQueued(TrialExpiredMail::class, 1);
    expect($active->fresh()->status)->toBe(Store::STATUS_PENDING)
        ->and($second->fresh()->status)->toBe(Store::STATUS_PENDING)
        ->and($alreadyPending->fresh()->status)->toBe(Store::STATUS_PENDING);
});

test('pausing at expiry covers stores the trial owner did not create', function () {
    Mail::fake();
    $this->freezeTime();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->subDays(4)]);
    $staffCreated = ws09Store($owner, ['user_id' => User::factory()->create(['business_id' => $owner->business_id])->id]);

    ws09RunTrialJob();

    // Legacy only paused $user->stores(), so this store stayed live forever.
    expect($staffCreated->fresh()->status)->toBe(Store::STATUS_PENDING);
});

test('a business on an active subscription is skipped by the trial job', function () {
    Mail::fake();
    $this->freezeTime();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->subDays(10)]);
    $store = ws09Store($owner);
    ws09Subscribe($owner, ws09Plan());

    ws09RunTrialJob();

    Mail::assertNothingQueued();
    expect($store->fresh()->status)->toBe(Store::STATUS_ACTIVE);
});

test('the trial job is a no-op when the platform trial is disabled', function () {
    Mail::fake();
    $this->freezeTime();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->subDays(10)]);
    $store = ws09Store($owner);
    Setting::create(['trial_enabled' => false, 'trial_days' => 7]);

    ws09RunTrialJob();

    Mail::assertNothingQueued();
    expect($store->fresh()->status)->toBe(Store::STATUS_ACTIVE);
});

test('the exempt list is exposed for the SPA and middleware to agree on', function () {
    $gate = app(SubscriptionGate::class);

    expect($gate->isExempt('api.management.plans.remove-coupon'))->toBeTrue()
        ->and($gate->isExempt('api.management.subscription.process-payment'))->toBeTrue()
        ->and($gate->isExempt('api.management.orders.index'))->toBeFalse()
        ->and($gate->isExempt(null))->toBeFalse();
});
