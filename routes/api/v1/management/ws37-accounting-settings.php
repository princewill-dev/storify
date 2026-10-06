<?php

use App\Http\Controllers\Api\V1\Management\Accounting\AccountingSettingsController;
use App\Http\Controllers\Api\V1\Management\Accounting\ReconciliationController;

/*
|--------------------------------------------------------------------------
| WS-37 — Accounting Settings, Closing & Reconciliation
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so the "management" prefix and "api.management." name are inherited. Only
| Route:: lines belong in this file.
|
| These URIs are new to the new stack — the shared route file never registered
| accounting/settings or accounting/reconciliation — so nothing here shadows
| an existing endpoint. Permissions mirror the legacy route block exactly:
|
|   accounting reconcile — every reconciliation read and action
|   accounting view      — the settings page read (legacy gated GET settings
|                          on "accounting view"; the year list it renders is
|                          inside the page, the close action is not)
|   accounting settings  — mappings, opening balances, period close/reopen
|   accounting close     — fiscal year close
|
| `accounting reconcile` was a seeded-but-unrouted permission in the new
| stack; these routes are where it finally guards something.
*/

// Bank reconciliation — statement import, match/unmatch/ignore, auto-match,
// completion snapshot.
Route::middleware('permission:accounting reconcile')->prefix('accounting/reconciliation')->name('accounting.reconciliation.')->group(function () {
    Route::get('/', [ReconciliationController::class, 'index'])->name('index');
    Route::post('/', [ReconciliationController::class, 'store'])->name('store');
    Route::get('{import}', [ReconciliationController::class, 'show'])->name('show');
    Route::post('{import}/auto-match', [ReconciliationController::class, 'autoMatch'])->name('auto-match');
    Route::post('{import}/complete', [ReconciliationController::class, 'complete'])->name('complete');
    Route::post('lines/{line}/match', [ReconciliationController::class, 'match'])->name('lines.match');
    Route::post('lines/{line}/unmatch', [ReconciliationController::class, 'unmatch'])->name('lines.unmatch');
    Route::post('lines/{line}/ignore', [ReconciliationController::class, 'ignore'])->name('lines.ignore');
});

// Accounting settings — mappings, opening balances, fiscal periods.
Route::middleware('permission:accounting view')->prefix('accounting')->name('accounting.')->group(function () {
    Route::get('settings', [AccountingSettingsController::class, 'index'])->name('settings.index');
});

Route::middleware('permission:accounting settings')->prefix('accounting/settings')->name('accounting.settings.')->group(function () {
    Route::put('mappings', [AccountingSettingsController::class, 'updateMappings'])->name('mappings.update');
    Route::post('opening-balances', [AccountingSettingsController::class, 'storeOpeningBalances'])->name('opening-balances.store');
    Route::post('periods/{period}/close', [AccountingSettingsController::class, 'closePeriod'])->name('periods.close');
    Route::post('periods/{period}/reopen', [AccountingSettingsController::class, 'reopenPeriod'])->name('periods.reopen');
});

// Year-end close is deliberately its own permission (legacy did the same):
// moving the net result to retained earnings and locking twelve periods is
// an owner-level act.
Route::middleware('permission:accounting close')->prefix('accounting/settings')->name('accounting.settings.')->group(function () {
    Route::post('years/{year}/close', [AccountingSettingsController::class, 'closeYear'])->whereNumber('year')->name('years.close');
});
