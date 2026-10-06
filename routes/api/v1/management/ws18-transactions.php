<?php

use App\Http\Controllers\Api\V1\Management\TransactionParityController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WS-18 — Transactions parity & payment emails
|--------------------------------------------------------------------------
| Registered inside the management group (prefix "management", name
| "api.management.", auth/audience/team middleware), so only the Route:: lines
| belong here.
|
| index/show/confirm/reject/refund deliberately reuse the URIs declared further
| up routes/api/v1/management.php: Laravel keys routes by method+URI, so these
| registrations replace the thin TransactionController versions with the full
| parity implementation (filters, detail read model, store scoping, mails).
|
| `transactions/pending/count` is three segments so it never meets the
| `transactions/{transaction}` binding, and `transactions/export` is kept
| reachable by excluding the static segment from that binding's pattern — the
| base registration already occupied the binding's slot before this module
| loaded, and first-registered-wins decides matching.
*/

Route::middleware('permission:transactions view')->group(function () {
    Route::get('transactions', [TransactionParityController::class, 'index'])->name('transactions.index');

    Route::get('transactions/pending/count', [TransactionParityController::class, 'pendingCount'])
        ->name('transactions.pending-count');

    Route::get('transactions/export', [TransactionParityController::class, 'export'])
        ->middleware('permission:transactions export')
        ->name('transactions.export');

    Route::get('transactions/{transaction}', [TransactionParityController::class, 'show'])
        ->where('transaction', '(?!export(/|$))[^/]+')
        ->name('transactions.show');
});

Route::middleware('permission:transactions confirm')->group(function () {
    Route::post('transactions/{transaction}/confirm', [TransactionParityController::class, 'confirm'])
        ->name('transactions.confirm');
});

Route::middleware('permission:transactions reject')->group(function () {
    Route::post('transactions/{transaction}/reject', [TransactionParityController::class, 'reject'])
        ->name('transactions.reject');
});

Route::middleware('permission:transactions refund')->group(function () {
    Route::post('transactions/{transaction}/refund', [TransactionParityController::class, 'refund'])
        ->name('transactions.refund');
});
