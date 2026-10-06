<?php

use App\Http\Controllers\Storefront\InvoicePaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public invoice payment page
|--------------------------------------------------------------------------
| The only customer-facing HTML this app still serves. An invoice is emailed
| with a tokenised link; the recipient has no account and no session, so the
| token in the URL is the credential. Everything else the storefront used to
| serve — catalog, cart, checkout, order tracking, digital downloads — now
| belongs to the storefront SPA.
*/

Route::prefix('pay/invoice/{token}')->name('invoice.pay.')->group(function () {
    Route::get('/', [InvoicePaymentController::class, 'show'])->name('show');
    Route::post('/initialize', [InvoicePaymentController::class, 'initialize'])->name('initialize');
    Route::get('/callback', [InvoicePaymentController::class, 'callback'])->name('callback');
    Route::get('/success', [InvoicePaymentController::class, 'success'])->name('success');
    Route::post('/bank-transfer', [InvoicePaymentController::class, 'bankTransfer'])->name('bank-transfer');
});
