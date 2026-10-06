<?php

use App\Http\Controllers\Api\V1\Management\StockTransferController;

/*
|--------------------------------------------------------------------------
| WS-15 — inventory transfers (the legacy "Stock Adjustment" workflow)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix, the "api.management." name and the
| auth middleware. Only Route:: lines belong in this file.
|
| Permissions mirror the legacy route block exactly: the read surfaces and the
| create-side transitions (submit / cancel / acknowledge) use "transfers view"
| and "transfers create"; approve / reject use "transfers approve"; dispatch
| and receive have their own abilities. The literal `transfers/locations`,
| `transfers/source-products` and `transfers/{transfer}/...` URIs are declared
| before the `transfers/{transfer}` binding so they are never swallowed by it.
*/

Route::middleware('permission:transfers view')->group(function () {
    Route::get('transfers/locations', [StockTransferController::class, 'locations'])
        ->name('transfers.locations');
    Route::get('transfers/source-products', [StockTransferController::class, 'sourceProducts'])
        ->name('transfers.source-products');

    // The roadmap's stock-location read; also accepts location_type/location_id.
    Route::get('stock-locations', [StockTransferController::class, 'stockLocations'])
        ->name('stock-locations.index');

    Route::get('transfers', [StockTransferController::class, 'index'])->name('transfers.index');
    Route::get('transfers/{transfer}', [StockTransferController::class, 'show'])->name('transfers.show');
});

Route::middleware('permission:transfers create')->group(function () {
    Route::post('transfers', [StockTransferController::class, 'store'])->name('transfers.store');
    Route::patch('transfers/{transfer}/submit', [StockTransferController::class, 'submit'])->name('transfers.submit');
    Route::patch('transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->name('transfers.cancel');
    Route::patch('transfers/{transfer}/acknowledge', [StockTransferController::class, 'acknowledge'])->name('transfers.acknowledge');
});

Route::middleware('permission:transfers approve')->group(function () {
    Route::patch('transfers/{transfer}/approve', [StockTransferController::class, 'approve'])->name('transfers.approve');
    Route::patch('transfers/{transfer}/reject', [StockTransferController::class, 'reject'])->name('transfers.reject');
});

Route::middleware('permission:transfers dispatch')->group(function () {
    Route::patch('transfers/{transfer}/dispatch', [StockTransferController::class, 'dispatch'])->name('transfers.dispatch');
});

Route::middleware('permission:transfers receive')->group(function () {
    Route::patch('transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->name('transfers.receive');
});
