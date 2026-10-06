<?php

use App\Http\Controllers\Api\V1\Management\Accounting\AccountingDashboardController;
use App\Http\Controllers\Api\V1\Management\Accounting\ChartOfAccountsController;
use App\Http\Controllers\Api\V1\Management\Accounting\JournalController;

/*
|--------------------------------------------------------------------------
| WS-23 — Chart of Accounts & Journal Workflows
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so the "management" prefix and "api.management." name are inherited.
| Only Route:: lines belong in this file.
|
| Laravel keys routes by method+URI and feature modules load after the shared
| route file, so re-registering `accounting/dashboard`, `accounting/accounts`
| and `accounting/journal(/{entry})` hands those URIs to the controllers this
| workstream rebuilt (the same technique WS-16/WS-17/WS-20 use). The remaining
| routes are new.
|
| Permissions mirror the legacy route file: reads need "accounting view",
| account writes need "accounting accounts", journal writes need
| "accounting journal".
|
| Journal amounts are integer kobo (`lines.*.debit_kobo` / `credit_kobo`) —
| the SPA converts what the user types once, at the edge.
*/

Route::middleware('permission:accounting view')->prefix('accounting')->name('accounting.')->group(function () {
    Route::get('dashboard', [AccountingDashboardController::class, 'index'])->name('dashboard');
    Route::get('accounts', [ChartOfAccountsController::class, 'index'])->name('accounts.index');
    Route::get('journal', [JournalController::class, 'index'])->name('journal.index');
    Route::get('journal/{entry}', [JournalController::class, 'show'])->name('journal.show');
});

Route::middleware('permission:accounting accounts')->prefix('accounting')->name('accounting.')->group(function () {
    Route::post('accounts', [ChartOfAccountsController::class, 'store'])->name('accounts.store');
    Route::put('accounts/{account}', [ChartOfAccountsController::class, 'update'])->name('accounts.update');
    Route::post('accounts/{account}/toggle', [ChartOfAccountsController::class, 'toggle'])->name('accounts.toggle');
});

Route::middleware('permission:accounting journal')->prefix('accounting')->name('accounting.')->group(function () {
    Route::post('journal', [JournalController::class, 'store'])->name('journal.store');
    Route::post('journal/{entry}/post', [JournalController::class, 'postDraft'])->name('journal.post');
    Route::post('journal/{entry}/reverse', [JournalController::class, 'reverse'])->name('journal.reverse');
    Route::delete('journal/{entry}', [JournalController::class, 'destroy'])->name('journal.destroy');
});
