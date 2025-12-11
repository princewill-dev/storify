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


//homepage routes
Route::get('/', [HomePageController::class, 'index'])->name('home.index');

// Order tracking
Route::get('/track-order', [TrackingController::class, 'index'])->name('tracking.index');
Route::get('/track-order/{order}', [TrackingController::class, 'show'])->name('tracking.show');

Route::get('/about-us', [HomePageController::class, 'about'])->name('home.about');
Route::get('/support', [HomePageController::class, 'support'])->name('home.support');
Route::get('/stores', [HomePageController::class, 'stores'])->name('home.stores');
Route::get('/services', [HomePageController::class, 'services'])->name('home.services');

// Store-scoped live search (must come BEFORE catch-all store route)
Route::get('/{store_slug}/search', [SearchController::class, 'liveSearch'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
    ->name('home.store.search');

// Live First program routes (must come BEFORE catch-all store route)
Route::get('/{store_slug}/live-first', [LiveFirstController::class, 'index'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
    ->name('home.live-first.index');

Route::middleware('auth:customer')->group(function () {
    Route::get('/{store_slug}/live-first/kyc', [LiveFirstController::class, 'showKycForm'])
        ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
        ->name('home.live-first.kyc');
    
    Route::post('/{store_slug}/live-first/kyc', [LiveFirstController::class, 'submitKyc'])
        ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
        ->name('home.live-first.kyc.submit');
    
    Route::get('/{store_slug}/live-first/status', [LiveFirstController::class, 'status'])
        ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
        ->name('home.live-first.status');
});

// Support page routes (must come BEFORE catch-all store route)
Route::get('/{store_slug}/support', [\App\Http\Controllers\Home\SupportController::class, 'index'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
    ->name('home.support.index');

Route::post('/{store_slug}/support', [\App\Http\Controllers\Home\SupportController::class, 'store'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
    ->name('home.support.store');

// Store products listing by store slug
Route::get('/{store_slug}', [ProductController::class, 'indexByStore'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
    ->name('home.store.products.index');

Route::get('/{store_slug}/products/{slug}-{code}', [ProductController::class, 'show'])
    ->where([
        'code' => 'prd_[A-Za-z0-9]{8}',
        'slug' => '.+',
    ])->name('home.products.show');

// Short code redirect to canonical (store-scoped)
Route::get('/p/{code}', function ($code) {
    $product = \App\Models\Product::with('store')->where('product_code', $code)->firstOrFail();
    $storeSlug = optional($product->store)->slug ?? 'store';
    return redirect()->route('home.products.show', [
        'store_slug' => $storeSlug,
        'slug' => $product->slug,
        'code' => $product->product_code,
    ], 301);
})->where('code', 'prd_[A-Za-z0-9]{8}');

// Store-scoped SHOP4ME landing page
Route::get('/{store_slug}/shop4me', [Shop4meController::class, 'page'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
    ->name('home.store.shop4me');

// Store-scoped BULK BUY page
Route::get('/{store_slug}/bulk_buy', [BulkOrderController::class, 'index'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
    ->name('home.store.bulk_buy');

// Store-scoped International Supply page (Coming Soon)
Route::get('/{store_slug}/international-supply', [InternationalSupplyController::class, 'page'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
    ->name('home.store.international_supply');

// Bulk cart API routes
Route::post('/bulk/cart/add', [BulkCartController::class, 'add'])->name('bulk.cart.add');
Route::post('/bulk/cart/custom', [BulkCartController::class, 'addCustom'])->name('bulk.cart.custom');
Route::post('/bulk/cart/custom/sync', [BulkCartController::class, 'syncCustom'])->name('bulk.cart.custom.sync');
Route::get('/bulk/cart', [BulkCartController::class, 'get'])->name('bulk.cart.get');
Route::patch('/bulk/cart/{productId}', [BulkCartController::class, 'update'])->name('bulk.cart.update');
Route::delete('/bulk/cart/{productId}', [BulkCartController::class, 'remove'])->name('bulk.cart.remove');
Route::delete('/bulk/cart/custom/{index}', [BulkCartController::class, 'removeCustom'])->name('bulk.cart.removeCustom');
Route::delete('/bulk/cart', [BulkCartController::class, 'clear'])->name('bulk.cart.clear');

// Bulk checkout page (auth handled in controller for custom redirect)
Route::get('/{store_slug}/bulk_buy/checkout', [BulkOrderController::class, 'checkout'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
    ->name('bulk.checkout');

// Bulk order review page (for customers to review admin changes)
Route::get('/{store_slug}/bulk_buy/order/{bulkCode}', [BulkOrderController::class, 'review'])
    ->where([
        'store_slug' => '[A-Za-z0-9_\-]+',
        'bulkCode' => 'BULK-[A-Z0-9]+'
    ])
    ->middleware('auth:customer')
    ->name('bulk.order.review');

// Bulk checkout submission and confirmation (requires authentication)
Route::middleware('auth:customer')->group(function () {
    Route::post('/{store_slug}/bulk_buy/checkout/submit', [BulkOrderController::class, 'submitOrder'])
        ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
        ->name('bulk.checkout.submit');
        
    Route::get('/{store_slug}/bulk_buy/order/{bulkCode}/confirmation', [BulkOrderController::class, 'confirmation'])
        ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
        ->name('bulk.order.confirmation');
    
    // Customer response and acceptance routes
    Route::post('/{store_slug}/bulk_buy/order/{bulkCode}/respond', [BulkOrderController::class, 'submitResponse'])
        ->where([
            'store_slug' => '[A-Za-z0-9_\-]+',
            'bulkCode' => 'BULK-[A-Z0-9]+'
        ])
        ->name('bulk.order.respond');
    
    Route::post('/{store_slug}/bulk_buy/order/{bulkCode}/accept', [BulkOrderController::class, 'acceptOrder'])
        ->where([
            'store_slug' => '[A-Za-z0-9_\-]+',
            'bulkCode' => 'BULK-[A-Z0-9]+'
        ])
        ->name('bulk.order.accept');
});

// Store-scoped cart page
Route::get('/{store_slug}/cart', [CartController::class, 'cart'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
    ->name('home.store.cart');

// Store-scoped cart JSON API (web, CSRF protected)
Route::get('/{store_slug}/cart/json', [CartApiController::class, 'get'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ]);

Route::post('/{store_slug}/cart/add', [CartApiController::class, 'add'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ]);

Route::patch('/{store_slug}/cart/item/{item}', [CartApiController::class, 'updateItem'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+', 'item' => '[0-9]+' ]);

Route::delete('/{store_slug}/cart/item/{item}', [CartApiController::class, 'removeItem'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+', 'item' => '[0-9]+' ]);

Route::delete('/{store_slug}/cart/clear', [CartApiController::class, 'clear'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ]);
