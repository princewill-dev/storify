<?php

use App\Models\EarlyPass;
use App\Models\EarlyPassUsage;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Early passes — the single-use boundary, ported from the legacy web test
| (tests/Feature/Management/EarlyPassTest.php)
|--------------------------------------------------------------------------
|
| The legacy test's redemption path — 200 + success, one active subscription
| on the default plan, one usage row, the pending store activated, and the
| second attempt refused without consuming anything — is already covered by
| ws32couponsearlyaccessTest.php ("an early pass activates a one-year
| subscription and consumes one use" and "an early pass cannot be used twice
| by the same user").
|
| The one assertion left behind is the max_uses=1 boundary: a pass that is
| exactly exhausted by the redemption flips to is_active=false. The existing
| coverage only proves a pass with headroom (max_uses=2) stays active, and
| pre-exhausts its max_uses=1 "GONE" pass by hand instead of through the
| endpoint.
|
| Contract note: legacy answered the spent-pass retry with 200 + success=false;
| the API treats an exhausted code as the standard 422 error envelope. That is
| the deliberate API contract, so this port asserts the API's answer.
|
*/

function ws32PassToken(User $owner): string
{
    return $owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

test('a single-use early pass activates once, deactivates itself and refuses the second attempt', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $plan = SubscriptionPlan::create([
        'name' => 'Early Access',
        'amount' => 1000.00,
        'currency' => 'NGN',
        'interval' => 'yearly',
        'is_active' => true,
        'is_default' => true,
        'is_trial' => false,
        'sort_order' => 1,
    ]);

    $pass = EarlyPass::create(['code' => 'EARLY-ONE', 'max_uses' => 1, 'is_active' => true]);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Early Store',
        'slug' => 'ws32-early-'.uniqid(),
        'status' => Store::STATUS_PENDING,
    ]);

    $this->withToken(ws32PassToken($owner))
        ->postJson('/api/v1/management/subscription/check-early-pass', ['code' => $pass->code])
        ->assertOk()
        ->assertJsonPath('data.success', true)
        ->assertJsonPath('data.activated', true)
        ->assertJsonPath('data.subscription.plan_name', 'Early Access');

    expect(Subscription::where('business_id', $business->id)->where('subscription_plan_id', $plan->id)->count())->toBe(1)
        ->and(EarlyPassUsage::where('early_pass_id', $pass->id)->count())->toBe(1)
        ->and($pass->fresh()->is_active)->toBeFalse()
        ->and($store->fresh()->status)->toBe(Store::STATUS_ACTIVE);

    // The single use is spent, so the retry is refused. The pass itself is now
    // unavailable, which the controller reports before the per-user check.
    $this->withToken(ws32PassToken($owner))
        ->postJson('/api/v1/management/subscription/check-early-pass', ['code' => $pass->code])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This code is not valid or has reached its usage limit.');

    expect(Subscription::where('business_id', $business->id)->count())->toBe(1)
        ->and(EarlyPassUsage::where('early_pass_id', $pass->id)->count())->toBe(1)
        ->and($pass->fresh()->is_active)->toBeFalse();
});
