<?php

use App\Http\Controllers\Api\V1\Management\PaymentGatewayController;
use Illuminate\Support\Facades\Route;

// WS-39 — Payment gateways.
// Loaded by routes/api/v1/management.php inside its management group, so the
// prefix, name prefix and auth/audience/team middleware are already applied.
//
// `store_id` is an optional parameter on every verb rather than a path segment:
// absent configures the business-wide default, present configures one store's
// override. See PaymentGatewayController::resolveStore().

Route::middleware('permission:settings payment')->prefix('payment-gateways')->name('payment-gateways.')->group(function () {
    Route::get('/', [PaymentGatewayController::class, 'index'])->name('index');
    Route::put('{provider}', [PaymentGatewayController::class, 'update'])->name('update');
    Route::delete('{provider}', [PaymentGatewayController::class, 'destroy'])->name('destroy');

    // Where a business with no gateway of its own says where it should be paid.
    // A `put` because it is the payout account for this provider and this scope,
    // not a collection — re-submitting replaces it.
    //
    // Rate-limited with `test`: this one creates a real account at the provider
    // and one is never deleted, so a loop here is a loop of real, permanent
    // accounts.
    Route::put('{provider}/payout-account', [PaymentGatewayController::class, 'registerPayoutAccount'])
        ->middleware('throttle:10,1')
        ->name('payout-account');

    // Stop or resume taking payments through Storify, without losing the payout
    // account. A `put` for the same reason as the payout account: it sets this
    // provider's activation to the value given, and is therefore repeatable.
    //
    // No rate limit. Unlike the two above it reaches the provider for nothing,
    // and a business locked out of stopping its own payments by a throttle would
    // be a worse failure than the loop this would have prevented.
    Route::put('{provider}/activation', [PaymentGatewayController::class, 'activation'])
        ->name('activation');

    // Reaches out to the provider's API with the business's own keys, so it
    // earns a rate limit.
    Route::post('{provider}/test', [PaymentGatewayController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('test');
});
