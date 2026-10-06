<?php

use App\Http\Controllers\Api\V1\Management\OrderFulfilmentController;

/*
|--------------------------------------------------------------------------
| WS-12 — Order fulfilment actions, activity & e-mails
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix, the "api.management." name and
| the auth middleware. Only Route:: lines belong in this file.
|
| The guarded transitions deliberately reuse the legacy "orders status_update"
| permission: that is what routes/v1/management.php gated accept/process/
| dispatch/deliver/complete/cancel/return on, and the seeder's finer-grained
| orders cancel / orders assign_delivery / orders refund permissions gate
| other surfaces (refunds live in TransactionController).
|
| PUT orders/{order}/payment-status is NOT re-registered here: the shared
| route file already registers that exact URI against OrderController and it
| is matched first, so this module cannot extend it in place. The extended
| version (unpaid voids instead of deleting, manual transactions carry the
| cash method + NGN, paid_at aligns with the mapped status) lives on the
| legacy orders/{order}/payment URI instead.
*/

Route::middleware('permission:orders view')->group(function () {
    Route::get('orders/{order}/fulfilment', [OrderFulfilmentController::class, 'show'])
        ->name('orders.fulfilment');
});

Route::middleware('permission:orders status_update')->group(function () {
    Route::get('orders/{order}/delivery-agents', [OrderFulfilmentController::class, 'deliveryAgents'])
        ->name('orders.delivery-agents');

    Route::post('orders/{order}/accept', [OrderFulfilmentController::class, 'accept'])
        ->name('orders.accept');
    Route::post('orders/{order}/process', [OrderFulfilmentController::class, 'process'])
        ->name('orders.process');
    Route::post('orders/{order}/dispatch', [OrderFulfilmentController::class, 'dispatch'])
        ->name('orders.dispatch');
    Route::post('orders/{order}/deliver', [OrderFulfilmentController::class, 'deliver'])
        ->name('orders.deliver');
    Route::post('orders/{order}/complete', [OrderFulfilmentController::class, 'complete'])
        ->name('orders.complete');
    Route::post('orders/{order}/cancel', [OrderFulfilmentController::class, 'cancel'])
        ->name('orders.cancel');
    Route::post('orders/{order}/return', [OrderFulfilmentController::class, 'returnOrder'])
        ->name('orders.return');

    Route::match(['put', 'patch'], 'orders/{order}/payment', [OrderFulfilmentController::class, 'updatePaymentStatus'])
        ->name('orders.payment');
});
