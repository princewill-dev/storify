<?php

use App\Http\Controllers\Api\V1\Management\Subscription\CouponController;
use App\Http\Controllers\Api\V1\Management\Subscription\EarlyPassController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WS-32 — Coupons & Early-Access Redemption
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix and "api.management." name.
|
| All three routes sit on the plans/subscription family, which WS-09's
| SubscriptionGate keeps exempt: they are how a business without an active
| subscription gets one. The legacy `plans.remove-coupon` gate hole (a gated
| owner could never clear an applied coupon) is deliberately not reproduced.
|
| The applied coupon survives as SPA state — every checkout call carries
| `coupon_code`, which WS-08's endpoints already accept.
*/
Route::middleware('permission:settings subscription')->group(function () {
    Route::post('plans/validate-coupon', [CouponController::class, 'validateCoupon'])
        ->name('plans.validate-coupon');
    Route::post('plans/remove-coupon', [CouponController::class, 'removeCoupon'])
        ->name('plans.remove-coupon');

    Route::post('subscription/check-early-pass', [EarlyPassController::class, 'apply'])
        ->name('subscription.check-early-pass');
});
