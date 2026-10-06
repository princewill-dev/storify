<?php

use App\Http\Controllers\Api\V1\Management\StockMovementController;
use App\Http\Controllers\Api\V1\Management\StockVisibilityController;

/*
|--------------------------------------------------------------------------
| WS-29 — stock visibility & the low-stock model
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix, the "api.management." name and the
| auth middleware. Only Route:: lines belong in this file.
|
| Reads split by ability: the low-stock list and dashboard summary are
| catalogue-visible ("products view" — the legacy dashboard card was shown to
| anyone who could see products), while the per-location ledger and movement
| history are warehouse business ("warehouses view"). The min-level writes are
| the first things ever to set `StockLocation.min_quantity` (legacy hard-coded
| it to 0 on every write), so they take the warehouse edit ability.
|
| `stock-locations/{stockLocation}` (PATCH) sits beside WS-15's
| `stock-locations` (GET) without touching it — Laravel keys routes by
| method + URI.
*/

Route::middleware('permission:products view')->group(function () {
    Route::get('stock/summary', [StockVisibilityController::class, 'summary'])
        ->name('stock.summary');

    Route::get('stock/low-stock', [StockVisibilityController::class, 'lowStock'])
        ->name('stock.low-stock');
});

Route::middleware('permission:warehouses view')->group(function () {
    Route::get('stock-levels', [StockVisibilityController::class, 'levels'])
        ->name('stock-levels.index');

    Route::get('stock-movements', [StockMovementController::class, 'index'])
        ->name('stock-movements.index');
});

Route::middleware('permission:warehouses edit')->group(function () {
    Route::put('stock-levels/min-levels', [StockVisibilityController::class, 'bulkUpdateMinLevels'])
        ->name('stock-levels.min-levels');

    Route::patch('stock-locations/{stockLocation}', [StockVisibilityController::class, 'updateMinLevel'])
        ->name('stock-locations.update');
});
