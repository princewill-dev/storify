<?php

use App\Http\Controllers\Api\V1\Admin\BusinessWalletController;
use App\Http\Controllers\Api\V1\Admin\PayoutHoldController;
use App\Http\Controllers\Api\V1\Admin\PayoutQueueController;
use App\Http\Controllers\Api\V1\Admin\PayoutRetryController;
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
| **No route here pays anybody**, and `payouts/retry` does not break that rule: it
| cannot choose an amount, a destination or a moment, and dispatches the same
| `SettlePayouts` job a collected sale dispatches, which sends only what the wallet
| already says is owed to the account the business already named. The amended rule
| and the reasoning are in ad12-money-config.php.
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

    // The operator's retry, for a business the job has stopped trying to pay.
    //
    // `POST` rather than `PUT`: this is not idempotent in the way a put implies —
    // each press is a fresh attempt at the provider, and pressing twice legitimately
    // means "I looked, and it still fails". It is deliberately *not* a route that
    // pays anybody, which is the rule the rest of this file is built on: it clears
    // the platform's record of refusals and then dispatches the same `SettlePayouts`
    // job a collected sale dispatches, which sends what the wallet already says is
    // owed to the account the business already named. See PayoutRetryService and the
    // amended rule in ad12-money-config.php.
    Route::post('payouts/retry', [PayoutRetryController::class, 'retryAll'])->name('payouts.retry-all');

    // The same, scoped to one business, for the operator who has just fixed
    // something specific — a bank that was wrong, a provider that has recovered.
    // Nested under the business because that is what it acts on, and it sits beside
    // the wallet read above so the console can offer both from one screen.
    Route::post('businesses/{business}/payouts/retry', [PayoutRetryController::class, 'retryBusiness'])
        ->name('businesses.payouts.retry');

    Route::get('payout-holds', [PayoutHoldController::class, 'index'])->name('payout-holds.index');
    Route::post('payout-holds', [PayoutHoldController::class, 'store'])->name('payout-holds.store');
    // Lifting a hold is a `delete` on the hold itself: it ends the state the row
    // represents, and re-issuing it is not idempotent in the way a `put` implies
    // (there is no hold to put back until somebody places another).
    Route::delete('payout-holds/{payoutHold}', [PayoutHoldController::class, 'destroy'])->name('payout-holds.destroy');
});
