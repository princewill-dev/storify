<?php

use App\Models\Coupon;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

test('a full discount coupon activates its plan without a payment', function () {
    [$user, $business] = createBusinessOwner();

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
        'user_id' => $user->id,
        'business_id' => $business->id,
        'name' => 'Pending Store',
        'status' => Store::STATUS_PENDING,
    ]);

    actingAs($user)
        ->postJson(route('management.plans.validate-coupon'), [
            'code' => $coupon->code,
            'plan_id' => $plan->id,
        ])
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('activated', true)
        ->assertJsonPath('redirect_url', route('management.dashboard'));

    assertDatabaseHas('subscriptions', [
        'business_id' => $business->id,
        'subscription_plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    expect($user->fresh()->selected_plan_id)->toBe($plan->id)
        ->and($store->fresh()->status)->toBe(Store::STATUS_ACTIVE)
        ->and($coupon->fresh()->uses_count)->toBe(1)
        ->and($coupon->fresh()->is_active)->toBeFalse();

    actingAs($user)
        ->postJson(route('management.plans.validate-coupon'), [
            'code' => $coupon->code,
            'plan_id' => $plan->id,
        ])
        ->assertOk()
        ->assertJsonPath('valid', false);

    expect(Subscription::where('business_id', $business->id)->count())->toBe(1)
        ->and($coupon->fresh()->uses_count)->toBe(1);
});
