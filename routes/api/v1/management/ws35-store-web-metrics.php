<?php

use App\Http\Controllers\Api\V1\Management\StoreWebMetricsController;

/*
|--------------------------------------------------------------------------
| WS-35 — store web metrics
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside the management group, so only
| Route:: lines belong here — the prefix, name and auth/audience/team
| middleware are inherited.
|
| One read backs both the store detail "Web Store" tab and the standalone
| `/stores/:id/web-metrics` page. `stores view` matches the gate on every
| other store-scoped read; the controller re-checks the caller's accessible
| stores, refuses deleted stores and refuses stores without `has_website`
| (422 + a `no_website` marker) instead of the legacy redirect+flash.
*/

Route::middleware('permission:stores view')->group(function () {
    Route::get('stores/{store}/web-metrics', [StoreWebMetricsController::class, 'show'])
        ->name('stores.web-metrics');
});
