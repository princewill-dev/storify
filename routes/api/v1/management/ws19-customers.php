<?php

use App\Http\Controllers\Api\V1\Management\CustomerParityController;
use App\Http\Controllers\Api\V1\Management\CustomerSearchController;
use App\Http\Controllers\Api\V1\Management\StoreCustomerController;

/*
|--------------------------------------------------------------------------
| WS-19 — Customers module parity
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix, the "api.management." name and
| the auth middleware. Only Route:: lines belong in this file.
|
| The customers list/show/update/suspend/activate routes deliberately reuse
| the legacy URIs: Laravel keys routes by method+URI, so registering the same
| URI later replaces the thin base controller entry declared earlier in the
| shared file. The SPA keeps calling the same paths.
|
| customers/{customer} carries a `cus_[A-Za-z0-9]+` constraint because the
| base file's binding slot is iterated before anything added here — without
| it, `customers/countries` would be swallowed by the account_id binding and
| 404. Account ids are always generated as `cus_` + 8 characters.
|
| Permission parity with the legacy routes/v1/management.php block:
|   customers view    — list, show, countries, per-store customers, search
|   customers edit    — update
|   customers suspend — suspend, activate
*/

Route::middleware('permission:customers view')->group(function () {
    Route::get('customers/countries', [CustomerParityController::class, 'countries'])->name('customers.countries');

    Route::get('customers', [CustomerParityController::class, 'index'])->name('customers.index');

    Route::get('customers/{customer}', [CustomerParityController::class, 'show'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.show');

    // The store detail "Customers" tab (UI owned by WS-27).
    Route::get('stores/{store}/customers', [StoreCustomerController::class, 'index'])->name('stores.customers.index');
});

Route::middleware('permission:customers edit')->group(function () {
    Route::put('customers/{customer}', [CustomerParityController::class, 'update'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.update');
});

Route::middleware('permission:customers suspend')->group(function () {
    Route::post('customers/{customer}/suspend', [CustomerParityController::class, 'suspend'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.suspend');

    Route::post('customers/{customer}/activate', [CustomerParityController::class, 'activate'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.activate');
});

// Global search, with the customer group WS-19 owns. Same URI+name as the
// base route, so this definition wins; products/orders are delegated back to
// SearchController.
Route::get('search', CustomerSearchController::class)->name('search');
