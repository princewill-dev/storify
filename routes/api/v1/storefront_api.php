<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Cart\CartApiController;

// Local dev bypass
if (config('app.env') === 'local') {
    Route::prefix('{store_subdomain}')
        ->where(['store_subdomain' => '(?!api|admin|vendor|storage|livewire|cart|checkout|products|services|search|support|bulk_buy|international-supply|live-first)[A-Za-z0-9_\-]+'])
        ->group(function () {
            
            // Cart JSON API
            Route::get('/cart/json', [CartApiController::class, 'get']);
            Route::post('/cart/add', [CartApiController::class, 'add']);
            Route::patch('/cart/item/{item}', [CartApiController::class, 'updateItem'])->where(['item' => '[0-9]+']);
            Route::delete('/cart/item/{item}', [CartApiController::class, 'removeItem'])->where(['item' => '[0-9]+']);
            Route::delete('/cart/clear', [CartApiController::class, 'clear']);
        });
}

// Subdomain routes
Route::domain('{store_subdomain}.' . config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST)))
    ->where(['store_subdomain' => '(?!www)[A-Za-z0-9_\-]+'])
    ->group(function () {
        
        // Cart JSON API
        Route::get('/cart/json', [CartApiController::class, 'get']);
        Route::post('/cart/add', [CartApiController::class, 'add']);
        Route::patch('/cart/item/{item}', [CartApiController::class, 'updateItem'])->where(['item' => '[0-9]+']);
        Route::delete('/cart/item/{item}', [CartApiController::class, 'removeItem'])->where(['item' => '[0-9]+']);
        Route::delete('/cart/clear', [CartApiController::class, 'clear']);
    });
