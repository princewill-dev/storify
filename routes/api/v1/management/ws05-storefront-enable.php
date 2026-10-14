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
// Disabling is here beside it because it is the same switch: it flips
// `has_website`, which ResolvesStorefrontContext reads to refuse every public
// storefront endpoint. It deliberately keeps the slug and the delivery route.
//
// `plan.feature:storefront` is on the two routes that switch a storefront *on*
// and deliberately not on the one that switches it off — nor on the group, and
// not as a route-name prefix. Both of those are the same decision seen twice:
// gating "off" would mean a business that lost the entitlement had a live
// storefront it could not close, and a prefix would catch
// `storefront.check-slug` and `storefront.stores.*`, which are shared reads that
// store create and the store screens call. A gate that breaks the create form
// for the very businesses it is meant to restrict is worse than no gate.
Route::middleware('permission:stores settings')->group(function () {
    Route::middleware('plan.feature:storefront')->group(function () {
        Route::post('stores/{store}/enable-website', [StorefrontController::class, 'enableWebsite'])
            ->name('storefront.enable-website');
        Route::post('stores/{store}/storefront', [StorefrontController::class, 'store'])
            ->name('storefront.store');
    });

    Route::post('stores/{store}/disable-website', [StorefrontController::class, 'disableWebsite'])
        ->name('storefront.disable-website');
});
