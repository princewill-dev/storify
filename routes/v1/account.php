<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Account\RegisterController;
use App\Http\Controllers\Account\AccountController;

// Registration + OTP under /account paths (list parameter optional for SHOP4ME flow)
Route::get('/account/register/{list?}', [RegisterController::class, 'showRegister'])->name('account.register');
Route::post('/account/register/{list?}', [RegisterController::class, 'register'])->middleware('throttle:6,1');
Route::get('/account/verify/{list?}', [RegisterController::class, 'showVerify'])->name('account.verify');
Route::post('/account/verify/{list?}', [RegisterController::class, 'verify'])->middleware('throttle:6,1');
Route::post('/account/verify/{list?}/resend', [RegisterController::class, 'resendOtp'])->name('account.verify.resend')->middleware('throttle:3,10');

// Authentication
Route::get('/account/login', [AccountController::class, 'showLogin'])->name('account.login');
Route::post('/account/login', [AccountController::class, 'login'])->middleware('throttle:6,1');
Route::post('/account/logout', [AccountController::class, 'logout'])->name('account.logout');

// Password Reset
Route::get('/account/forgot-password', [AccountController::class, 'showForgotPassword'])->name('account.forgot-password');
Route::post('/account/forgot-password', [AccountController::class, 'sendResetOtp'])->middleware('throttle:3,10');
Route::get('/account/reset-password/verify', [AccountController::class, 'showVerifyOtp'])->name('account.reset-password.verify');
Route::post('/account/reset-password/verify', [AccountController::class, 'verifyOtp']);
Route::get('/account/reset-password/{token}', [AccountController::class, 'showResetPassword'])->name('account.reset-password.form');
Route::post('/account/reset-password/{token}', [AccountController::class, 'resetPassword']);

// Protected Account Routes - Use customer guard
Route::middleware('auth:customer')->group(function () {
    Route::get('/account/dashboard', [AccountController::class, 'dashboard'])->name('account.dashboard');
    Route::get('/account/info', [AccountController::class, 'showAccountInfo'])->name('account.info');
    Route::post('/account/info', [AccountController::class, 'updateAccountInfo']);
    Route::get('/account/orders', [AccountController::class, 'orders'])->name('account.orders');
    Route::get('/account/orders/{orderNumber}', [AccountController::class, 'showOrder'])->name('account.order.show');
    Route::get('/account/transactions', [AccountController::class, 'transactions'])->name('account.transactions');
    Route::get('/account/transactions/{transactionId}', [AccountController::class, 'showTransaction'])->name('account.transaction.show');
    
    // Address Management
    Route::post('/account/addresses', [App\Http\Controllers\Account\AddressController::class, 'store'])->name('account.addresses.store');
});
