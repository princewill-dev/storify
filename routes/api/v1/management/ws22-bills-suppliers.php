<?php

use App\Http\Controllers\Api\V1\Management\Accounting\BillController;
use App\Http\Controllers\Api\V1\Management\Accounting\SupplierController;

/*
|--------------------------------------------------------------------------
| WS-22 — Accounting: Suppliers & Bills
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so the "management" prefix and "api.management." name are inherited.
| Only Route:: lines belong in this file.
|
| GET accounting/suppliers and GET accounting/bills re-register the URIs the
| shared route file points at AccountingController; feature modules load after
| that file, so these registrations win and the legacy search / outstanding /
| stats payloads land on the endpoints the SPA already calls.
|
| Permissions mirror the legacy route file: reads need "accounting view";
| supplier writes need "accounting suppliers"; bill writes — including the
| form's option payload — need "accounting bills".
*/

Route::middleware('permission:accounting view')->prefix('accounting')->name('accounting.')->group(function () {
    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
    Route::get('bills', [BillController::class, 'index'])->name('bills.index');
    Route::get('bills/{bill}', [BillController::class, 'show'])->name('bills.show');
});

Route::middleware('permission:accounting suppliers')->prefix('accounting')->name('accounting.')->group(function () {
    Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
});

Route::middleware('permission:accounting bills')->prefix('accounting')->name('accounting.')->group(function () {
    Route::get('bill-options', [BillController::class, 'options'])->name('bills.options');
    Route::post('bills', [BillController::class, 'store'])->name('bills.store');
    Route::post('bills/{bill}/payments', [BillController::class, 'storePayment'])->name('bills.payments.store');
    Route::post('bills/{bill}/void', [BillController::class, 'void'])->name('bills.void');
});
