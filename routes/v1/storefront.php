<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Home\ProductController;
use App\Http\Controllers\Home\SupportController;
use App\Http\Controllers\Cart\CartController;
use App\Http\Controllers\Checkout\CheckoutController;
use App\Http\Controllers\Storefront\StoreSupportController;
use App\Http\Controllers\Storefront\StoreOrderController;
use App\Http\Controllers\Storefront\StoreCategoryController;
use App\Http\Controllers\Storefront\StoreProductController;
use App\Http\Controllers\Storefront\StoreServiceController;
use App\Http\Controllers\Home\SearchController;
use App\Http\Controllers\Shop4me\Shop4meController;
use App\Http\Controllers\Storefront\InvoicePaymentController;

// Main domain routes
Route::domain(config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST)))->group(function () {
    
    // Cart Actions


    // Short code redirect to canonical (subdomain-based)
    Route::get('/p/{code}', function ($code) {
        $product = \App\Models\Product::with('store')->where('product_code', $code)->firstOrFail();
        $storeSlug = optional($product->store)->slug ?? 'store';
        
        $scheme = request()->secure() ? 'https' : 'http';
        $baseDomain = config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST));
        $url = "{$scheme}://{$storeSlug}.{$baseDomain}/products/{$product->slug}-{$product->product_code}";
        
        return redirect($url, 301);
    })->where('code', 'prd_[A-Za-z0-9]{8}');
});

// Local dev bypass
if (config('app.env') === 'local') {
    Route::prefix('{store_subdomain}')
        ->where(['store_subdomain' => '(?!api|admin|vendor|storage|livewire|cart|checkout|products|services|search|support|international-supply)[A-Za-z0-9_\-]+'])
        ->group(function () {
            
            // Store homepage (products listing)
            Route::get('/', [ProductController::class, 'indexByStore'])->name('local.store.products.index');
            
            // Live search
            Route::get('/search', [SearchController::class, 'liveSearch'])->name('local.store.search');

            // Support page routes
            Route::get('/support', [SupportController::class, 'index'])->name('local.support.index');
            Route::post('/support', [SupportController::class, 'store'])->name('local.support.store');
            
            // Product details
            Route::get('/products/{slug}-{code}', [ProductController::class, 'show'])
                ->where([
                    'code' => 'prd_[A-Za-z0-9]{8}',
                    'slug' => '.+',
                ])->name('local.products.show');
                
            // Service details
            Route::get('/services/{slug}-{code}', [ProductController::class, 'showService'])
                ->where([
                    'code' => 'svc_[A-Za-z0-9]{8}',
                    'slug' => '.+',
                ])->name('local.services.show');
            
            // Cart page
            Route::get('/cart', [CartController::class, 'cart'])->name('local.store.cart');

            // Cart Actions
            Route::post('/cart/checkout', [CartController::class, 'proceedToCheckout'])->name('local.store.cart.proceed');

            // Checkout
            Route::get('/checkout/{token}', [CheckoutController::class, 'index'])->name('local.checkout.index');
            Route::post('/checkout', [CheckoutController::class, 'process'])->name('local.checkout.process');
            Route::get('/checkout/payment/{order}', [CheckoutController::class, 'payment'])->name('local.checkout.payment');
            Route::get('/checkout/{order}/payment-methods', [CheckoutController::class, 'showPaymentMethods'])->name('local.checkout.payment-methods');
            Route::post('/checkout/{order}/payment-methods', [CheckoutController::class, 'selectPaymentMethod'])->name('local.checkout.payment-methods.select');
            Route::post('/checkout/{order}/confirm-payment', [CheckoutController::class, 'confirmPayment'])->name('local.checkout.confirm-payment');
            Route::get('/checkout/{order}/bank-transfer', [\App\Http\Controllers\Payment\BankTransferController::class, 'show'])->name('local.payment.bank-transfer');
            Route::post('/checkout/{order}/bank-transfer/confirm', [\App\Http\Controllers\Payment\BankTransferController::class, 'confirmPayment'])->name('local.payment.bank-transfer.confirm');
            Route::get('/checkout/{order}/payment-pending', [\App\Http\Controllers\Payment\BankTransferController::class, 'pending'])->name('local.payment.pending');

            // Tracking
            Route::get('/track', [StoreOrderController::class, 'track'])->name('local.store.order.track');
            Route::post('/track', [StoreOrderController::class, 'findOrder'])->name('local.store.order.find');
        });
}

// Subdomain routes
Route::domain('{store_subdomain}.' . config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST)))
    ->where(['store_subdomain' => '(?!www)[A-Za-z0-9_\-]+'])
    ->group(function () {

    // Store homepage (products listing)
    Route::get('/', [ProductController::class, 'indexByStore'])->name('home.store.products.index');

    // Live search
        Route::get('/search', [SearchController::class, 'liveSearch'])->name('home.store.search');

        // Support page routes
        Route::get('/support', [StoreSupportController::class, 'index'])->name('home.support.index');
        Route::post('/support', [StoreSupportController::class, 'store'])->name('home.support.store');

        // Category page
        Route::get('/category/{category}', [StoreCategoryController::class, 'index'])->name('home.store.category');

        // Products & Services Index Pages
        Route::get('/products', [StoreProductController::class, 'index'])->name('home.store.products');
        Route::get('/services', [StoreServiceController::class, 'index'])->name('home.store.services');

        // SHOP4ME landing page
        Route::get('/shop4me', [Shop4meController::class, 'page'])->name('home.store.shop4me');

        // Product details
        Route::get('/products/{slug}-{code}', [ProductController::class, 'show'])
            ->where([
                'code' => 'prd_[A-Za-z0-9]{8}',
                'slug' => '.+',
            ])->name('home.products.show');
            
        // Service details
        Route::get('/services/{slug}-{code}', [ProductController::class, 'showService'])
            ->where([
                'code' => 'svc_[A-Za-z0-9]{8}',
                'slug' => '.+',
            ])->name('home.services.show');
        
        // Cart page
        Route::get('/cart', [CartController::class, 'cart'])->name('home.store.cart');
        
        // Cart Actions
        Route::post('/cart/checkout', [CartController::class, 'proceedToCheckout'])->name('home.store.cart.proceed');

        // Checkout
        Route::get('/checkout/{token}', [CheckoutController::class, 'index'])->name('checkout.index');
        Route::post('/checkout', [CheckoutController::class, 'process'])->name('checkout.process');
        Route::get('/checkout/payment/{order}', [CheckoutController::class, 'payment'])->name('checkout.payment');
        
        // Payment Method Selection
        Route::get('/checkout/{order}/payment-methods', [CheckoutController::class, 'showPaymentMethods'])->name('checkout.payment-methods');
        Route::post('/checkout/{order}/payment-methods', [CheckoutController::class, 'selectPaymentMethod'])->name('checkout.payment-methods.select');
        Route::post('/checkout/{order}/confirm-payment', [CheckoutController::class, 'confirmPayment'])->name('checkout.confirm-payment');
        
        // Bank Transfer Payment
        Route::get('/checkout/{order}/bank-transfer', [\App\Http\Controllers\Payment\BankTransferController::class, 'show'])->name('payment.bank-transfer');
        Route::post('/checkout/{order}/bank-transfer/confirm', [\App\Http\Controllers\Payment\BankTransferController::class, 'confirmPayment'])->name('payment.bank-transfer.confirm');
        Route::get('/checkout/{order}/payment-pending', [\App\Http\Controllers\Payment\BankTransferController::class, 'pending'])->name('payment.pending');
        Route::get('/checkout/{order}/payment-remaining', [\App\Http\Controllers\Payment\BankTransferController::class, 'remaining'])->name('payment.remaining');
    });

// Paystack Payment Routes (global, not subdomain-specific)
Route::prefix('payment/paystack')->name('payment.paystack.')->group(function () {
    Route::post('/initialize', [\App\Http\Controllers\Payment\PaystackController::class, 'initialize'])->name('initialize');
    Route::get('/callback', [\App\Http\Controllers\Payment\PaystackController::class, 'callback'])->name('callback');
    Route::post('/webhook', [\App\Http\Controllers\Payment\PaystackController::class, 'webhook'])->name('webhook');
});

// Order Payment Status Pages (using transaction reference - global)
Route::get('/payment/{reference}/success', function($reference) {
    $transaction = \App\Models\Transaction::where('reference', $reference)->firstOrFail();
    $order = $transaction->order;
    $store = $order->store;
    return view('storefront.pages.payment.success', compact('order', 'transaction', 'store'));
})->name('order.success');

Route::get('/payment/{reference}/failed', function($reference) {
    $transaction = \App\Models\Transaction::where('reference', $reference)->firstOrFail();
    $order = $transaction->order;
    $store = $order->store;
    return view('storefront.pages.payment.failed', compact('order', 'transaction', 'store'));
})->name('order.failed');

// Invoice Payment Routes (public, no auth)
Route::prefix('pay/invoice/{token}')->name('invoice.pay.')->group(function () {
    Route::get('/', [InvoicePaymentController::class, 'show'])->name('show');
    Route::post('/initialize', [InvoicePaymentController::class, 'initialize'])->name('initialize');
    Route::get('/callback', [InvoicePaymentController::class, 'callback'])->name('callback');
    Route::get('/success', [InvoicePaymentController::class, 'success'])->name('success');
    Route::post('/bank-transfer', [InvoicePaymentController::class, 'bankTransfer'])->name('bank-transfer');
});
