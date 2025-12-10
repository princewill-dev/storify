<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Shop4me\Shop4meController;

// Submit list (pre-account) scoped to store
Route::post('/{store_slug}/shop4me/requests', [Shop4meController::class, 'storeRequest'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+' ])
    ->name('shop4me.requests.store');

// Unified checkout entry for Shop4Me lists
Route::get('/shop4me/{list}/checkout', [Shop4meController::class, 'checkout'])
    ->name('shop4me.checkout');

// Legacy delivery path now redirects to checkout
Route::get('/shop4me/{list}/delivery', [Shop4meController::class, 'checkout']);

// Preferred public tracking URL
Route::get('/tracking/{list}', [Shop4meController::class, 'track'])->name('tracking.order');
// Legacy tracking path kept (optional)
Route::get('/shop4me/{list}/track', [Shop4meController::class, 'track'])->name('shop4me.track');
