<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\Auth\AdminAuthController;

// Default login route (for auth middleware redirect)
Route::get('/login', function() {
    return redirect()->route('admin.login');
})->name('login');

// Superadmin Setup / Onboard Routes
Route::get('/superadmin/setup', [AdminAuthController::class, 'onboard'])->name('admin.setup');
Route::post('/superadmin/setup', [AdminAuthController::class, 'processOnboard'])->name('admin.setup.process');
// Legacy onboard route
Route::get('/superadmin/onboard', fn() => redirect()->route('admin.setup'))->name('admin.onboard');
Route::post('/superadmin/onboard', fn() => redirect()->route('admin.setup'))->name('admin.onboard.process');

// Admin Login Routes
Route::get('/superadmin', [AdminAuthController::class, 'login'])->name('admin.login');
Route::post('/superadmin', [AdminAuthController::class, 'processLogin'])->name('admin.login.process')->middleware('throttle:6,1');

// Password Reset via OTP (Superadmin)
Route::get('/superadmin/forgot-password', [AdminAuthController::class, 'showForgotPassword'])->name('admin.password.forgot');
Route::post('/superadmin/forgot-password', [AdminAuthController::class, 'processForgotPassword'])->name('admin.password.forgot.process')->middleware('throttle:3,10');
Route::get('/superadmin/reset-password', [AdminAuthController::class, 'showResetPassword'])->name('admin.password.reset');
Route::post('/superadmin/reset-password', [AdminAuthController::class, 'processResetPassword'])->name('admin.password.reset.process');

// OTP Verification Routes
Route::get('/superadmin/verify-otp', [AdminAuthController::class, 'showVerifyOtp'])->name('admin.verify-otp');
Route::post('/superadmin/verify-otp', [AdminAuthController::class, 'verifyOtp'])->name('admin.verify-otp.process')->middleware('throttle:6,1');
Route::post('/superadmin/resend-otp', [AdminAuthController::class, 'resendOtp'])->name('admin.verify-otp.resend')->middleware('throttle:3,10');

// Logout Route (protected)
Route::post('/superadmin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');



