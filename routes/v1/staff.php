<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Staff\DashboardController;
use App\Http\Controllers\Staff\PosController as StaffPosController;
use App\Http\Controllers\Management\PosSessionController;
use App\Http\Controllers\Auth\BusinessAuthController;

// ── POS Login (public) ──────────────────────────────────────────
Route::get('/pos/login', fn() => view('staff.auth.pos-login'))->name('pos.login');
Route::post('/pos/login', [BusinessAuthController::class, 'login'])->name('pos.login.store');

// ── POS Terminal (authenticated staff) ──────────────────────────
Route::prefix('pos')->name('pos.')->middleware(['auth:web', 'team.context'])->group(function () {
    Route::get('/', [StaffPosController::class, 'index'])->name('index');
    Route::get('/no-store', fn() => view('staff.pos.no-store'))->name('no-store');
    Route::post('/switch-store', [StaffPosController::class, 'switchStore'])->name('switch-store');

    Route::post('/{store}/session/open', [PosSessionController::class, 'open'])
        ->middleware('permission:pos open_session')
        ->name('session.open');

    Route::post('/{store}/session/close', [PosSessionController::class, 'close'])
        ->middleware('permission:pos close_session')
        ->name('session.close');

    Route::post('/{store}/product/search', [\App\Http\Controllers\Management\PosSaleController::class, 'searchProducts'])
        ->middleware('permission:pos process_sale')
        ->name('product.search');

    Route::post('/{store}/checkout', [\App\Http\Controllers\Management\PosSaleController::class, 'checkout'])
        ->middleware('permission:pos process_sale')
        ->name('checkout');

    Route::post('/{store}/refund/{order}', [\App\Http\Controllers\Management\PosSaleController::class, 'refund'])
        ->middleware('permission:pos process_sale')
        ->name('refund');

    Route::get('/{store}/receipt/{order}', [\App\Http\Controllers\Management\PosSaleController::class, 'receipt'])
        ->name('receipt');
});

// ── Legacy staff routes (redirect to new POS or keep for staff dashboard) ──
Route::prefix('staff')->name('staff.')->group(function () {
    Route::middleware(['auth:web', 'team.context'])->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/password/change', [StaffPosController::class, 'showPasswordChange'])->name('password.change');
        Route::post('/password/change', [StaffPosController::class, 'updatePassword'])->name('password.change.store');

        // Redirect old POS URLs to new /pos
        Route::get('/pos', fn() => redirect()->route('pos.index'))->name('pos');
        Route::get('/pos/{store}/receipt/{order}', fn (\App\Models\Store $store, \App\Models\Order $order) => redirect()->route('pos.receipt', ['store' => $store, 'order' => $order]))->name('pos.receipt');
    });
});
