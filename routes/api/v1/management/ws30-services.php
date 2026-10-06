<?php

use App\Http\Controllers\Api\V1\Management\CatalogMetaController;
use App\Http\Controllers\Api\V1\Management\ServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WS-30 — Services catalogue
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix and "api.management." name.
|
| `services/{service}` binds by `service_code` through the Service model's
| getRouteKeyName(), matching the legacy management routes and the public
| storefront contract. The catalog screens are permission-gated end to end in
| legacy (`products view|create|edit|delete`), so the new routes reuse the
| same product permissions.
|
| `meta/currencies` is the WS-30 prerequisite the roadmap called out — no
| currency list existed anywhere in the management API.
*/

Route::middleware('permission:products view')->group(function () {
    Route::get('services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
    Route::get('meta/currencies', [CatalogMetaController::class, 'currencies'])->name('meta.currencies');
});

Route::middleware('permission:products create')->group(function () {
    Route::post('services', [ServiceController::class, 'store'])->name('services.store');
});

Route::middleware('permission:products edit')->group(function () {
    Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');
});

Route::middleware('permission:products delete')->group(function () {
    Route::delete('services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
});
