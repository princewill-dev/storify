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

    // Where the business says which of its bank accounts its money is paid into.
    // A `put` because it names the destination for this scope — re-submitting
    // replaces it.
    //
    // The path still carries a provider for the client's sake, and the verb is
    // still per-provider on the screen, but the destination itself is not: the
    // wallet and the payout are the business's, so this settles on one column
    // whichever card the owner was looking at. See
    // PaymentGatewayService::setPayoutBank().
    //
    // No rate limit any more. It used to earn one because it created a real,
    // permanent account at the provider; what it writes now is one local column,
    // and a business locked out of naming its bank by a throttle would be a
    // worse failure than the loop this would have prevented.
    Route::put('{provider}/payout-account', [PaymentGatewayController::class, 'payoutBank'])
        ->name('payout-account');

    // Stop or resume taking payments through Storify. A `put` for the same
    // reason as the payout bank above: it sets this provider's activation to the
    // value given, and is therefore repeatable.
    //
    // No rate limit. It reaches the provider for nothing, and a business locked
    // out of stopping its own payments by a throttle would be a worse failure
    // than the loop this would have prevented.
    Route::put('{provider}/activation', [PaymentGatewayController::class, 'activation'])
        ->name('activation');

    // Reaches out to the provider's API with the business's own keys, so it
    // earns a rate limit.
    Route::post('{provider}/test', [PaymentGatewayController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('test');
});
