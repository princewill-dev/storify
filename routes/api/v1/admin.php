<?php

use App\Http\Controllers\Api\V1\Admin\BusinessController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\SearchController;
use App\Http\Controllers\Api\V1\Admin\StoreController;
use App\Http\Controllers\Api\V1\Admin\TransactionController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin app API (platform office)
|--------------------------------------------------------------------------
| Bearer tokens with audience "admin", Spatie team context + admin permissions.
*/

Route::middleware(['auth:sanctum', 'token.audience:admin', 'team.context'])
    ->prefix('admin')
    ->name('api.admin.')
    ->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])
            ->middleware('permission:admin.dashboard')
            ->name('dashboard');

        Route::get('search', [SearchController::class, 'index'])->name('search');

        Route::middleware('permission:admin.businesses')->group(function () {
            Route::get('businesses', [BusinessController::class, 'index'])->name('businesses.index');
            Route::get('businesses/{business}', [BusinessController::class, 'show'])->name('businesses.show');
            Route::post('businesses/{business}/suspend', [BusinessController::class, 'suspend'])->name('businesses.suspend');
            Route::post('businesses/{business}/activate', [BusinessController::class, 'activate'])->name('businesses.activate');
        });

        Route::middleware('permission:admin.stores')->group(function () {
            Route::get('stores', [StoreController::class, 'index'])->name('stores.index');
            Route::get('stores/{store}', [StoreController::class, 'show'])->name('stores.show');
        });

        Route::middleware('permission:admin.users')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
            Route::post('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
            Route::post('users/{user}/verify', [UserController::class, 'verify'])->name('users.verify');
            Route::post('users/{user}/unverify', [UserController::class, 'unverify'])->name('users.unverify');
        });

        Route::middleware('permission:admin.transactions')->group(function () {
            Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
            Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
        });

        Route::middleware('permission:admin.coupons')->group(function () {
            Route::get('coupons', [CouponController::class, 'index'])->name('coupons.index');
            Route::get('coupon-plans', [CouponController::class, 'plans'])->name('coupons.plans');
            Route::post('coupons', [CouponController::class, 'store'])->name('coupons.store');
            Route::put('coupons/{coupon}', [CouponController::class, 'update'])->name('coupons.update');
            Route::post('coupons/{coupon}/toggle', [CouponController::class, 'toggle'])->name('coupons.toggle');
            Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->name('coupons.destroy');
        });
    });
