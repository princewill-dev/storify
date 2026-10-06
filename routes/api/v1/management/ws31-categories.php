<?php

use App\Http\Controllers\Api\V1\Management\CategoryParityController;

/*
|--------------------------------------------------------------------------
| WS-31 — Categories Polish & Audit Logging
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so the "management" prefix and "api.management." name are inherited. Only
| Route:: lines belong in this file.
|
| Laravel keys routes by method+URI, so re-registering the category URIs here
| hands them to CategoryParityController, which replaces the thin base slice
| (the technique WS-20/WS-25 use). Permission middleware is unchanged from the
| declarations being replaced, and the payload is a superset of the base
| one — minus `parent_id`, which roadmap item D9 retires until the storefront
| renders child categories.
*/

Route::middleware('permission:products view')->group(function () {
    Route::get('categories', [CategoryParityController::class, 'index'])->name('categories.index');
});

Route::middleware('permission:products create')->group(function () {
    Route::post('categories', [CategoryParityController::class, 'store'])->name('categories.store');
});

Route::middleware('permission:products edit')->group(function () {
    Route::put('categories/{category}', [CategoryParityController::class, 'update'])->name('categories.update');
});

Route::middleware('permission:products delete')->group(function () {
    Route::delete('categories/{category}', [CategoryParityController::class, 'destroy'])->name('categories.destroy');
});
