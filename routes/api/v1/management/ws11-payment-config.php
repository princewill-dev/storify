<?php

use App\Http\Controllers\Api\V1\Management\PaymentSettingsController;
use App\Http\Controllers\Api\V1\Management\StorePaymentMethodController;
use Illuminate\Support\Facades\Route;

// WS-11 — Payment Configuration (banks, Paystack, store assignment).
// Loaded by routes/api/v1/management.php inside its management group, so the
// prefix, name prefix and auth/audience/team middleware are already applied.

Route::middleware('permission:settings payment')->prefix('payment-settings')->name('payment-settings.')->group(function () {
    Route::get('/', [PaymentSettingsController::class, 'index'])->name('index');

    // Bank accounts (business ledger used by payouts and manual transfers).
    Route::post('bank-accounts', [PaymentSettingsController::class, 'storeBankAccount'])->name('bank-accounts.store');
    Route::put('bank-accounts/{bank}', [PaymentSettingsController::class, 'updateBankAccount'])->name('bank-accounts.update');
    Route::patch('bank-accounts/{bank}/primary', [PaymentSettingsController::class, 'setPrimaryBank'])->name('bank-accounts.primary');
    Route::delete('bank-accounts/{bank}', [PaymentSettingsController::class, 'destroyBankAccount'])->name('bank-accounts.destroy');
    Route::post('verify-bank', [PaymentSettingsController::class, 'verifyBankAccount'])->name('verify-bank');
    Route::post('banks', [PaymentSettingsController::class, 'banks'])->name('banks');

    // Paystack gateway.
    Route::post('gateways', [PaymentSettingsController::class, 'storeGateway'])->name('gateways.store');
    Route::put('gateways/{gateway}', [PaymentSettingsController::class, 'updateGateway'])
        ->whereNumber('gateway')->name('gateways.update');
    Route::delete('gateways/{gateway}', [PaymentSettingsController::class, 'destroyGateway'])
        ->whereNumber('gateway')->name('gateways.destroy');
    Route::patch('gateways/{gateway}/toggle', [PaymentSettingsController::class, 'toggleGateway'])
        ->whereNumber('gateway')->name('gateways.toggle');
    Route::post('gateways/{gateway}/test', [PaymentSettingsController::class, 'testGateway'])
        ->whereNumber('gateway')->name('gateways.test');

    // Per-store auto/manual payment mode (legacy controller-only route).
    Route::post('stores/{store}/toggle-mode', [PaymentSettingsController::class, 'togglePaymentMode'])->name('toggle-mode');
});

// Method → store assignment. One unambiguous type resolution: `gateway` ids
// are business gateway pivot ids, `bank` ids are store_banks ids — the legacy
// `{type}` string silently mapped everything but the literal "paystack" to
// bank_transfer and turned the store modal's Paystack assign into a no-op.
Route::middleware('permission:settings payment')->group(function () {
    Route::get('payment-methods/{type}/{id}/info', [PaymentSettingsController::class, 'methodInfo'])->name('payment-methods.info');
    Route::post('payment-methods/{type}/{id}/assign', [PaymentSettingsController::class, 'assignMethod'])->name('payment-methods.assign');
    Route::delete('payment-methods/{type}/{id}/unassign/{store}', [PaymentSettingsController::class, 'unassignMethod'])->name('payment-methods.unassign');
});

// Store-side assignment surfaces, gated like legacy's store settings routes.
Route::middleware('permission:stores settings')->prefix('stores/{store}')->name('stores.')->group(function () {
    Route::get('payment-methods', [StorePaymentMethodController::class, 'show'])->name('payment-methods');
    Route::post('assign-bank', [StorePaymentMethodController::class, 'assignBank'])->name('assign-bank');
    Route::delete('remove-bank/{bank}', [StorePaymentMethodController::class, 'removeBank'])->name('remove-bank');
});
