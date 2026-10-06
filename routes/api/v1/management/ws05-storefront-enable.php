<?php

use App\Http\Controllers\Api\V1\Management\StorefrontController;

/*
| WS-05 — Storefront enablement.
|
| Loaded by routes/api/v1/management.php, so the "management" prefix, the
| api.management.* name and the auth/audience/team middleware are inherited.
*/

// No permission gate, matching legacy: the slug check backs store create
// (WS-03) as well as the enable/wizard forms, and runs before a store exists.
// The contract is {data: {available, slug, url, original}}.
Route::post('stores/check-slug', [StorefrontController::class, 'checkSlug'])
    ->name('storefront.check-slug');

Route::middleware('permission:stores view')->group(function () {
    Route::get('storefront/stores', [StorefrontController::class, 'index'])
        ->name('storefront.stores.index');
    Route::get('storefront/stores/{store}', [StorefrontController::class, 'show'])
        ->name('storefront.stores.show');
});

// Enabling writes the store's slug and the nationwide delivery route the
// storefront checkout charges, so it carries the permission legacy required.
Route::middleware('permission:stores settings')->group(function () {
    Route::post('stores/{store}/enable-website', [StorefrontController::class, 'enableWebsite'])
        ->name('storefront.enable-website');
    Route::post('stores/{store}/storefront', [StorefrontController::class, 'store'])
        ->name('storefront.store');
});
