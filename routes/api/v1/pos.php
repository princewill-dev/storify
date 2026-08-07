<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Pos\AuthController;
use App\Http\Controllers\Api\V1\Pos\SessionController;
use App\Http\Controllers\Api\V1\Pos\ProductController;
use App\Http\Controllers\Api\V1\Pos\SaleController;
use App\Http\Controllers\Api\V1\Pos\CustomerController;
use App\Http\Controllers\Api\V1\Pos\TransactionController;
use App\Http\Controllers\Api\V1\Pos\BankController;

Route::prefix('pos')->group(function () {

    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'team.context'])->group(function () {

        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/switch-store', [AuthController::class, 'switchStore']);
        Route::post('/verify-pin', [AuthController::class, 'verifyPin']);

        Route::prefix('stores/{store}')->group(function () {

            Route::get('/session', [SessionController::class, 'status']);
            Route::post('/session/open', [SessionController::class, 'open'])->middleware('permission:pos open_session');
            Route::post('/session/close', [SessionController::class, 'close'])->middleware('permission:pos close_session');

            Route::get('/products', [ProductController::class, 'search']);

            Route::get('/banks', [BankController::class, 'index']);

            Route::post('/checkout', [SaleController::class, 'checkout']);
            Route::get('/orders', [SaleController::class, 'history']);
            Route::get('/orders/{orderId}/receipt', [SaleController::class, 'receipt']);
            Route::post('/orders/{orderId}/refund', [SaleController::class, 'refund']);

            Route::get('/customers', [CustomerController::class, 'search']);
            Route::get('/customers/{customerId}', [CustomerController::class, 'show']);

            Route::get('/transactions', [TransactionController::class, 'index']);
            Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
        });
    });
});
