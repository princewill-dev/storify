<?php

use App\Http\Controllers\Api\V1\Management\InventoryOverviewController;

/*
 * WS-59 — the inventory overview.
 *
 * Auto-included by the glob in routes/api/v1/management.php, so the
 * `management` prefix, the `api.management.` name prefix and the
 * auth/audience/team/onboarding/subscription middleware are already applied.
 *
 * `products view` is the catalogue gate: stock value and the low-stock list
 * are the same data WS-29 publishes under that ability. The controller omits
 * the movement series and warehouse breakdown without `warehouses view`, and
 * the transfer pipeline without `transfers view`.
 */
Route::middleware('permission:products view')->group(function () {
    Route::get('inventory/overview', [InventoryOverviewController::class, 'index'])
        ->name('inventory.overview');
});
