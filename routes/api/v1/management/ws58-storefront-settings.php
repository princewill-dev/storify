<?php

use App\Http\Controllers\Api\V1\Management\StorefrontPageController;
use App\Http\Controllers\Api\V1\Management\StorefrontSettingsController;

/*
| WS-58 — Storefront settings.
|
| Loaded by routes/api/v1/management.php, so the "management" prefix, the
| api.management.* name and the auth/audience/team middleware are inherited.
|
| The read is ungated by plan. A business that lost the storefront entitlement
| must still be able to see what its shop is serving, and the public endpoints
| keep serving the pages either way — the same reasoning ws05 applies to the
| storefront off switch.
|
| Writes are gated: configuring metadata or pages only makes sense while the
| storefront is part of the plan, and the tab itself routes to the upgrade
| screen without it.
*/

Route::middleware('permission:stores settings')->group(function () {
    Route::get('stores/{store}/storefront-settings', [StorefrontSettingsController::class, 'show'])
        ->name('storefront-settings.show');

    Route::middleware('plan.feature:storefront')->group(function () {
        Route::put('stores/{store}/storefront-settings', [StorefrontSettingsController::class, 'update'])
            ->name('storefront-settings.update');

        Route::post('stores/{store}/storefront-pages', [StorefrontPageController::class, 'store'])
            ->name('storefront-pages.store');

        Route::put('stores/{store}/storefront-pages/{page}', [StorefrontPageController::class, 'update'])
            ->where('page', '[0-9]+')
            ->name('storefront-pages.update');

        Route::delete('stores/{store}/storefront-pages/{page}', [StorefrontPageController::class, 'destroy'])
            ->where('page', '[0-9]+')
            ->name('storefront-pages.destroy');
    });

    Route::get('stores/{store}/storefront-pages/{page}', [StorefrontPageController::class, 'show'])
        ->where('page', '[0-9]+')
        ->name('storefront-pages.show');
});
