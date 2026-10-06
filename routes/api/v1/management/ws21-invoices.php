<?php

use App\Http\Controllers\Api\V1\Management\InvoiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WS-21 — Invoices module
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix, the "api.management." name and the
| auth middleware. Only Route:: lines belong in this file.
|
| Permission mapping mirrors the legacy route block: reads and the form
| pickers need "invoices view"; create "invoices create"; send/remind,
| mark-paid, record-payment and void "invoices edit"; delete "invoices
| delete". The seeded "invoices send" ability was never routed in legacy
| either.
|
| `invoices/form-options` is declared before the `invoices/{invoice}` binding
| (and the binding excludes the literal) so the static segment is never
| swallowed by the model route.
*/

Route::middleware('permission:invoices view')->group(function () {
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');

    Route::get('invoices/form-options', [InvoiceController::class, 'formOptions'])
        ->name('invoices.form-options');

    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])
        ->name('invoices.pdf');

    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
        ->where('invoice', '(?!form-options(/|$))[^/]+')
        ->name('invoices.show');
});

Route::middleware('permission:invoices create')->group(function () {
    Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
});

Route::middleware('permission:invoices edit')->group(function () {
    Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
    Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
    Route::post('invoices/{invoice}/record-payment', [InvoiceController::class, 'recordPayment'])->name('invoices.record-payment');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
});

Route::middleware('permission:invoices delete')->group(function () {
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
});
