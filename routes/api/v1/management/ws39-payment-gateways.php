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

    // Reaches out to the provider's API with the business's own keys, so it
    // earns a rate limit.
    Route::post('{provider}/test', [PaymentGatewayController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('test');
});
