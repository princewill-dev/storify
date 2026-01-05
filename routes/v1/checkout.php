<?php

use App\Http\Controllers\Checkout\CheckoutController;
use App\Http\Controllers\Payment\PaystackController;
use Illuminate\Support\Facades\Route;



Route::post('/{store_slug}/checkout/save-address', [CheckoutController::class, 'saveAddress'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+'])
    ->name('checkout.save-address');



Route::post('/{store_slug}/checkout/live-first', [CheckoutController::class, 'processLiveFirst'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+'])
    ->name('checkout.live-first');

// Payment Method Selection
Route::get('/{store_slug}/checkout/{order}/payment-methods', [CheckoutController::class, 'showPaymentMethods'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+'])
    ->name('checkout.payment-methods');

Route::post('/{store_slug}/checkout/{order}/payment-methods', [CheckoutController::class, 'selectPaymentMethod'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+'])
    ->name('checkout.payment-methods.select');

// Payment Confirmation
Route::post('/{store_slug}/checkout/{order}/confirm-payment', [CheckoutController::class, 'confirmPayment'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+'])
    ->name('checkout.confirm-payment');

Route::get('/{store_slug}/checkout/payment/{order}', [CheckoutController::class, 'payment'])
    ->where(['store_slug' => '[A-Za-z0-9_\-]+'])
    ->name('checkout.payment');

// Paystack Payment Routes
Route::prefix('payment/paystack')->name('payment.paystack.')->group(function () {
    // Initialize payment (can be called from checkout)
    Route::post('/initialize', [PaystackController::class, 'initialize'])->name('initialize');
    
    // Payment callback (after payment on Paystack)
    Route::get('/callback', [PaystackController::class, 'callback'])->name('callback');
    
    // Webhook for Paystack events
    Route::post('/webhook', [PaystackController::class, 'webhook'])->name('webhook');
});

// Bank Transfer Payment Routes
Route::get('/{store_slug}/checkout/{order}/bank-transfer', [\App\Http\Controllers\Payment\BankTransferController::class, 'show'])->name('payment.bank-transfer');
Route::post('/{store_slug}/checkout/{order}/bank-transfer/confirm', [\App\Http\Controllers\Payment\BankTransferController::class, 'confirmPayment'])->name('payment.bank-transfer.confirm');

// Order Payment Status Pages (using transaction reference)
Route::get('/payment/{reference}/success', function($reference) {
    $transaction = \App\Models\Transaction::where('reference', $reference)->firstOrFail();
    $order = $transaction->order;
    return view('payment.success', compact('order', 'transaction'));
})->name('order.success');

Route::get('/payment/{reference}/failed', function($reference) {
    $transaction = \App\Models\Transaction::where('reference', $reference)->firstOrFail();
    $order = $transaction->order;
    return view('payment.failed', compact('order', 'transaction'));
})->name('order.failed');

Route::get('/{store_slug}/checkout/{order}/payment-pending', [\App\Http\Controllers\Payment\BankTransferController::class, 'pending'])
    ->name('payment.pending');
