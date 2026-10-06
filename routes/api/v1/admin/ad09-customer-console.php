<?php

use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-09 — Platform customer console (WS9)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth/audience/team group, so
| these inherit the "admin" prefix, the "api.admin." name and the middleware.
| Only Route:: lines belong in this file.
|
| `permission:admin.customers` gates the console (SpatiePermissionSeeder
| seeds the string; the Platform Admin role carries it). The platform-role
| check inside the controller is the second half of the gate, because the
| business-scoped "Super Admin" role bundles the admin.* permission names.
|
| The `cus_[A-Za-z0-9]+` constraint on the {customer} parameter is required:
| Customer::getRouteKeyName() is `account_id`, and without it a literal path
| such as customers/countries could be swallowed by the binding. Account ids
| are always generated as `cus_` + 8 alphanumerics.
|
| AdminApiActivityLogger rides these routes so who looked at or changed a
| customer is itself audited (WS-1 middleware; it de-dupes per request
| against the future group-level wiring).
*/

Route::middleware(['permission:admin.customers', AdminApiActivityLogger::class])->group(function () {
    Route::get('customers/countries', [CustomerController::class, 'countries'])->name('customers.countries');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');

    Route::get('customers/{customer}', [CustomerController::class, 'show'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.show');

    Route::put('customers/{customer}', [CustomerController::class, 'update'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.update');

    Route::post('customers/{customer}/suspend', [CustomerController::class, 'suspend'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.suspend');

    Route::post('customers/{customer}/activate', [CustomerController::class, 'activate'])
        ->where('customer', 'cus_[A-Za-z0-9]+')
        ->name('customers.activate');
});
