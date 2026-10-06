<?php

use App\Http\Controllers\Api\V1\Management\Subscription\PaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WS-08 — Paystack Billing & Activation
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix, the "api.management." name and
| the auth middleware. Only Route:: lines belong in this file: paths and
| names are written out in full rather than wrapped in a nested group.
|
| The money path: checkout summary, idempotent initialization, the verified
| callback that activates the subscription, and billing history.
|
| WS-09's subscription gate keeps every "subscription." route exempt — these
| are how a business without an active subscription gets one.
*/
Route::middleware('permission:settings subscription')->group(function () {
    Route::get('subscription/payment', [PaymentController::class, 'show'])->name('subscription.payment');

    Route::post('subscription/process-payment', [PaymentController::class, 'initialize'])
        ->name('subscription.process-payment');

    Route::get('subscription/callback', [PaymentController::class, 'callback'])->name('subscription.callback');

    Route::get('subscription/payments', [PaymentController::class, 'payments'])->name('subscription.payments');
});
