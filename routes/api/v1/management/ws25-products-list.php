<?php

use App\Http\Controllers\Api\V1\Management\ProductListController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WS-25 — Products List, Bulk Actions & Detail
|--------------------------------------------------------------------------
| Registered inside the management group (prefix "management", name
| "api.management.", auth/audience/team middleware), so only the Route:: lines
| belong here.
|
| `GET products` deliberately reuses the URI declared further up this group and
| in the WS-14 module: Laravel keys routes by method+URI, so this registration
| replaces the earlier list handler with the WS-25 one (legacy's created-at
| range, the 10/50/100 per-page whitelist, low-stock/section/has-variant
| filters). The row payload is a superset of the WS-14 list payload, so the
| other consumers of this endpoint keep working. show/store/update/status and
| destroy stay with ProductFormController.
|
| The bulk URIs are POSTs and cannot collide with the `products/{product}`
| binding (which is only registered for GET/PUT/DELETE), so the "bulk-*"
| literal segment is never read as a product code.
*/

Route::middleware('permission:products view')->group(function () {
    Route::get('products', [ProductListController::class, 'index'])->name('products.index');
});

Route::middleware('permission:products edit')->group(function () {
    Route::post('products/bulk-update', [ProductListController::class, 'bulkUpdate'])->name('products.bulk-update');
    Route::post('products/bulk-status', [ProductListController::class, 'bulkStatus'])->name('products.bulk-status');
});

Route::middleware('permission:products delete')->group(function () {
    Route::post('products/bulk-delete', [ProductListController::class, 'bulkDelete'])->name('products.bulk-delete');
});
