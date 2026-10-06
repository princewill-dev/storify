<?php

use App\Models\Payment;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Log;

function ws07Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws07Plan(array $attributes = []): SubscriptionPlan
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

function ws07Subscribe(User $user, SubscriptionPlan $plan, array $attributes = []): Subscription
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

test('the plans endpoint groups plans and computes the yearly saving', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws07Plan(['name' => 'Starter', 'amount' => 5000, 'interval' => 'monthly', 'sort_order' => 1]);
    ws07Plan(['name' => 'Growth', 'amount' => 10000, 'interval' => 'monthly', 'sort_order' => 2]);
    ws07Plan(['name' => 'Annual', 'amount' => 48000, 'interval' => 'yearly', 'sort_order' => 3, 'is_default' => true]);
    ws07Plan(['name' => 'Legacy', 'amount' => 1000, 'is_active' => false]);
    ws07Plan(['name' => 'Trial plan', 'amount' => 0, 'is_trial' => true]);

    $response = $this->withToken(ws07Token($owner))->getJson('/api/v1/management/subscription/plans');

    $response->assertOk()
        ->assertJsonPath('data.yearly_savings_percent', 20) // 48000 vs 5000×12 = 60000
        ->assertJsonPath('data.trial_enabled', true)
        ->assertJsonPath('data.trial_days', 7)
        ->assertJsonPath('data.has_active_subscription', false)
        ->assertJsonPath('data.monthly.0.name', 'Starter')
        ->assertJsonPath('data.yearly.0.name', 'Annual')
        ->assertJsonPath('data.yearly.0.is_default', true)
        ->assertJsonPath('data.yearly.0.amount_kobo', 4800000)
        ->assertJsonPath('data.yearly.0.interval_label', '/yearly')
        ->assertJsonCount(2, 'data.monthly')
        ->assertJsonCount(1, 'data.yearly')
        ->assertJsonCount(3, 'data.plans');

    expect(array_column($response->json('data.plans'), 'name'))->not->toContain('Legacy')
        ->not->toContain('Trial plan');
});

test('the plans endpoint reports an empty catalogue and trial settings', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    Setting::create(['trial_enabled' => false, 'trial_days' => 14]);

    $this->withToken(ws07Token($owner))
        ->getJson('/api/v1/management/subscription/plans')
        ->assertOk()
        ->assertJsonCount(0, 'data.plans')
        ->assertJsonPath('data.yearly_savings_percent', null)
        ->assertJsonPath('data.trial_enabled', false)
        ->assertJsonPath('data.trial_days', 14);
});

test('the subscription endpoint reports the current plan and trial state', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $plan = ws07Plan();
    ws07Subscribe($owner, $plan);

    $this->withToken(ws07Token($owner))
        ->getJson('/api/v1/management/subscription')
        ->assertOk()
        ->assertJsonPath('data.subscription.plan.name', 'Starter')
        ->assertJsonPath('data.subscription.is_active', true)
        ->assertJsonPath('data.subscription.next_amount', 5000)
        ->assertJsonPath('data.subscription.next_amount_kobo', 500000)
        ->assertJsonPath('data.subscription.billing_cycle', '/monthly')
        ->assertJsonPath('data.trial.active', false)
        ->assertJsonStructure(['data' => ['subscription', 'trial', 'selected_plan', 'billing_history']]);
});

test('the subscription endpoint never exposes another business subscription', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    ws07Subscribe($owner, ws07Plan());

    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws07Token($otherOwner))
        ->getJson('/api/v1/management/subscription')
        ->assertOk()
        ->assertJsonPath('data.subscription', null)
        ->assertJsonPath('data.billing_history', []);
});

test('a trialing owner sees days remaining and trial end date', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addDays(5)]);

    $response = $this->withToken(ws07Token($owner))
        ->getJson('/api/v1/management/subscription')
        ->assertOk()
        ->assertJsonPath('data.subscription', null)
        ->assertJsonPath('data.trial.active', true)
        ->assertJsonPath('data.trial.expired', false)
        ->assertJsonPath('data.trial.ends_at', $owner->fresh()->trial_ends_at->toISOString());

    // Timestamps lose sub-second precision in MySQL, so a whole-day diff can
    // floor to 4 for a "5 days left" trial. Legacy floored the same way.
    expect($response->json('data.trial.days_left'))->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(5);
});

test('selecting a plan starts the platform trial and lands on the dashboard', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $plan = ws07Plan();

    $this->withToken(ws07Token($owner))
        ->postJson('/api/v1/management/subscription/select-plan', ['plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonPath('data.next', 'dashboard')
        ->assertJsonPath('data.trial_started', true)
        ->assertJsonPath('data.selected_plan.name', 'Starter');

    $owner->refresh();

    expect($owner->selected_plan_id)->toBe($plan->id)
        ->and($owner->trial_ends_at)->not->toBeNull()
        ->and($owner->trial_ends_at->isFuture())->toBeTrue();
});

test('re-selecting a plan during a trial does not restart the trial', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $starter = ws07Plan(['name' => 'Starter']);
    $growth = ws07Plan(['name' => 'Growth', 'amount' => 9000]);

    $this->withToken(ws07Token($owner))
        ->postJson('/api/v1/management/subscription/select-plan', ['plan_id' => $starter->id])
        ->assertOk();

    $trialEndsAt = $owner->fresh()->trial_ends_at;

    // Legacy re-ran `now()->addDays()` on every selection, so a trialing user
    // could extend the trial indefinitely by re-selecting a plan.
    $this->travel(2)->days();

    $this->withToken(ws07Token($owner))
        ->postJson('/api/v1/management/subscription/select-plan', ['plan_id' => $growth->id])
        ->assertOk()
        ->assertJsonPath('data.trial_started', false)
        ->assertJsonPath('data.next', 'dashboard');

    expect($owner->fresh()->trial_ends_at->equalTo($trialEndsAt))->toBeTrue()
        ->and($owner->fresh()->selected_plan_id)->toBe($growth->id);
});

test('selecting a plan without a platform trial routes to payment', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    Setting::create(['trial_enabled' => false, 'trial_days' => 7]);
    $plan = ws07Plan();

    $this->withToken(ws07Token($owner))
        ->postJson('/api/v1/management/subscription/select-plan', ['plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonPath('data.next', 'payment')
        ->assertJsonPath('data.trial_started', false);

    $owner->refresh();

    expect($owner->selected_plan_id)->toBe($plan->id)
        ->and($owner->trial_ends_at)->toBeNull();
});

test('an owner who already used a trial is not granted another one', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->subDay()]);
    $plan = ws07Plan();

    $this->withToken(ws07Token($owner))
        ->postJson('/api/v1/management/subscription/select-plan', ['plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonPath('data.next', 'payment')
        ->assertJsonPath('data.trial_started', false);

    expect($owner->fresh()->trial_ends_at->isPast())->toBeTrue();
});

test('selecting a plan is refused while a subscription is active', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    ws07Subscribe($owner, ws07Plan(['name' => 'Current']));
    $other = ws07Plan(['name' => 'Other', 'amount' => 9000]);

    $this->withToken(ws07Token($owner))
        ->postJson('/api/v1/management/subscription/select-plan', ['plan_id' => $other->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You already have an active subscription.');

    expect($owner->fresh()->selected_plan_id)->toBeNull();
});

test('an unverified owner cannot select a plan', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null, 'is_verified' => false]);
    $plan = ws07Plan();

    $this->withToken(ws07Token($owner))
        ->postJson('/api/v1/management/subscription/select-plan', ['plan_id' => $plan->id])
        ->assertStatus(403);

    // Legacy force-set `is_verified = true`, letting an unverified account
    // skip email verification; the plan flow must not.
    expect($owner->fresh()->is_verified)->toBeFalse()
        ->and($owner->fresh()->selected_plan_id)->toBeNull();
});

test('selecting an invalid or inactive plan is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $inactive = ws07Plan(['name' => 'Retired', 'is_active' => false]);

    $this->withToken(ws07Token($owner))
        ->postJson('/api/v1/management/subscription/select-plan', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan_id');

    $this->withToken(ws07Token($owner))
        ->postJson('/api/v1/management/subscription/select-plan', ['plan_id' => $inactive->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Invalid plan selection.');

    expect($owner->fresh()->selected_plan_id)->toBeNull();
});

test('changing a plan swaps it immediately and logs the change', function () {
    Log::spy();

    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $starter = ws07Plan(['name' => 'Starter', 'amount' => 5000]);
    $growth = ws07Plan(['name' => 'Growth', 'amount' => 9000, 'sort_order' => 2]);
    $subscription = ws07Subscribe($owner, $starter);

    $this->withToken(ws07Token($owner))
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $growth->id])
        ->assertOk()
        ->assertJsonPath('data.subscription.plan.name', 'Growth')
        ->assertJsonPath('data.subscription.next_amount', 9000);

    expect($subscription->fresh()->subscription_plan_id)->toBe($growth->id);

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context = []) => $message === 'subscription.plan_changed'
            && ($context['user_id'] ?? null) === $owner->id
            && ($context['old_plan_id'] ?? null) === $starter->id
            && ($context['new_plan_id'] ?? null) === $growth->id);
});

test('changing to the current plan is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $starter = ws07Plan();
    $subscription = ws07Subscribe($owner, $starter);

    $this->withToken(ws07Token($owner))
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $starter->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Invalid plan selection.');

    expect($subscription->fresh()->subscription_plan_id)->toBe($starter->id);
});

test('a business without an active subscription cannot change plans', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $activated = ws07Plan(['name' => 'Activated']);
    ws07Subscribe($owner, $activated);

    // The caller's subscription is resolved from their own business, never
    // from an id in the request — another business's subscription is unreachable.
    $this->withToken(ws07Token($otherOwner))
        ->postJson('/api/v1/management/subscription/change-plan', ['plan_id' => $activated->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'No active subscription found.');

    expect($owner->business->fresh()->activeSubscription()->first()->subscription_plan_id)->toBe($activated->id);
});

test('billing history keeps payments from expired subscriptions', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => null]);
    $starter = ws07Plan(['name' => 'Starter']);

    $expiredSubscription = ws07Subscribe($owner, $starter, [
        'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subYear(),
        'expires_at' => now()->subMonths(2),
    ]);

    Payment::create([
        'business_id' => $owner->business_id,
        'user_id' => $owner->id,
        'subscription_id' => $expiredSubscription->id,
        'amount' => 5000,
        'currency' => 'NGN',
        'status' => Payment::STATUS_SUCCESS,
        'payment_type' => Payment::TYPE_SUBSCRIPTION,
        'metadata' => ['plan_name' => 'Starter'],
        'paid_at' => now()->subYear(),
    ]);

    // Another business's payment must not leak into this history.
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    Payment::create([
        'business_id' => $otherOwner->business_id,
        'user_id' => $otherOwner->id,
        'subscription_id' => ws07Subscribe($otherOwner, $starter)->id,
        'amount' => 99000,
        'currency' => 'NGN',
        'status' => Payment::STATUS_SUCCESS,
        'payment_type' => Payment::TYPE_SUBSCRIPTION,
    ]);

    $response = $this->withToken(ws07Token($owner))->getJson('/api/v1/management/subscription');

    $response->assertOk()
        ->assertJsonCount(1, 'data.billing_history')
        ->assertJsonPath('data.billing_history.0.plan_name', 'Starter')
        ->assertJsonPath('data.billing_history.0.status', 'success')
        ->assertJsonPath('data.billing_history.0.amount_kobo', 500000)
        // The active-subscription-only query legacy used would have hidden this.
        ->assertJsonPath('data.subscription', null);
});

test('subscription endpoints are refused without the subscription permission', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    $this->withToken(ws07Token($staff))
        ->getJson('/api/v1/management/subscription/plans')
        ->assertStatus(403);

    $this->withToken(ws07Token($staff))
        ->postJson('/api/v1/management/subscription/select-plan', ['plan_id' => ws07Plan()->id])
        ->assertStatus(403);
});

test('subscription endpoints require authentication', function () {
    $this->getJson('/api/v1/management/subscription/plans')->assertStatus(401);
    $this->postJson('/api/v1/management/subscription/select-plan', [])->assertStatus(401);
});
