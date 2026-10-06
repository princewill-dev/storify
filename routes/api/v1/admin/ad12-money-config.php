<?php

use App\Http\Controllers\Api\V1\Admin\BankAccountController;
use App\Http\Controllers\Api\V1\Admin\CouponUpdateController;
use App\Http\Controllers\Api\V1\Admin\PaymentMethodController;
use App\Http\Controllers\Api\V1\Admin\VatController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-12 — Money configuration (WS12): VAT, payment methods, bank accounts
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth / audience / team group,
| so the "admin" prefix, the "api.admin." name and the group middleware are
| inherited. Only Route:: lines belong in this file.
|
| `permission:admin.finance` gates the money-configuration screens (the
| permission and the Finance Admin role are already seeded); the controllers
| additionally call EnsuresPlatformAdmin because every business's in-business
| "Super Admin" role bundles the admin.* permission names, so a leaked
| admin-audience token from a business account would otherwise pass the gate.
|
| AdminApiActivityLogger rides these routes so every VAT, payment-method and
| bank-account change is itself audited (WS-1 middleware; it de-dupes per
| request against future group-level wiring).
|
| No `bank-accounts/{id}` show route: the legacy one rendered a Blade view
| that never existed and 500'd on every hit.
*/

Route::middleware(['permission:admin.finance', AdminApiActivityLogger::class])->group(function () {
    Route::get('vats', [VatController::class, 'index'])->name('vats.index');
    Route::post('vats', [VatController::class, 'store'])->name('vats.store');
    Route::put('vats/{vat}', [VatController::class, 'update'])->name('vats.update');
    Route::delete('vats/{vat}', [VatController::class, 'destroy'])->name('vats.destroy');
    Route::post('vats/{vat}/toggle', [VatController::class, 'toggle'])->name('vats.toggle');

    Route::get('payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
    Route::post('payment-methods/{paymentMethod}/toggle', [PaymentMethodController::class, 'toggle'])->name('payment-methods.toggle');

    Route::get('bank-accounts', [BankAccountController::class, 'index'])->name('bank-accounts.index');
    Route::post('bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
    Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
    Route::delete('bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy'])->name('bank-accounts.destroy');
    Route::post('bank-accounts/{bankAccount}/toggle-active', [BankAccountController::class, 'toggleActive'])->name('bank-accounts.toggle-active');
});

/*
| Coupon edit parity (OF-4.2): the shared routes/api/v1/admin.php already
| registers PUT coupons/{coupon} against CouponController, whose validation
| never accepted `code`. Re-registering the URI here replaces that entry (the
| route collection keys on method+URI, so route:list keeps one row per URI) —
| the same way AD-08 extends the shared user routes. CouponController itself
| stays untouched for the rest of the coupon screens.
*/
Route::middleware(['permission:admin.coupons', AdminApiActivityLogger::class])->group(function () {
    Route::put('coupons/{coupon}', [CouponUpdateController::class, 'update'])->name('coupons.update');
});
