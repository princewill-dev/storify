<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Staff\DashboardController;
use App\Http\Controllers\Staff\PosController as StaffPosController;
use App\Http\Controllers\Management\PosSessionController;

Route::prefix('staff')->name('staff.')->group(function () {
    Route::middleware(['auth:web', 'team.context'])->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/password/change', [StaffPosController::class, 'showPasswordChange'])->name('password.change');
        Route::post('/password/change', [StaffPosController::class, 'updatePassword'])->name('password.change.store');

        Route::get('/pos', [StaffPosController::class, 'index'])->name('pos');
        Route::post('/pos/switch-store', [StaffPosController::class, 'switchStore'])->name('pos.switch-store');

        Route::post('/pos/{store}/session/open', [PosSessionController::class, 'open'])
            ->middleware('permission:pos open_session')
            ->name('pos.session.open');

        Route::post('/pos/{store}/session/close', [PosSessionController::class, 'close'])
            ->middleware('permission:pos close_session')
            ->name('pos.session.close');

        Route::post('/pos/{store}/product/search', [\App\Http\Controllers\Management\PosSaleController::class, 'searchProducts'])
            ->middleware('permission:pos process_sale')
            ->name('pos.product.search');

        Route::post('/pos/{store}/checkout', [\App\Http\Controllers\Management\PosSaleController::class, 'checkout'])
            ->middleware('permission:pos process_sale')
            ->name('pos.checkout');

        Route::post('/pos/{store}/refund/{order}', [\App\Http\Controllers\Management\PosSaleController::class, 'refund'])
            ->middleware('permission:pos process_sale')
            ->name('pos.refund');

        Route::get('/pos/{store}/receipt/{order}', [\App\Http\Controllers\Management\PosSaleController::class, 'receipt'])
            ->name('pos.receipt');
    });
});
