<?php

use App\Http\Controllers\Api\V1\Home\HomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Home API (public marketing site)
|--------------------------------------------------------------------------
| Standalone from the storefront API on purpose: this surface only serves
| the storyify.ng marketing site (company, plans, testimonials, services).
*/

Route::prefix('home')->name('api.home.')->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('index');
    Route::get('stores', [HomeController::class, 'stores'])->name('stores');
    Route::post('support', [HomeController::class, 'support'])->middleware('throttle:3,10')->name('support');
});
