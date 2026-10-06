<?php

use App\Http\Controllers\Api\V1\Management\Subscription\PlanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WS-07 — Subscription plans & trial onboarding
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix, the "api.management." name and
| the auth middleware. Only Route:: lines belong in this file: paths and
| names are written out in full rather than wrapped in a nested group.
|
| WS-09 (the subscription gate) must keep these four routes on its exempt
| list: they are how a business without an active subscription gets one.
*/
Route::middleware('permission:settings subscription')->group(function () {
    Route::get('subscription/plans', [PlanController::class, 'plans'])->name('subscription.plans');

    Route::get('subscription', [PlanController::class, 'show'])->name('subscription.show');

    Route::post('subscription/select-plan', [PlanController::class, 'select'])
        ->name('subscription.select-plan');

    Route::post('subscription/change-plan', [PlanController::class, 'change'])
        ->name('subscription.change-plan');
});
