<?php

use App\Http\Controllers\Api\V1\Management\StoreDeliveryRouteController;
use App\Http\Controllers\Api\V1\Management\StoreLifecycleController;
use App\Http\Controllers\Api\V1\Management\StoreSettingsController;
use Illuminate\Support\Facades\Route;

// WS-04 — Store Settings, Branding & Lifecycle.
// Loaded by routes/api/v1/management.php inside its management group, so the
// prefix, name prefix and auth/audience/team middleware are already applied.

Route::middleware('permission:stores settings')->group(function () {
    Route::get('stores/{store}/settings', [StoreSettingsController::class, 'show'])->name('stores.settings');

    Route::post('stores/{store}/staff', [StoreSettingsController::class, 'assignStaff'])->name('stores.staff.assign');
    Route::delete('stores/{store}/staff/{staff}', [StoreSettingsController::class, 'removeStaff'])->name('stores.staff.remove');

    Route::post('stores/{store}/service-charges', [StoreSettingsController::class, 'storeServiceCharge'])->name('stores.service-charges.store');
    Route::put('stores/{store}/service-charges/{charge}', [StoreSettingsController::class, 'updateServiceCharge'])
        ->whereNumber('charge')->name('stores.service-charges.update');
    Route::delete('stores/{store}/service-charges/{charge}', [StoreSettingsController::class, 'destroyServiceCharge'])
        ->whereNumber('charge')->name('stores.service-charges.destroy');
    Route::patch('stores/{store}/service-charges/{charge}/toggle', [StoreSettingsController::class, 'toggleServiceCharge'])
        ->whereNumber('charge')->name('stores.service-charges.toggle');

    Route::post('stores/{store}/delivery-routes', [StoreDeliveryRouteController::class, 'store'])->name('stores.delivery-routes.store');
    Route::put('stores/{store}/delivery-routes/{route}', [StoreDeliveryRouteController::class, 'update'])
        ->whereNumber('route')->name('stores.delivery-routes.update');
    Route::delete('stores/{store}/delivery-routes/{route}', [StoreDeliveryRouteController::class, 'destroy'])
        ->whereNumber('route')->name('stores.delivery-routes.destroy');

    Route::patch('stores/{store}/suspend', [StoreLifecycleController::class, 'suspend'])->name('stores.suspend');
    Route::patch('stores/{store}/activate', [StoreLifecycleController::class, 'activate'])->name('stores.activate');
    Route::delete('stores/{store}', [StoreLifecycleController::class, 'destroy'])->name('stores.destroy');
});

Route::middleware('permission:stores edit')->group(function () {
    Route::put('stores/{store}', [StoreSettingsController::class, 'update'])->name('stores.update');
});
