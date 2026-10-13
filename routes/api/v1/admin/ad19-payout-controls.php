<?php

use App\Http\Controllers\Api\V1\Admin\BusinessWalletController;
use App\Http\Controllers\Api\V1\Admin\PayoutHoldController;
use App\Http\Controllers\Api\V1\Admin\PayoutQueueController;
use App\Http\Middleware\AdminApiActivityLogger;
use App\Http\Middleware\ForceJsonResponse;

/*
|--------------------------------------------------------------------------
| AD-19 — Payout controls: the queue, a business's wallet, and the holds that stop it
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth / audience / team group,
| so the "admin" prefix, the "api.admin." name and the group middleware are
| inherited. Only Route:: lines belong in this file.
|
| `permission:admin.finance` gates all three, the permission that already governs
| the money screens, and all three controllers also call EnsuresPlatformAdmin because
| every business's in-business "Super Admin" role bundles the admin.* permission
| names — a leaked admin-audience token from a business account would otherwise
| pass the gate and could then stop every payout on the platform.
|
| AdminApiActivityLogger rides these routes so a hold is audited twice over: once
| here as an admin API action, and once from inside PayoutHoldService as a
| domain event carrying the reason and the subject. The duplication is deliberate
| — the domain row survives a change of transport, and the request row survives a
| change of service. The two reads carry it for the same reason the middleware
| exists at all: which admin looked at which business's money is part of the trail,
| and a logged write beside an unlogged read would be half a story.
|
| There is no route that pays anybody. See the note in ad12-money-config.php.
*/

Route::middleware([ForceJsonResponse::class, 'permission:admin.finance', AdminApiActivityLogger::class])->group(function () {
    // The platform's whole payout position. Read-only.
    Route::get('payout-queue', [PayoutQueueController::class, 'index'])->name('payout-queue.index');

    // One business's money, for the console an operator opens from the queue or
    // from the business list. Read-only, and the same block the business sees on
    // its own wallet page — the two disagreeing during a support call would be
    // worse than either being wrong alone.
    //
    // It sits under `admin.finance` with the rest of the money surface rather
    // than under `admin.businesses` with the console that opens it: every figure
    // in it is a payout figure, and a platform admin holds the whole bundle
    // anyway.
    Route::get('businesses/{business}/wallet', [BusinessWalletController::class, 'show'])->name('businesses.wallet');

    Route::get('payout-holds', [PayoutHoldController::class, 'index'])->name('payout-holds.index');
    Route::post('payout-holds', [PayoutHoldController::class, 'store'])->name('payout-holds.store');
    // Lifting a hold is a `delete` on the hold itself: it ends the state the row
    // represents, and re-issuing it is not idempotent in the way a `put` implies
    // (there is no hold to put back until somebody places another).
    Route::delete('payout-holds/{payoutHold}', [PayoutHoldController::class, 'destroy'])->name('payout-holds.destroy');
});
