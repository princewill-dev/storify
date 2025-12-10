<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Home\FamilyPackController;
use App\Http\Controllers\FamilyPackCartController;

Route::middleware(['web'])->group(function () {
    // Cart Routes (MUST be before store_slug routes to avoid conflicts)
    Route::get('/family-pack/cart/data', [FamilyPackCartController::class, 'getCartData'])->name('family-pack.cart.data');
    Route::post('/family-pack/cart/add', [FamilyPackCartController::class, 'add'])->name('family-pack.cart.add');
    Route::post('/family-pack/cart/add-custom', [FamilyPackCartController::class, 'addCustom'])->name('family-pack.cart.add-custom');
    Route::post('/family-pack/cart/update', [FamilyPackCartController::class, 'update'])->name('family-pack.cart.update');
    Route::post('/family-pack/cart/remove', [FamilyPackCartController::class, 'remove'])->name('family-pack.cart.remove');
    Route::post('/family-pack/cart/clear', [FamilyPackCartController::class, 'clear'])->name('family-pack.cart.clear');

    // Family Pack Routes (with store_slug parameter)
    Route::get('/{store_slug}/family-pack', [FamilyPackController::class, 'index'])->name('family-pack.index');
    Route::get('/{store_slug}/family-pack/checkout', [FamilyPackController::class, 'checkout'])->name('family-pack.checkout');
    Route::post('/{store_slug}/family-pack/submit', [FamilyPackController::class, 'submitOrder'])->name('family-pack.submit');
    Route::get('/{store_slug}/family-pack/{packCode}/review', [FamilyPackController::class, 'review'])->name('family-pack.review');
    Route::post('/{store_slug}/family-pack/{packCode}/accept', [FamilyPackController::class, 'accept'])->name('family-pack.accept');

    // Customer deliveries and subscription controls
    Route::get('/{store_slug}/family-pack/{packCode}/deliveries', [FamilyPackController::class, 'deliveries'])->name('family-pack.deliveries');
    Route::post('/{store_slug}/family-pack/{packCode}/subscription/pause', [FamilyPackController::class, 'pauseSubscription'])->name('family-pack.subscription.pause');
    Route::post('/{store_slug}/family-pack/{packCode}/subscription/resume', [FamilyPackController::class, 'resumeSubscription'])->name('family-pack.subscription.resume');
    Route::post('/{store_slug}/family-pack/{packCode}/subscription/cancel', [FamilyPackController::class, 'cancelSubscription'])->name('family-pack.subscription.cancel');
    Route::post('/{store_slug}/family-pack/{packCode}/deliveries/{cycle}/skip', [FamilyPackController::class, 'skipDelivery'])->name('family-pack.deliveries.skip');
});
