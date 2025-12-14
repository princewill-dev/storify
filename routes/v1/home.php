<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Home\HomePageController;
use App\Http\Controllers\Home\ProductController;
use App\Http\Controllers\Home\TrackingController;
use App\Http\Controllers\Home\BulkOrderController;
use App\Http\Controllers\Shop4me\Shop4meController;
use App\Http\Controllers\Cart\CartController;
use App\Http\Controllers\Cart\CartApiController;
use App\Http\Controllers\BulkCartController;
use App\Http\Controllers\Home\InternationalSupplyController;
use App\Http\Controllers\Home\SearchController;
use App\Http\Controllers\Home\LiveFirstController;

// Handle www subdomain redirect to main domain
Route::domain('www.' . config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST)))->group(function () {
    Route::get('{any}', function () {
        $mainDomain = config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST));
        $scheme = request()->secure() ? 'https' : 'http';
        $path = request()->path() !== '/' ? '/' . request()->path() : '';
        return redirect("{$scheme}://{$mainDomain}{$path}", 301);
    })->where('any', '.*');
});

// Main domain routes (non-subdomain)
Route::domain(config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST)))->group(function () {
    //homepage routes
    Route::get('/', [HomePageController::class, 'index'])->name('home.index');
    
    // Order tracking
    Route::get('/track-order', [TrackingController::class, 'index'])->name('tracking.index');
    Route::get('/track-order/{order}', [TrackingController::class, 'show'])->name('tracking.show');
    
    Route::get('/about-us', [HomePageController::class, 'about'])->name('home.about');
    Route::get('/support', [HomePageController::class, 'support'])->name('home.support');
    Route::get('/stores', [HomePageController::class, 'stores'])->name('home.stores');
    Route::get('/services', [HomePageController::class, 'services'])->name('home.services');
    
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

// Subdomain routes for stores (excluding www)
Route::domain('{store_subdomain}.' . config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST)))
    ->where(['store_subdomain' => '(?!www)[A-Za-z0-9_\-]+'])
    ->group(function () {
        
    // Store homepage (products listing)
    Route::get('/', [ProductController::class, 'indexByStore'])->name('home.store.products.index');
    
    // Live search
    Route::get('/search', [SearchController::class, 'liveSearch'])->name('home.store.search');
    
    // Live First program routes
    Route::get('/live-first', [LiveFirstController::class, 'index'])->name('home.live-first.index');
    
    Route::middleware('auth:customer')->group(function () {
        Route::get('/live-first/kyc', [LiveFirstController::class, 'showKycForm'])->name('home.live-first.kyc');
        Route::post('/live-first/kyc', [LiveFirstController::class, 'submitKyc'])->name('home.live-first.kyc.submit');
        Route::get('/live-first/status', [LiveFirstController::class, 'status'])->name('home.live-first.status');
    });
    
    // Support page routes
    Route::get('/support', [\App\Http\Controllers\Home\SupportController::class, 'index'])->name('home.support.index');
    Route::post('/support', [\App\Http\Controllers\Home\SupportController::class, 'store'])->name('home.support.store');
    
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
    
    // SHOP4ME landing page
    Route::get('/shop4me', [Shop4meController::class, 'page'])->name('home.store.shop4me');
    
    // BULK BUY page
    Route::get('/bulk_buy', [BulkOrderController::class, 'index'])->name('home.store.bulk_buy');
    
    // International Supply page
    Route::get('/international-supply', [InternationalSupplyController::class, 'page'])->name('home.store.international_supply');
    
    // Bulk cart API routes
    Route::post('/bulk/cart/add', [BulkCartController::class, 'add'])->name('bulk.cart.add');
    Route::post('/bulk/cart/custom', [BulkCartController::class, 'addCustom'])->name('bulk.cart.custom');
    Route::post('/bulk/cart/custom/sync', [BulkCartController::class, 'syncCustom'])->name('bulk.cart.custom.sync');
    Route::get('/bulk/cart', [BulkCartController::class, 'get'])->name('bulk.cart.get');
    Route::patch('/bulk/cart/{productId}', [BulkCartController::class, 'update'])->name('bulk.cart.update');
    Route::delete('/bulk/cart/{productId}', [BulkCartController::class, 'remove'])->name('bulk.cart.remove');
    Route::delete('/bulk/cart/custom/{index}', [BulkCartController::class, 'removeCustom'])->name('bulk.cart.removeCustom');
    Route::delete('/bulk/cart', [BulkCartController::class, 'clear'])->name('bulk.cart.clear');
    
    // Bulk checkout page
    Route::get('/bulk_buy/checkout', [BulkOrderController::class, 'checkout'])->name('bulk.checkout');
    
    // Bulk order review page
    Route::get('/bulk_buy/order/{bulkCode}', [BulkOrderController::class, 'review'])
        ->where(['bulkCode' => 'BULK-[A-Z0-9]+'])
        ->middleware('auth:customer')
        ->name('bulk.order.review');
    
    // Bulk checkout submission and confirmation (requires authentication)
    Route::middleware('auth:customer')->group(function () {
        Route::post('/bulk_buy/checkout/submit', [BulkOrderController::class, 'submitOrder'])->name('bulk.checkout.submit');
        Route::get('/bulk_buy/order/{bulkCode}/confirmation', [BulkOrderController::class, 'confirmation'])->name('bulk.order.confirmation');
        
        // Customer response and acceptance routes
        Route::post('/bulk_buy/order/{bulkCode}/respond', [BulkOrderController::class, 'submitResponse'])
            ->where(['bulkCode' => 'BULK-[A-Z0-9]+'])
            ->name('bulk.order.respond');
        
        Route::post('/bulk_buy/order/{bulkCode}/accept', [BulkOrderController::class, 'acceptOrder'])
            ->where(['bulkCode' => 'BULK-[A-Z0-9]+'])
            ->name('bulk.order.accept');
    });
    
    // Cart page
    Route::get('/cart', [CartController::class, 'cart'])->name('home.store.cart');
    
    // Cart JSON API
    Route::get('/cart/json', [CartApiController::class, 'get']);
    Route::post('/cart/add', [CartApiController::class, 'add']);
    Route::patch('/cart/item/{item}', [CartApiController::class, 'updateItem'])->where(['item' => '[0-9]+']);
    Route::delete('/cart/item/{item}', [CartApiController::class, 'removeItem'])->where(['item' => '[0-9]+']);
    Route::delete('/cart/clear', [CartApiController::class, 'clear']);
});
