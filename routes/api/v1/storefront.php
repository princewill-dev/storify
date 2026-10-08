<?php

use App\Http\Controllers\Api\V1\Storefront\AccountController;
use App\Http\Controllers\Api\V1\Storefront\CartController;
use App\Http\Controllers\Api\V1\Storefront\CatalogController;
use App\Http\Controllers\Api\V1\Storefront\CheckoutController;
use App\Http\Controllers\Api\V1\Storefront\DownloadController;
use App\Http\Controllers\Api\V1\Storefront\SupportController;
use App\Http\Controllers\Api\V1\Storefront\TrackingController;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront API (customer-facing, per store)
|--------------------------------------------------------------------------
| Public browsing + guest carts (X-Guest-Token header). Customer endpoints
| use the dedicated sanctum_customer guard.
*/

// Customer account (registered first so `account` is not treated as a store slug)
Route::middleware(['auth:sanctum_customer', 'token.audience:customer'])
    ->prefix('storefront/account')
    ->name('api.storefront.account.')
    ->group(function () {
        Route::get('orders', [AccountController::class, 'orders'])->name('orders.index');
        Route::get('orders/{orderNumber}', [AccountController::class, 'showOrder'])->name('orders.show');
        Route::get('downloads', [AccountController::class, 'downloads'])->name('downloads.index');
        Route::put('profile', [AccountController::class, 'updateProfile'])->name('profile.update');
    });

// Public storefront
Route::prefix('storefront/{store}')->name('api.storefront.')->group(function () {
    Route::get('home', [CatalogController::class, 'home'])->name('home');

    // Plugin tags this storefront should run. Public because its two callers
    // have no session: the storefront SPA, and the Cloudflare Worker that
    // rewrites the raw HTML head so verification meta tags are visible to
    // crawlers that do not run JavaScript. Cached at the edge per store.
    Route::get('tracking', [TrackingController::class, 'show'])->name('tracking');
    Route::get('products', [CatalogController::class, 'products'])->name('products.index');
    Route::get('products/{slugOrCode}', [CatalogController::class, 'show'])->name('products.show');
    Route::get('services', [CatalogController::class, 'services'])->name('services.index');
    Route::get('services/{slugOrCode}', [CatalogController::class, 'serviceShow'])->name('services.show');
    Route::get('categories', [CatalogController::class, 'categories'])->name('categories.index');
    Route::get('delivery-routes', [CatalogController::class, 'deliveryRoutes'])->name('delivery-routes');
    Route::get('payment-methods', [CheckoutController::class, 'paymentMethods'])->name('payment-methods');
    Route::post('support', [SupportController::class, 'store'])->middleware('throttle:3,10')->name('support.store');

    Route::get('cart', [CartController::class, 'show'])->name('cart.show');
    Route::post('cart/items', [CartController::class, 'add'])->name('cart.add');
    Route::patch('cart/items/{item}', [CartController::class, 'updateItem'])->name('cart.items.update');
    Route::delete('cart/items/{item}', [CartController::class, 'removeItem'])->name('cart.items.destroy');
    Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');

    Route::post('checkout', [CheckoutController::class, 'place'])->name('checkout.place');
    // Generic provider endpoints. The provider is a path segment rather than
    // one route per gateway, so adding a provider is a driver and a registry
    // entry and nothing here.
    //
    // {provider} is constrained to known registry keys at the route, so an
    // unknown one 404s before reaching a controller rather than being passed
    // into a lookup.
    Route::post('payments/{provider}/initialize', [CheckoutController::class, 'initializePayment'])
        ->whereIn('provider', PaymentGatewayRegistry::keys())
        ->middleware('throttle:20,1')
        ->name('payments.initialize');

    Route::post('payments/{provider}/verify', [CheckoutController::class, 'verifyPayment'])
        ->whereIn('provider', PaymentGatewayRegistry::keys())
        ->name('payments.verify');

    // Kept so the already-deployed storefront keeps working through the
    // rollout — the same handlers with the provider fixed.
    Route::post('payments/paystack/initialize', [CheckoutController::class, 'paystackInitialize'])->name('payments.paystack.initialize');
    Route::post('payments/paystack/verify', [CheckoutController::class, 'paystackVerify'])->name('payments.paystack.verify');
    Route::post('payments/bank-transfer', [CheckoutController::class, 'bankTransfer'])->name('payments.bank-transfer');
    Route::get('orders/{orderNumber}', [CheckoutController::class, 'orderShow'])->name('orders.show');

    // Tokenised digital downloads. The token in the emailed link IS the
    // credential, so these stay public and are scoped to the issuing store.
    Route::get('downloads/{token}', [DownloadController::class, 'show'])->name('downloads.show');
    Route::get('downloads/{token}/files/{file}', [DownloadController::class, 'file'])->name('downloads.file');
});
