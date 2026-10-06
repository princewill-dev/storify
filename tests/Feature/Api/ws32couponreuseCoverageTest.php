<?php

use App\Models\Coupon;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Coupons — reuse of a spent full-discount coupon, ported from the legacy web
| tests (tests/Feature/Management/SubscriptionCouponTest.php)
|--------------------------------------------------------------------------
|
| The legacy test's main path — a plan-scoped 100% coupon with max_uses=1
| activating the subscription with no payment (valid + activated, active
| subscription on the plan, selected_plan_id set, pending store activated,
| uses_count=1, is_active=false) — is already covered by
| ws32couponsearlyaccessTest.php ("a fully covering coupon activates the
| subscription with no payment") and, on the checkout path, by
| ws08paystackbillingTest.php ("a fully covering coupon activates the
| subscription without touching the gateway").
|
| The gap left behind is the legacy test's closing assertion: the same owner's
| second attempt with their now-spent coupon is refused and the refused attempt
| consumes nothing — no extra use recorded, no second subscription.
|
| Contract note: legacy answered that retry with 200 + valid=false; the API
| treats an exhausted code as the standard 422 error envelope. That is the
| deliberate API contract, so this port asserts the API's answer.
|
*/

function ws32ReuseToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

test('a spent coupon refuses the second attempt without consuming a use or a second subscription', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $plan = SubscriptionPlan::create([
        'name' => 'Growth Monthly',
        'amount' => 25000,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'is_active' => true,
        'is_trial' => false,
        'sort_order' => 1,
    ]);

    $coupon = Coupon::create([
        'code' => 'FULLACCESS',
        'name' => 'Full Access',
        'subscription_plan_id' => $plan->id,
        'discount_type' => 'percentage',
        'discount_value' => 100,
        'max_uses' => 1,
        'is_active' => true,
    ]);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Pending Store',
        'slug' => 'ws32-reuse-'.uniqid(),
        'status' => Store::STATUS_PENDING,
    ]);

    $this->withToken(ws32ReuseToken($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'FULLACCESS', 'plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.activated', true);

    expect($coupon->fresh()->uses_count)->toBe(1)
        ->and($coupon->fresh()->is_active)->toBeFalse();

    // The retry is refused — the code is spent.
    $this->withToken(ws32ReuseToken($owner))
        ->postJson('/api/v1/management/plans/validate-coupon', ['code' => 'FULLACCESS', 'plan_id' => $plan->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Invalid or expired coupon code.');

    // The refused attempt consumes nothing: one use, one subscription, and the
    // state written by the first activation is untouched.
    expect(Subscription::where('business_id', $business->id)->count())->toBe(1)
        ->and($coupon->fresh()->uses_count)->toBe(1)
        ->and($coupon->fresh()->is_active)->toBeFalse()
        ->and($owner->fresh()->selected_plan_id)->toBe($plan->id)
        ->and($store->fresh()->status)->toBe(Store::STATUS_ACTIVE);
});
