<?php

use App\Http\Controllers\Api\V1\Admin\StockTransferController;
use App\Http\Controllers\Api\V1\Admin\WarehouseController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-14 — warehouses & stock transfers (WS14)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth / audience / team group,
| so these inherit the "admin" prefix, the "api.admin." name and the
| middleware. Only Route:: lines belong in this file.
|
| `admin.warehouses` is the seeded permission the roadmap maps to both
| screens; the controllers additionally carry the platform-role guard
| (`EnsuresPlatformAdmin`) because in-business Super Admin roles are seeded
| with the `admin.*` strings too. AdminApiActivityLogger rides the routes so
| who approved, dispatched, received or cancelled a transfer is itself
| audited (it de-dupes against WS-1's group-level wiring).
|
| `Warehouse` binds by `warehouse_code` and `StockTransfer` by
| `transfer_code` (both models' route keys), matching the roadmap's key
| convention. The transition URIs are declared after the `{transfer}` read
| route for readability; static segments outscore the parameter in the
| matcher, so they can never be swallowed.
|
| The five transitions all delegate to `Management\StockTransferController`
| so the stock ledger keeps one writer; the admin-side cancel is the route
| the audit asked for (legacy's admin page posted Cancel to the management
| route — bug #13 in the admin roadmap's improve list).
*/

Route::middleware(['permission:admin.warehouses', AdminApiActivityLogger::class])->group(function () {
    Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
    Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])->name('warehouses.show');

    Route::get('transfers', [StockTransferController::class, 'index'])->name('transfers.index');
    Route::get('transfers/{transfer}', [StockTransferController::class, 'show'])->name('transfers.show');

    Route::patch('transfers/{transfer}/approve', [StockTransferController::class, 'approve'])->name('transfers.approve');
    Route::patch('transfers/{transfer}/reject', [StockTransferController::class, 'reject'])->name('transfers.reject');
    Route::patch('transfers/{transfer}/dispatch', [StockTransferController::class, 'dispatch'])->name('transfers.dispatch');
    Route::patch('transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->name('transfers.receive');
    Route::patch('transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->name('transfers.cancel');
});
