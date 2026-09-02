<?php

use App\Models\EarlyPass;
use App\Models\EarlyPassUsage;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;

use function Pest\Laravel\actingAs;

test('an early pass activates a business once and consumes one use', function () {
    [$user, $business] = createBusinessOwner();
    $plan = SubscriptionPlan::create([
        'name' => 'Early Access',
        'amount' => 1000,
        'currency' => 'NGN',
        'interval' => 'yearly',
        'is_active' => true,
        'is_default' => true,
        'is_trial' => false,
        'sort_order' => 1,
    ]);
    $pass = EarlyPass::create(['code' => 'EARLY-ONE', 'max_uses' => 1, 'is_active' => true]);
    $store = Store::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'name' => 'Early Store',
        'status' => Store::STATUS_PENDING,
    ]);

    actingAs($user)
        ->postJson(route('management.subscription.check-early-pass'), ['code' => $pass->code])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Subscription::where('business_id', $business->id)->where('subscription_plan_id', $plan->id)->count())->toBe(1)
        ->and(EarlyPassUsage::where('early_pass_id', $pass->id)->count())->toBe(1)
        ->and($pass->fresh()->is_active)->toBeFalse()
        ->and($store->fresh()->status)->toBe(Store::STATUS_ACTIVE);

    actingAs($user)
        ->postJson(route('management.subscription.check-early-pass'), ['code' => $pass->code])
        ->assertOk()
        ->assertJsonPath('success', false);

    expect(Subscription::where('business_id', $business->id)->count())->toBe(1)
        ->and(EarlyPassUsage::where('early_pass_id', $pass->id)->count())->toBe(1);
});
