<?php

use App\Mail\CouponExhaustedMail;
use App\Mail\StoreActivated;
use App\Models\Coupon;
use App\Models\EarlyPass;
use App\Models\EarlyPassUsage;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

function ws32Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws32Plan(array $attributes = []): SubscriptionPlan
{
    return SubscriptionPlan::create(array_merge([
        'name' => 'Growth Monthly',
        'description' => 'Grow your business.',
        'amount' => 5000.00,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_default' => false,
        'is_trial' => false,
        'features' => ['Online store', 'POS'],
    ], $attributes));
}

/**
 * Coupons do not list `business_id` in $fillable (the platform's coupons have
 * none), so a business-scoped coupon is written with forceFill.
 */
function ws32Coupon(array $attributes = []): Coupon
{
    $coupon = new Coupon;

    $coupon->forceFill(array_merge([
        'code' => 'SAVE20',
        'name' => 'Save 20',
        'subscription_plan_id' => null,
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'max_uses' => null,
        'uses_count' => 0,
        'is_active' => true,
        'expires_at' => null,
    ], $attributes))->save();

    return $coupon;
}

function ws32Store(User $owner, string $status = Store::STATUS_PENDING): Store
{
    return Store::create([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'WS32 Store',
        'slug' => 'ws32-'.uniqid(),
        'status' => $status,
    ]);
}

function ws32Pass(array $attributes = []): EarlyPass
{
    return EarlyPass::create(array_merge([
        'code' => 'EARLY-2026',
        'description' => 'Early access',
        'max_uses' => null,
        'is_active' => true,
    ], $attributes));
}

// ---------------------------------------------------------------------------
// Coupon validation
// ---------------------------------------------------------------------------

test('a valid coupon is accepted and priced against the intended plan', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $plan = ws32Plan(['amount' => 5000.00]);
    ws32Coupon(['code' => 'SAVE20']);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'save20', 'plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.activated', false)
        ->assertJsonPath('data.code', 'SAVE20')
        ->assertJsonPath('data.discount_type', 'percentage')
        ->assertJsonPath('data.base_amount_kobo', 500000)
        ->assertJsonPath('data.discount_kobo', 100000)
        ->assertJsonPath('data.total_kobo', 400000)
        ->assertJsonPath('data.plan_name', null);
});

test('a fixed coupon is capped at the plan amount', function () {
    Mail::fake();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $plan = ws32Plan(['amount' => 3000.00]);
    ws32Coupon(['code' => 'BIGOFF', 'discount_type' => 'fixed', 'discount_value' => 10000]);

    // ₦10,000 off a ₦3,000 plan is a full cover, so it activates rather than
    // returning a negative total.
    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'BIGOFF', 'plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.activated', true);
});

test('unknown, inactive and expired codes produce the legacy message', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws32Coupon(['code' => 'SWITCHEDOFF', 'is_active' => false]);
    ws32Coupon(['code' => 'STALE', 'expires_at' => now()->subDay()]);

    foreach (['NOPE', 'SWITCHEDOFF', 'STALE'] as $code) {
        $this->withToken(ws32Token($owner))
            ->postJson('/api/v1/management/plans/validate-coupon', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid or expired coupon code.');
    }
});

test('a coupon scoped to another plan is rejected with the plan name', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $planA = ws32Plan(['name' => 'Starter Monthly']);
    $planB = ws32Plan(['name' => 'Business Yearly', 'interval' => 'yearly', 'amount' => 60000]);
    ws32Coupon(['code' => 'STARTERONLY', 'subscription_plan_id' => $planA->id, 'discount_value' => 20]);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'STARTERONLY', 'plan_id' => $planB->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This coupon only applies to Starter Monthly.');

    // The code is untouched: the same coupon still applies to its own plan.
    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'STARTERONLY', 'plan_id' => $planA->id])
        ->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.plan_name', 'Starter Monthly');
});

test('a code from another business is not redeemable', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws32Coupon(['code' => 'THEIRS', 'business_id' => $otherBusiness->id]);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'THEIRS'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Invalid or expired coupon code.');
});

test('a fully covering coupon activates the subscription with no payment', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $plan = ws32Plan(['amount' => 25000.00]);
    $coupon = ws32Coupon(['code' => 'FULLACCESS', 'subscription_plan_id' => $plan->id, 'discount_value' => 100, 'max_uses' => 1]);
    $store = ws32Store($owner);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'FULLACCESS', 'plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.activated', true)
        ->assertJsonPath('data.redirect', '/')
        ->assertJsonPath('data.subscription.plan_name', 'Growth Monthly');

    $subscription = Subscription::where('business_id', $business->id)->first();

    expect($subscription?->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription?->subscription_plan_id)->toBe($plan->id)
        ->and($subscription?->metadata['coupon_code'] ?? null)->toBe('FULLACCESS')
        ->and($owner->fresh()->selected_plan_id)->toBe($plan->id)
        ->and($owner->fresh()->trial_ends_at)->toBeNull()
        ->and($store->fresh()->status)->toBe(Store::STATUS_ACTIVE)
        ->and($coupon->fresh()->uses_count)->toBe(1)
        ->and($coupon->fresh()->is_active)->toBeFalse();

    Mail::assertQueued(CouponExhaustedMail::class);
    Mail::assertQueued(StoreActivated::class);
});

test('a generic coupon that fully covers the chosen plan also activates', function () {
    // Legacy's plans-page branch only fired for a plan-scoped coupon and only
    // the checkout path covered the generic case. Both paths activate here.
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $plan = ws32Plan(['amount' => 5000.00]);
    ws32Coupon(['code' => 'FREEMONTH', 'discount_type' => 'fixed', 'discount_value' => 5000, 'max_uses' => 3]);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'FREEMONTH', 'plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonPath('data.activated', true)
        ->assertJsonPath('data.coupon_exhausted', false);

    expect(Subscription::where('business_id', $business->id)->count())->toBe(1);

    Mail::assertNotQueued(CouponExhaustedMail::class);
});

test('a redeemed coupon cannot be redeemed twice and an active subscription blocks another activation', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $plan = ws32Plan(['amount' => 5000.00]);
    ws32Coupon(['code' => 'ONCEONLY', 'subscription_plan_id' => $plan->id, 'discount_value' => 100, 'max_uses' => 5]);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'ONCEONLY', 'plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonPath('data.activated', true);

    // The coupon is still valid (max 5), but the business already subscribed.
    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'ONCEONLY', 'plan_id' => $plan->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You already have an active subscription.');

    expect(Subscription::where('business_id', $business->id)->count())->toBe(1);
});

test('an exhausted coupon is deactivated and refuses further use', function () {
    Mail::fake();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $plan = ws32Plan(['amount' => 5000.00]);
    $coupon = ws32Coupon(['code' => 'LASTONE', 'subscription_plan_id' => $plan->id, 'discount_value' => 100, 'max_uses' => 1]);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'LASTONE', 'plan_id' => $plan->id])
        ->assertOk();

    expect($coupon->fresh()->is_active)->toBeFalse();

    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws32Token($otherOwner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'LASTONE', 'plan_id' => $plan->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Invalid or expired coupon code.');
});

test('a coupon code is required and must be a string', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'OK', 'plan_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan_id');
});

test('removing a coupon is acknowledged even for a business without a subscription', function () {
    // Legacy gated plans.remove-coupon, so a blocked owner could never clear
    // the applied chip. This route must stay reachable.
    [$owner] = createBusinessOwner();

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/remove-coupon')
        ->assertOk()
        ->assertJsonPath('data.removed', true);
});

test('the coupon endpoints refuse staff without the billing permission', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    $this->withToken(ws32Token($staff))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'SAVE20'])
        ->assertStatus(403);

    $this->withToken(ws32Token($staff))
        ->postJson('/api/v1/management/subscription/check-early-pass', ['code' => 'EARLY-2026'])
        ->assertStatus(403);
});

test('activation never touches another business', function () {
    Mail::fake();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $otherStore = ws32Store($otherOwner);
    $plan = ws32Plan(['amount' => 5000.00]);
    ws32Coupon(['code' => 'MINEONLY', 'subscription_plan_id' => $plan->id, 'discount_value' => 100]);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'MINEONLY', 'plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonPath('data.activated', true);

    expect(Subscription::where('business_id', $otherBusiness->id)->count())->toBe(0)
        ->and($otherStore->fresh()->status)->toBe(Store::STATUS_PENDING)
        ->and($otherOwner->fresh()->selected_plan_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Early-access passes
// ---------------------------------------------------------------------------

test('an early pass activates a one-year subscription and consumes one use', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $plan = ws32Plan(['name' => 'Early Access', 'interval' => 'yearly', 'amount' => 1000.00, 'is_default' => true]);
    $pass = ws32Pass(['code' => 'EARLY-ONE', 'max_uses' => 2]);
    $store = ws32Store($owner);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/subscription/check-early-pass', ['code' => 'EARLY-ONE'])
        ->assertOk()
        ->assertJsonPath('data.success', true)
        ->assertJsonPath('data.activated', true)
        ->assertJsonPath('data.redirect', '/')
        ->assertJsonPath('data.subscription.plan_name', 'Early Access');

    $subscription = Subscription::where('business_id', $business->id)->first();

    expect($subscription?->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription?->metadata['early_pass_code'] ?? null)->toBe('EARLY-ONE')
        ->and($subscription?->expires_at->isAfter(now()->addMonths(11)))->toBeTrue()
        ->and(EarlyPassUsage::where('early_pass_id', $pass->id)->count())->toBe(1)
        ->and($store->fresh()->status)->toBe(Store::STATUS_ACTIVE)
        ->and($pass->fresh()->is_active)->toBeTrue();

    Mail::assertQueued(StoreActivated::class);
});

test('an early pass cannot be used twice by the same user', function () {
    Mail::fake();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws32Plan(['interval' => 'yearly', 'amount' => 1000.00, 'is_default' => true]);
    $pass = ws32Pass(['code' => 'EARLY-TWICE', 'max_uses' => 5]);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/subscription/check-early-pass', ['code' => 'EARLY-TWICE'])
        ->assertOk();

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/subscription/check-early-pass', ['code' => 'EARLY-TWICE'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You have already used this code.');

    expect(Subscription::count())->toBe(1)
        ->and(EarlyPassUsage::where('early_pass_id', $pass->id)->count())->toBe(1);
});

test('an unknown code and a missing default plan produce the legacy messages', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/subscription/check-early-pass', ['code' => 'NOPE'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Invalid code. Please check and try again.');

    // The pass exists but there is no active default plan to grant.
    ws32Pass(['code' => 'NO-PLAN']);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/subscription/check-early-pass', ['code' => 'NO-PLAN'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'No subscription plan available.');
});

test('an exhausted pass and an active subscription are both refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws32Plan(['interval' => 'yearly', 'amount' => 1000.00, 'is_default' => true]);

    $used = ws32Pass(['code' => 'GONE', 'max_uses' => 1]);
    EarlyPassUsage::create([
        'early_pass_id' => $used->id,
        'user_id' => User::factory()->create(['business_id' => $otherBusiness->id])->id,
        'used_at' => now(),
    ]);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/subscription/check-early-pass', ['code' => 'GONE'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This code is not valid or has reached its usage limit.');

    $openPass = ws32Pass(['code' => 'STACK', 'max_uses' => 10]);
    Subscription::create([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'subscription_plan_id' => SubscriptionPlan::query()->first()->id,
        'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now(),
        'expires_at' => now()->addMonth(),
    ]);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/subscription/check-early-pass', ['code' => 'STACK'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You already have an active subscription.');
});

test('an early pass activates only the redeeming business stores', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws32Plan(['interval' => 'yearly', 'amount' => 1000.00, 'is_default' => true]);
    ws32Pass(['code' => 'SHARED-PASS', 'max_uses' => 5]);

    $mine = ws32Store($owner);
    $theirs = ws32Store($otherOwner);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/subscription/check-early-pass', ['code' => 'SHARED-PASS'])
        ->assertOk();

    expect($mine->fresh()->status)->toBe(Store::STATUS_ACTIVE)
        ->and($theirs->fresh()->status)->toBe(Store::STATUS_PENDING)
        ->and(Subscription::where('business_id', $otherBusiness->id)->count())->toBe(0)
        ->and(Subscription::where('business_id', $business->id)->count())->toBe(1);
});

test('the early-pass code is required and the endpoints need a token', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws32Token($owner))
        ->postJson('/api/v1/management/subscription/check-early-pass', ['code' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    $this->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'SAVE20'])
        ->assertStatus(401);

    $this->postJson('/api/v1/management/subscription/check-early-pass', ['code' => 'EARLY-2026'])
        ->assertStatus(401);
});
