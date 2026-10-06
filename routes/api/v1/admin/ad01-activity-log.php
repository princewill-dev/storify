<?php

use App\Http\Controllers\Api\V1\Admin\ActivityLogController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-01 — Activity log & audit trail (WS1)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth/audience/team group, so
| this inherits the "admin" prefix, the "api.admin." name and the middleware.
| Only Route:: lines belong in this file.
|
| `permission:admin.activity-logs` is the modern equivalent of the legacy
| superadmin hard-check (SpatiePermissionSeeder already seeds the string).
|
| AdminApiActivityLogger rides the route itself so that viewing the log is
| itself audited (legacy parity) even before the orchestrator applies the
| middleware to the whole admin group for the dashboard/settings blind spot.
| The middleware de-dupes per request, so a later group-level application
| still writes exactly one row.
|
| `export=csv` is a representation of this same endpoint, not a second route.
*/

Route::get('activity-logs', [ActivityLogController::class, 'index'])
    ->middleware(['permission:admin.activity-logs', AdminApiActivityLogger::class])
    ->name('activity-logs.index');
