<?php

use Illuminate\Support\Facades\Route;

// Legacy vendor routes - redirect to management
Route::prefix('vendor')->name('vendor.')->group(function () {
    Route::redirect('/register', '/management/register', 301)->name('auth.register');
    Route::post('/register', fn() => redirect('/management/register', 301));
    Route::redirect('/login', '/management/login', 301)->name('auth.login');
    Route::post('/login', fn() => redirect('/management/login', 301));
    Route::redirect('/forgot-password', '/management/forgot-password', 301);
    Route::redirect('/reset-password', '/management/reset-password', 301);

    Route::redirect('/', '/management', 301);
    Route::redirect('/{any}', '/management', 301)->where('any', '.*');
});
