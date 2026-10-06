<?php

use App\Http\Controllers\Api\V1\Management\Accounting\ExpenseController;

/*
|--------------------------------------------------------------------------
| WS-16 — Accounting: Expenses
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so the "management" prefix and "api.management." name are inherited.
| Only Route:: lines belong in this file.
|
| GET accounting/expenses re-registers the URI the shared route file points
| at AccountingController@expenses. Feature modules load after that file, so
| this registration wins and the legacy stats/category/date filters land on
| the endpoint the SPA already calls — no second list endpoint to keep in
| sync. The remaining routes are new.
|
| Permissions mirror the legacy route file: reads need "accounting view",
| the mutating expense routes need "accounting expenses".
*/

Route::middleware('permission:accounting view')->prefix('accounting')->name('accounting.')->group(function () {
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('expense-options', [ExpenseController::class, 'options'])->name('expenses.options');
    Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
});

Route::middleware('permission:accounting expenses')->prefix('accounting')->name('accounting.')->group(function () {
    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->name('expenses.void');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
});
