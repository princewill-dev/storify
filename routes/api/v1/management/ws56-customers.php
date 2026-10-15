<?php

use App\Http\Controllers\Api\V1\Management\CustomerAccountController;

/*
|--------------------------------------------------------------------------
| WS-56 — the Customers section: tabs, notes, messaging, data rights
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix, the "api.management." name and the
| auth middleware. Only Route:: lines belong in this file.
|
| WS-19 owns customers, customers/{customer} and the suspend/activate pair;
| this file adds what hangs off a customer and the two queues. Nothing here
| redefines a WS-19 URI.
|
| Every route that takes a customer carries `->where('customer',
| 'cus_[A-Za-z0-9]+')`. Two of them are load-bearing rather than tidiness:
| `customers/export` and `customers/deletion-requests` are literal segments
| sitting where WS-19's `customers/{customer}` binding can see them, and the
| constraint is the only thing stopping the account_id binding swallowing both
| and 404-ing them. Account ids are always `cus_` + 8 characters.
|
| Permission mapping — the customers group WS-19 already routes on, extended
| in SpatiePermissionSeeder/SyncPermissions with note, export and erase:
|   customers view    — orders, payments, addresses, notes, messages, consents,
|                       the deletion queue
|   customers note    — add, delete
|   customers message — one-to-one send, broadcast
|   customers export  — the CSV
|   customers erase   — approve, reject
*/

Route::middleware('permission:customers view')->group(function () {
    // Literal segments first, so the reading order matches what the router
    // does: these can only match themselves.
    Route::get('customers/deletion-requests', [CustomerAccountController::class, 'deletionRequests'])
        ->name('customers.deletion-requests');

    // The customer detail screen's tabs. Overview is WS-19's show.
    Route::get('customers/{customer}/orders', [CustomerAccountController::class, 'orders'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.orders');

    Route::get('customers/{customer}/payments', [CustomerAccountController::class, 'payments'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.payments');

    Route::get('customers/{customer}/addresses', [CustomerAccountController::class, 'addresses'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.addresses');

    Route::get('customers/{customer}/notes', [CustomerAccountController::class, 'notes'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.notes');

    Route::get('customers/{customer}/messages', [CustomerAccountController::class, 'messages'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.messages');

    Route::get('customers/{customer}/consents', [CustomerAccountController::class, 'consents'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.consents');
});

Route::middleware('permission:customers note')->group(function () {
    Route::post('customers/{customer}/notes', [CustomerAccountController::class, 'storeNote'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.notes.store');

    // {note} is a numeric primary key, constrained so the segment cannot be
    // read as anything else.
    Route::delete('customers/{customer}/notes/{note}', [CustomerAccountController::class, 'destroyNote'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->where('note', '[0-9]+')
        ->name('customers.notes.destroy');
});

Route::middleware('permission:customers message')->group(function () {
    // The broadcast is declared before the one-to-one route and takes a
    // different URI shape (no {customer}), so the two cannot collide — but it
    // carries the constraint anyway, for the same reason as everything else.
    Route::post('customers/messages', [CustomerAccountController::class, 'broadcast'])
        ->name('customers.messages.broadcast');

    Route::post('customers/{customer}/message', [CustomerAccountController::class, 'sendMessage'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.message.send');
});

Route::middleware('permission:customers export')->group(function () {
    Route::get('customers/export', [CustomerAccountController::class, 'export'])
        ->name('customers.export');
});

Route::middleware('permission:customers erase')->group(function () {
    Route::post('customers/{customer}/erasure/approve', [CustomerAccountController::class, 'approveErasure'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.erasure.approve');

    Route::post('customers/{customer}/erasure/reject', [CustomerAccountController::class, 'rejectErasure'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.erasure.reject');
});
