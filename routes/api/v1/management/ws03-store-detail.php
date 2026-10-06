<?php

use App\Http\Controllers\Api\V1\Management\StoreDashboardController;

/*
|--------------------------------------------------------------------------
| WS-03 — Store detail shell & dashboard tab
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside the management group, so only
| Route:: lines belong here — the prefix, name and auth/audience/team
| middleware are inherited. Access is re-checked against the caller's
| accessible stores inside the controller.
*/

Route::middleware('permission:stores view')->group(function () {
    Route::get('stores/{store}/dashboard', [StoreDashboardController::class, 'show'])
        ->name('stores.dashboard');
});
