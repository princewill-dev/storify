<?php

use App\Http\Controllers\Api\V1\Admin\AccountingController;

/*
|--------------------------------------------------------------------------
| AD-13 — Platform accounting (WS13)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth/audience/team group, so
| this inherits the "admin" prefix, the "api.admin." name and the middleware.
| Only Route:: lines belong in this file.
|
| `permission:admin.accounting` is the seeded platform-accounting string and
| rides every route, read and write. The controller adds the platform-role
| check business-scoped Super Admins need to clear.
|
| `accounting/journal/export` is declared before `accounting/journal/{entry}`
| so the CSV representation is never captured by the model binding. It is the
| same filtered row set as the journal list, serialized as CSV.
*/

Route::middleware('permission:admin.accounting')->group(function () {
    Route::get('accounting', [AccountingController::class, 'index'])
        ->name('accounting.index');

    Route::get('accounting/accounts', [AccountingController::class, 'accounts'])
        ->name('accounting.accounts');

    Route::get('accounting/journal', [AccountingController::class, 'journal'])
        ->name('accounting.journal');
    Route::get('accounting/journal/export', [AccountingController::class, 'journalExport'])
        ->name('accounting.journal.export');
    Route::get('accounting/journal/{entry}', [AccountingController::class, 'journalShow'])
        ->name('accounting.journal.show');

    Route::get('accounting/reports', [AccountingController::class, 'reports'])
        ->name('accounting.reports');

    Route::get('accounting/settings', [AccountingController::class, 'settings'])
        ->name('accounting.settings');
    Route::put('accounting/settings/mappings', [AccountingController::class, 'updateMappings'])
        ->name('accounting.settings.mappings.update');
    Route::post('accounting/settings/periods/{period}/close', [AccountingController::class, 'closePeriod'])
        ->name('accounting.settings.periods.close');
    Route::post('accounting/settings/periods/{period}/reopen', [AccountingController::class, 'reopenPeriod'])
        ->name('accounting.settings.periods.reopen');
    Route::post('accounting/settings/years/{year}/close', [AccountingController::class, 'closeYear'])
        ->name('accounting.settings.years.close');
});
