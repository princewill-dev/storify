<?php

use App\Http\Controllers\Payment\PaymentWebhookController;
use App\Http\Controllers\Payment\PaystackWebhookController;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks and apex redirects
|--------------------------------------------------------------------------
| The marketing site, storefront and admin console are all standalone SPAs
| now, so this file carries only what has to live on the API host.
*/

// Paystack posts here. Must stay on the web stack: it is CSRF-exempted in
// bootstrap/app.php and verified by signature rather than by session.
// One endpoint for every provider. The provider is a path segment so adding
// one is a driver and a registry entry, nothing here.
Route::post('/webhooks/payments/{provider}', [PaymentWebhookController::class, 'handle'])
    ->whereIn('provider', PaymentGatewayRegistry::keys())
    ->name('webhooks.payments');

// The URL a business is given to paste into its provider's dashboard, which is
// the only one that names whose connection is being notified about. `{scope}` is
// a store's `st_…` code or a business's `…_BIZ_…` code, resolved in the
// controller rather than by route binding — see the controller for why.
//
// The provider-only route above stays exactly as it is and keeps working: it is
// already registered in live provider dashboards, and a business that pasted it
// must not be broken by this.
Route::post('/webhooks/payments/{provider}/{scope}', [PaymentWebhookController::class, 'handle'])
    ->whereIn('provider', PaymentGatewayRegistry::keys())
    ->where('scope', '[A-Za-z0-9_]+')
    ->name('webhooks.payments.scope');

// Kept because this exact URL is registered in Paystack's dashboard; changing
// it would silently stop settlement until someone updated it there.
Route::post('/webhooks/paystack', [PaystackWebhookController::class, 'handle'])->name('webhooks.paystack');

// Send www traffic to the apex so links and cookies share one origin.
Route::domain('www.'.config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST)))->group(function () {
    Route::get('{any}', function () {
        $mainDomain = config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST));
        $scheme = request()->secure() ? 'https' : 'http';
        $path = request()->path() !== '/' ? '/'.request()->path() : '';

        return redirect("{$scheme}://{$mainDomain}{$path}", 301);
    })->where('any', '.*');
});
