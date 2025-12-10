<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\StorefrontSlideController;

// API endpoints for admin storefront slides and product listings
Route::middleware(['auth'])
    ->prefix('api/superadmin')
    ->name('api.admin.')
    ->group(function () {
        // List store products (with search/pagination) for the modal
        Route::get('stores/{store}/products', [StorefrontSlideController::class, 'apiListProducts'])->name('store-products.index');

        // Bulk create slides from selected product IDs
        Route::post('stores/{store}/storefront-slides/bulk', [StorefrontSlideController::class, 'apiBulkStore'])->name('storefront-slides.bulk');

        // Reorder slides
        Route::post('stores/{store}/storefront-slides/reorder', [StorefrontSlideController::class, 'reorder'])->name('storefront-slides.reorder');
    });
