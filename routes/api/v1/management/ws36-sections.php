<?php

use App\Http\Controllers\Api\V1\Management\SectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WS-36 — Sections & Product↔Section
|--------------------------------------------------------------------------
| Registered inside the management group (prefix "management", name
| "api.management.", auth/audience/team middleware), so only the Route:: lines
| belong here.
|
| Sections are warehouse-scoped and bind by `section_code` (the model's route
| key, matching the legacy nested resource), while `{warehouse}` binds by
| `warehouse_code` like every other warehouse route.
|
| Permissions improve on legacy, which put the whole sections resource behind
| `warehouses view` — a read-only user could create and delete sections.
| Mutations now carry the matching `warehouses create|edit|delete`, and
| product assignment carries `products edit` because it writes the product.
*/

Route::middleware('permission:warehouses view')->group(function () {
    // Picker source for the product form and the assign-products modal.
    Route::get('sections', [SectionController::class, 'picker'])->name('sections.picker');

    Route::get('warehouses/{warehouse}/sections', [SectionController::class, 'index'])->name('sections.index');
    Route::get('warehouses/{warehouse}/sections/{section}', [SectionController::class, 'show'])->name('sections.show');
});

Route::middleware('permission:warehouses create')->group(function () {
    Route::post('warehouses/{warehouse}/sections', [SectionController::class, 'store'])->name('sections.store');
});

Route::middleware('permission:warehouses edit')->group(function () {
    Route::put('warehouses/{warehouse}/sections/{section}', [SectionController::class, 'update'])->name('sections.update');
});

Route::middleware('permission:products view')->group(function () {
    Route::get('warehouses/{warehouse}/sections/{section}/available-products', [SectionController::class, 'availableProducts'])
        ->name('sections.products.available');
});

Route::middleware('permission:products edit')->group(function () {
    Route::post('warehouses/{warehouse}/sections/{section}/products', [SectionController::class, 'assignProducts'])
        ->name('sections.products.assign');
    Route::delete('warehouses/{warehouse}/sections/{section}/products', [SectionController::class, 'unassignProducts'])
        ->name('sections.products.unassign');
});

Route::middleware('permission:warehouses delete')->group(function () {
    Route::delete('warehouses/{warehouse}/sections/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');
});
