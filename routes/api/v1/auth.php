<?php

use App\Http\Controllers\Api\V1\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Auth\CustomerAuthController;
use App\Http\Controllers\Api\V1\Auth\ImpersonationController;
use App\Http\Controllers\Api\V1\Auth\InvitationController;
use App\Http\Controllers\Api\V1\Auth\ManagementAuthController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\Management\SetupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API authentication (standalone frontends)
|--------------------------------------------------------------------------
| Bearer access tokens with per-app audiences; rotating refresh tokens.
| No sessions, no CSRF.
*/

// Token exchange
Route::post('auth/refresh', [TokenController::class, 'refresh'])->middleware('throttle:auth');

// Public invitations
Route::get('management/invitations/{token}', [InvitationController::class, 'showStaff']);
Route::post('management/invitations/{token}', [InvitationController::class, 'acceptStaff'])->middleware('throttle:auth');
Route::get('admin/invitations/{token}', [InvitationController::class, 'showAdmin']);
Route::post('admin/invitations/{token}', [InvitationController::class, 'acceptAdmin'])->middleware('throttle:auth');

// Management app — public auth
Route::prefix('management/auth')->name('api.management.auth.')->middleware('throttle:auth')->group(function () {
    Route::post('register', [ManagementAuthController::class, 'register'])->name('register');
    Route::post('login', [ManagementAuthController::class, 'login'])->name('login');
    Route::post('verify-otp', [ManagementAuthController::class, 'verifyOtp'])->name('verify-otp');
    Route::post('resend-otp', [ManagementAuthController::class, 'resendOtp'])->name('resend-otp');
    Route::post('forgot-password', [ManagementAuthController::class, 'forgotPassword'])->name('forgot-password');
    Route::post('reset-password', [ManagementAuthController::class, 'resetPassword'])->name('reset-password');
});

// Admin app — public auth
Route::prefix('admin/auth')->name('api.admin.auth.')->middleware('throttle:auth')->group(function () {
    Route::get('setup-status', [AdminAuthController::class, 'setupStatus'])->name('setup-status');
    Route::post('setup', [AdminAuthController::class, 'setup'])->name('setup');
    Route::post('login', [AdminAuthController::class, 'login'])->name('login');
    Route::post('verify-otp', [AdminAuthController::class, 'verifyOtp'])->name('verify-otp');
    Route::post('resend-otp', [AdminAuthController::class, 'resendOtp'])->name('resend-otp');
    Route::post('forgot-password', [AdminAuthController::class, 'forgotPassword'])->name('forgot-password');
    Route::post('reset-password', [AdminAuthController::class, 'resetPassword'])->name('reset-password');
});

/*
| Storefront (customer) app — public auth, transitional.
|
| The canonical routes are now `storefront/{store}/auth/*` in storefront.php,
| because a customer account belongs to one business and the request therefore
| has to name it. These tenant-less URIs are kept working for the storefront
| that is already deployed and calls them; the controller resolves the business
| from the payload, a header, or the request's `Origin` host.
|
| Delete this group once no deployed bundle calls it — the same condition that
| retires the account-less checkout fallback. The `legacy.` name infix keeps it
| from silently claiming the canonical names, which would otherwise make
| route('api.storefront.auth.register') resolve to whichever registered last.
*/
Route::prefix('storefront/auth')->name('api.storefront.auth.legacy.')->middleware('throttle:auth')->group(function () {
    Route::post('register', [CustomerAuthController::class, 'register'])->name('register');
    Route::post('login', [CustomerAuthController::class, 'login'])->name('login');
    Route::post('verify-otp', [CustomerAuthController::class, 'verifyOtp'])->name('verify-otp');
    Route::post('resend-otp', [CustomerAuthController::class, 'resendOtp'])->name('resend-otp');
    Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword'])->name('forgot-password');
    Route::post('reset-password', [CustomerAuthController::class, 'resetPassword'])->name('reset-password');
});

// Management app — authenticated
Route::middleware(['auth:sanctum', 'token.audience:management', 'team.context'])
    ->prefix('management/auth')->name('api.management.auth.')
    ->group(function () {
        Route::get('me', [ManagementAuthController::class, 'me'])->name('me');
        Route::put('profile', [ManagementAuthController::class, 'updateProfile'])->name('profile');
        Route::post('change-password', [ManagementAuthController::class, 'changePassword'])->name('change-password');
        Route::post('logout', [ManagementAuthController::class, 'logout'])->name('logout');
        Route::post('logout-all', [ManagementAuthController::class, 'logoutAll'])->name('logout-all');
        Route::post('stop-impersonation', [ImpersonationController::class, 'stop'])->name('stop-impersonation');
    });

// Management app — onboarding (authenticated, but before a business exists)
Route::middleware(['auth:sanctum', 'token.audience:management', 'team.context'])
    ->prefix('management')->name('api.management.')
    ->group(function () {
        Route::post('setup', [SetupController::class, 'store'])->name('setup');
    });

// Admin app — authenticated
Route::middleware(['auth:sanctum', 'token.audience:admin', 'team.context'])
    ->prefix('admin')->name('api.admin.')
    ->group(function () {
        Route::get('auth/me', [AdminAuthController::class, 'me'])->name('me');
        // The bridge to the session-cookie surface at /logs. `platform.admin`
        // is defence in depth; the controller's LogViewerAccess check is the
        // real gate and is the one that respects `log-viewer.allowed_roles`.
        Route::get('auth/log-viewer-link', [AdminAuthController::class, 'logViewerLink'])
            ->middleware('platform.admin')
            ->name('log-viewer-link');
        Route::post('auth/logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::post('auth/logout-all', [AdminAuthController::class, 'logoutAll'])->name('logout-all');
        Route::post('users/{user}/impersonate', [ImpersonationController::class, 'impersonate'])
            ->middleware('permission:admin.users.impersonate')
            ->name('users.impersonate');
    });

// Storefront (customer) app — authenticated
Route::middleware(['auth:sanctum_customer', 'token.audience:customer'])
    ->prefix('storefront/auth')->name('api.storefront.auth.')
    ->group(function () {
        Route::get('me', [CustomerAuthController::class, 'me'])->name('me');
        Route::post('logout', [CustomerAuthController::class, 'logout'])->name('logout');
    });
