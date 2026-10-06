<?php

use App\Http\Controllers\Api\V1\Management\OrderParityController;

/*
|--------------------------------------------------------------------------
| WS-13 — Orders list & detail parity
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so the "management" prefix and "api.management." name are inherited.
|
| The board endpoints carry a second literal segment on purpose: the shared
| route file registers orders/{order} before feature modules load, so a
| one-segment orders/stats would be captured by the order binding and 404 on
| an unknown order number. orders/board/* and orders/{order}/detail|edit are
| distinct from every base orders route.
*/

Route::middleware('permission:orders view')->group(function () {
    Route::get('orders/board/list', [OrderParityController::class, 'index'])->name('orders.board.list');
    Route::get('orders/board/stats', [OrderParityController::class, 'stats'])->name('orders.board.stats');
    Route::get('orders/{order}/detail', [OrderParityController::class, 'show'])->name('orders.detail');
});

Route::middleware('permission:orders edit')->group(function () {
    Route::get('orders/{order}/edit', [OrderParityController::class, 'edit'])->name('orders.edit');
    Route::put('orders/{order}', [OrderParityController::class, 'update'])->name('orders.update');
});
