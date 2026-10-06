<?php

use App\Http\Controllers\Api\V1\Admin\OrderController;
use App\Http\Controllers\Api\V1\Admin\Shop4meOrderController;
use App\Http\Controllers\Api\V1\Admin\TransactionStatusController;

/*
|--------------------------------------------------------------------------
| AD-05 — Platform orders oversight (WS5)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth / audience / team group,
| so the "admin" prefix, the "api.admin." name and the middleware are
| inherited. Only Route:: lines belong in this file.
|
| The orders/{order} binding is by order_number (Order::getRouteKeyName).
| Soft-deleted orders fall outside implicit binding, matching legacy: once
| deleted they leave both lists and 404 on detail while the row stays for
| refunds/audit.
|
| transaction status override rides `admin.transactions`, the same permission
| the existing transaction index/show use; everything else is `admin.orders`.
| The platform-role guard inside each controller is what keeps a
| business-scoped account with a leaked admin-audience token out.
*/

Route::middleware('permission:admin.orders')->group(function () {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::put('orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
    Route::patch('orders/{order}/payment-status', [OrderController::class, 'updatePaymentStatus'])->name('orders.update-payment-status');
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');

    // The Shop4Me queue is the same payload narrowed to source=shop4me; the
    // main list also accepts ?source=shop4me for callers that prefer one URL.
    Route::get('shop4me-orders', [Shop4meOrderController::class, 'index'])->name('shop4me-orders.index');
});

Route::patch('transactions/{transaction}/status', [TransactionStatusController::class, 'update'])
    ->middleware('permission:admin.transactions')
    ->name('transactions.update-status');
