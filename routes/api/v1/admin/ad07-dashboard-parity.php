<?php

use App\Http\Controllers\Api\V1\Admin\DashboardParityController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-07 — Dashboard parity completion (WS7)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth/audience/team group, so
| this inherits the "admin" prefix, the "api.admin." name and the middleware.
| Only Route:: lines belong in this file.
|
| The `dashboard` URI below is already registered by the shared
| routes/api/v1/admin.php (which this workstream must not edit) against the
| older, read-only DashboardController. Re-registering it here is how a module
| file "extends" an existing endpoint without touching the shared file: the
| route collection keys on method+URI, so this later registration replaces the
| earlier one and `route:list` keeps exactly one entry (verified with
| `php artisan route:list --path=api/v1/admin`). The old controller is left
| untouched on disk for the orchestrator to retire.
|
| `platform.admin` is the missing half of the gate: every business's in-business
| "Super Admin" role bundles the admin.* permission names, so a business-scoped
| account with a leaked admin-audience token would otherwise satisfy
| `permission:admin.dashboard` and read platform-wide figures. Platform admins
| (AdminAuthController only signs in superadmin/admin) always pass.
|
| AdminApiActivityLogger rides the route itself so dashboard views are part of
| the audit trail — the legacy blind spot WS1 set out to fix. The middleware
| de-dupes per request, so a later group-level application writes one row.
*/

Route::get('dashboard', [DashboardParityController::class, 'index'])
    ->middleware(['permission:admin.dashboard', 'platform.admin', AdminApiActivityLogger::class])
    ->name('dashboard');
