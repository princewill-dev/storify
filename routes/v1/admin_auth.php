<?php

use App\Http\Controllers\Admin\Auth\AdminAuthController;
use App\Http\Controllers\Admin\AdminInvitationController;
use Illuminate\Support\Facades\Route;

// Default login route (for auth middleware redirect)
Route::get('/login', function () {
    return redirect()->route('admin.login');
})->name('login');

// Superadmin Setup / Onboard Routes
Route::get('/office/setup', [AdminAuthController::class, 'onboard'])->name('admin.setup');
Route::post('/office/setup', [AdminAuthController::class, 'processOnboard'])->name('admin.setup.process');
// Legacy onboard route
Route::get('/office/onboard', fn () => redirect()->route('admin.setup'))->name('admin.onboard');
Route::post('/office/onboard', fn () => redirect()->route('admin.setup'))->name('admin.onboard.process');

// Admin Login Routes
Route::get('/office', [AdminAuthController::class, 'login'])->name('admin.login');
Route::post('/office', [AdminAuthController::class, 'processLogin'])->name('admin.login.process')->middleware('throttle:6,1');

// Password Reset via OTP (Superadmin)
Route::get('/office/forgot-password', [AdminAuthController::class, 'showForgotPassword'])->name('admin.password.forgot');
Route::post('/office/forgot-password', [AdminAuthController::class, 'processForgotPassword'])->name('admin.password.forgot.process')->middleware('throttle:3,10');
Route::get('/office/reset-password', [AdminAuthController::class, 'showResetPassword'])->name('admin.password.reset');
Route::post('/office/reset-password', [AdminAuthController::class, 'processResetPassword'])->name('admin.password.reset.process');

// OTP Verification Routes
Route::get('/office/verify-otp', [AdminAuthController::class, 'showVerifyOtp'])->name('admin.verify-otp');
Route::post('/office/verify-otp', [AdminAuthController::class, 'verifyOtp'])->name('admin.verify-otp.process')->middleware('throttle:6,1');
Route::post('/office/resend-otp', [AdminAuthController::class, 'resendOtp'])->name('admin.verify-otp.resend')->middleware('throttle:3,10');

// Logout Route (protected)
Route::post('/office/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

// Admin invitation accept (public)
Route::get('/office/invite/{token}', [AdminInvitationController::class, 'showAccept'])->name('admin.invitation.accept');
Route::post('/office/invite/{token}', [AdminInvitationController::class, 'accept'])->name('admin.invitation.accept.process');

// Legacy portal redirects: old /superadmin/* bookmarks now live under /office/*
Route::get('/superadmin', fn () => redirect('/office', 301));
Route::get('/superadmin/{path}', fn (string $path) => redirect('/office/'.$path, 301))
    ->where('path', '.*');

// Stop impersonation — must work while impersonating, so only requires an authenticated session.
Route::post('/office/impersonate/stop', [\App\Http\Controllers\Admin\UserController::class, 'stopImpersonate'])
    ->middleware('auth')
    ->name('admin.impersonate.stop');
