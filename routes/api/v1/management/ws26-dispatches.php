<?php

use App\Http\Controllers\Api\V1\Management\DispatchController;

/*
|--------------------------------------------------------------------------
| WS-26 — Dispatches board
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so this inherits the "management" prefix, the "api.management." name and the
| auth middleware. Only Route:: lines belong in this file.
|
| The legacy board (`management.dispatches.index`) was gated on `orders view`
| — the same permission its Orders deeplink needs — and stayed read-only: the
| only route was index. This module reproduces exactly that surface.
*/

Route::middleware('permission:orders view')->group(function () {
    Route::get('dispatches', [DispatchController::class, 'index'])->name('dispatches.index');
});
