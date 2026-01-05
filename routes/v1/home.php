<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Home\HomePageController;
use App\Http\Controllers\Home\ProductController;
use App\Http\Controllers\Home\TrackingController;
// use App\Http\Controllers\Home\BulkOrderController;
use App\Http\Controllers\Shop4me\Shop4meController;
use App\Http\Controllers\Storefront\StoreSupportController;
use App\Http\Controllers\Storefront\StoreOrderController;
use App\Http\Controllers\Cart\CartController;
use App\Http\Controllers\Cart\CartApiController;
// use App\Http\Controllers\BulkCartController;
// use App\Http\Controllers\Home\InternationalSupplyController;
use App\Http\Controllers\Home\SearchController;
// use App\Http\Controllers\Home\LiveFirstController;
use App\Http\Controllers\Home\SupportController;



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
    Route::get('/support', [SupportController::class, 'platformIndex'])->name('home.support');
    Route::post('/support/send', [SupportController::class, 'platformSend'])->name('home.support.send');
    Route::get('/stores', [HomePageController::class, 'stores'])->name('home.stores');
    Route::get('/services', [HomePageController::class, 'services'])->name('home.services');
});

// Local dev bypass: access stores via path instead of subdomain
if (config('app.env') === 'local') {
    Route::prefix('{store_subdomain}')
        ->where(['store_subdomain' => '(?!api|admin|vendor|storage|livewire|cart|checkout|products|services|search|support|bulk_buy|international-supply|live-first)[A-Za-z0-9_\-]+'])
        ->group(function () {
            // Store homepage (products listing)
            Route::get('/', [ProductController::class, 'indexByStore'])->name('local.store.products.index');
            
            // Live search
            Route::get('/search', [SearchController::class, 'liveSearch'])->name('local.store.search');
            
            // SHOP4ME landing page
            Route::get('/shop4me', [Shop4meController::class, 'page'])->name('local.store.shop4me');

            Route::get('/track/{orderNumber?}', [StoreOrderController::class, 'track'])->name('home.store.order.track');
            Route::post('/track', [StoreOrderController::class, 'findOrder'])->name('home.store.order.find');
        });
}

// Subdomain routes for stores (excluding www)
Route::domain('{store_subdomain}.' . config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST)))
    ->where(['store_subdomain' => '(?!www)[A-Za-z0-9_\-]+'])
    ->group(function () {
        
    // Store homepage (products listing)
    Route::get('/', [ProductController::class, 'indexByStore'])->name('home.store.products.index');

    // Order Tracking (Moved to top for priority)
    Route::get('/track/{orderNumber?}', [StoreOrderController::class, 'track'])->name('home.store.order.track');
    Route::post('/track', [StoreOrderController::class, 'findOrder'])->name('home.store.order.find');
    
    // Live search
    Route::get('/search', [SearchController::class, 'liveSearch'])->name('home.store.search');
    
    // Category page
    Route::get('/category/{category}', [\App\Http\Controllers\Storefront\StoreCategoryController::class, 'index'])->name('home.store.category');

    // SHOP4ME landing page
    Route::get('/shop4me', [Shop4meController::class, 'page'])->name('home.store.shop4me');
});
