<?php

use App\Http\Controllers\Api\V1\Admin\EarlyPassController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionPlanController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-11 — Subscriptions, plans & early access (WS11)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth/audience/team group, so
| these inherit the "admin" prefix, the "api.admin." name and the middleware.
| Only Route:: lines belong in this file.
|
| Permissions follow the roadmap mapping: `admin.subscriptions` gates the
| subscription oversight list and the plan CRUD; early-access passes stay on
| `admin.businesses` (the permission legacy used for Access Codes).
|
| AdminApiActivityLogger rides both groups so reads and writes are audited
| even before WS1's group-wide wiring lands; it de-dupes per request, so a
| later group-level application still writes exactly one row.
|
| Bindings: `{plan}` is SubscriptionPlan's `plan_code`; `{earlyPass}` is
| EarlyPass's `code` (uppercase), matching the legacy `/office/early-access/{CODE}`.
*/

Route::middleware(['permission:admin.subscriptions', AdminApiActivityLogger::class])->group(function () {
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');

    Route::get('subscription-plans', [SubscriptionPlanController::class, 'index'])->name('subscription-plans.index');
    Route::post('subscription-plans', [SubscriptionPlanController::class, 'store'])->name('subscription-plans.store');
    Route::put('subscription-plans/{plan}', [SubscriptionPlanController::class, 'update'])->name('subscription-plans.update');
    Route::delete('subscription-plans/{plan}', [SubscriptionPlanController::class, 'destroy'])->name('subscription-plans.destroy');
});

Route::middleware(['permission:admin.businesses', AdminApiActivityLogger::class])->group(function () {
    Route::get('early-access', [EarlyPassController::class, 'index'])->name('early-access.index');
    Route::post('early-access', [EarlyPassController::class, 'store'])->name('early-access.store');
    Route::get('early-access/{earlyPass}', [EarlyPassController::class, 'show'])->name('early-access.show');
    Route::put('early-access/{earlyPass}', [EarlyPassController::class, 'update'])->name('early-access.update');
    Route::post('early-access/{earlyPass}/toggle-status', [EarlyPassController::class, 'toggleStatus'])->name('early-access.toggle-status');
    Route::delete('early-access/{earlyPass}', [EarlyPassController::class, 'destroy'])->name('early-access.destroy');
});
