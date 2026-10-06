<?php

use App\Http\Controllers\Api\V1\Management\Accounting\ReportsController;

/*
|--------------------------------------------------------------------------
| WS-24 — Accounting report exports & polish
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so the "management" prefix and "api.management." name are inherited. Only
| Route:: lines belong in this file.
|
| These nine GETs intentionally re-register the URIs the shared route file
| points at AccountingController@* reports. Feature modules are required after
| that file, so these registrations win, and ReportsController serves the
| same JSON payload plus the legacy `?export=csv` (all nine) and `?export=pdf`
| (trial balance, profit & loss, balance sheet) branches. One endpoint per
| report, as the roadmap asks, instead of a second divergent export surface.
|
| Permissions mirror the legacy route block: every read and export needs
| "accounting reports".
*/

Route::middleware('permission:accounting reports')->prefix('accounting/reports')->name('accounting.reports.')->group(function () {
    Route::get('profit-and-loss', [ReportsController::class, 'profitAndLoss'])->name('profit-and-loss');
    Route::get('balance-sheet', [ReportsController::class, 'balanceSheet'])->name('balance-sheet');
    Route::get('trial-balance', [ReportsController::class, 'trialBalance'])->name('trial-balance');
    Route::get('general-ledger/{account}', [ReportsController::class, 'generalLedger'])->name('general-ledger');
    Route::get('ar-aging', [ReportsController::class, 'arAging'])->name('ar-aging');
    Route::get('ap-aging', [ReportsController::class, 'apAging'])->name('ap-aging');
    Route::get('vat-summary', [ReportsController::class, 'vatSummary'])->name('vat-summary');
    Route::get('expense-summary', [ReportsController::class, 'expenseSummary'])->name('expense-summary');
    Route::get('integrity', [ReportsController::class, 'integrity'])->name('integrity');
});
