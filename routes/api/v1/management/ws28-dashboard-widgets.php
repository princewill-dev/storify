<?php

use App\Http\Controllers\Api\V1\Management\DashboardWidgetsController;

/*
|--------------------------------------------------------------------------
| WS-28 — dashboard widgets & store switcher
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so this inherits the "management" prefix, the "api.management." name and
| the auth middleware. Only Route:: lines belong in this file.
|
| The SPA's dashboard reads this endpoint instead of GET management/dashboard:
| that route's controller is owned by the shell workstream, so the WS-28
| payload (a strict superset — every legacy KPI, panel and the store scope)
| lives behind its own URI. `dashboard view` is the legacy dashboard gate and
| every business role holds it; the per-card permission checks happen inside
| the controller, where unpermitted sections are omitted from the payload.
|
| The store switcher is stateless: the SPA persists the choice (Pinia +
| localStorage) and sends ?store_id= on each request, which the controller
| intersects with accessibleStores() and refuses with a 403 otherwise. The
| literal URI is `dashboard/widgets`, so it never collides with the existing
| GET `dashboard` route the shell workstream owns.
*/

Route::middleware('permission:dashboard view')->group(function () {
    Route::get('dashboard/widgets', [DashboardWidgetsController::class, 'index'])->name('dashboard.widgets');

    // The trend window behind the tile sparklines and the line chart. Split
    // from the payload above because the range control parameterises it and
    // because its daily GROUP BYs should not ride on every dashboard load.
    // Same controller and same repository: the tenant scoping underneath is
    // one implementation, not two.
    Route::get('dashboard/trend', [DashboardWidgetsController::class, 'trend'])->name('dashboard.trend');
});
