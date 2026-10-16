<?php

use App\Http\Controllers\Api\V1\Management\NotificationController;
use App\Http\Controllers\Api\V1\Management\PayoutController;
use App\Http\Controllers\Api\V1\Management\WalletController;
use Illuminate\Support\Facades\Route;

// WS-49 — the wallet, the payout history and the money alerts.
// Loaded by routes/api/v1/management.php inside its management group, so the
// prefix, name prefix and auth/audience/team middleware are already applied.
//
// Numbered after the custody work streams it surfaces (WS-44…WS-48) rather than
// after the last route file: those streams own the money model and the alerts
// but no management routes of their own, so routing them into the next free
// module number would have put the API face on a file that reads as unrelated
// work.
//
// Read-only, and there is no route here that moves money. Money leaves the wallet
// from one service, entered two ways — the queued `SettlePayouts` job a collected
// sale dispatches, and the operator's retry — both through
// `InstantPayoutService::settle()` → `PayoutSweepService::sweepBusiness()`; an
// endpoint that pushed one would be a second way to move money, which is the shape
// of bug this whole subsystem exists to delete. The same reasoning is why the admin
// side of this has holds and a queue view but no "run now" — see ad12-money-config.php.

// The money itself, behind the permission the codebase already uses for money.
// The wallet is business-level, exactly like the dashboard figures and the
// transaction totals behind the same class of read.
Route::middleware('permission:transactions view')->group(function () {
    Route::get('wallet', [WalletController::class, 'show'])->name('wallet.show');

    Route::get('payouts', [PayoutController::class, 'index'])->name('payouts.index');
    Route::get('payouts/{payout}', [PayoutController::class, 'show'])->name('payouts.show');
});

// Alerts are addressed to a person, not to a role: a staff member without
// `transactions view` still has to be told that the money it is holding arrived.
// Tenancy is the scope — the controller refuses any row from another business.
Route::prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    // The whole set, in one statement.
    Route::post('read', [NotificationController::class, 'markAllRead'])->name('read-all');
    Route::post('{notification}/read', [NotificationController::class, 'markRead'])->name('read');
});
