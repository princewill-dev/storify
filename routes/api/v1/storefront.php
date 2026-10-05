<?php

use App\Http\Controllers\Api\V1\Storefront\AccountController;
use App\Http\Controllers\Api\V1\Storefront\CartController;
use App\Http\Controllers\Api\V1\Storefront\CatalogController;
use App\Http\Controllers\Api\V1\Storefront\CheckoutController;
use App\Http\Controllers\Api\V1\Storefront\SupportController;
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
    Route::post('payments/paystack/initialize', [CheckoutController::class, 'paystackInitialize'])->name('payments.paystack.initialize');
    Route::post('payments/paystack/verify', [CheckoutController::class, 'paystackVerify'])->name('payments.paystack.verify');
    Route::post('payments/bank-transfer', [CheckoutController::class, 'bankTransfer'])->name('payments.bank-transfer');
    Route::get('orders/{orderNumber}', [CheckoutController::class, 'orderShow'])->name('orders.show');
});
