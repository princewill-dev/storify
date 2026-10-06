<?php

use App\Http\Controllers\Api\V1\Admin\SettingsController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| Admin WS2 — Platform settings & branding
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth / audience / team group,
| so these inherit the "admin" prefix and the "api.admin." name.
|
| One screen saves every field, so one multipart PUT covers the whole form:
| uploads replace the previous file, and `default_currency_id` flips the
| single `currencies.is_default` flag inside the same transaction.
|
| The admin SPA sends this as POST with `_method=PUT` in the FormData: PHP
| does not parse multipart bodies for real PUT requests, and Laravel's method
| spoofing routes it here (see src/api/modules/ad02-platform-settings.ts).
|
| AdminApiActivityLogger rides these routes so visiting and changing the
| platform settings screen is itself audited — the legacy blind spot WS-1
| set out to close. The middleware de-dupes per request, so the group-level
| wiring planned for routes/api/v1/admin.php still writes exactly one row.
*/

Route::middleware(['permission:admin.settings', AdminApiActivityLogger::class])->group(function () {
    Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
});
