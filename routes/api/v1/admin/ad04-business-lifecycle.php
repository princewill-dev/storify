<?php

use App\Http\Controllers\Api\V1\Admin\BusinessLifecycleController;
use App\Http\Controllers\Api\V1\Admin\BusinessTypeController;
use App\Http\Controllers\Api\V1\Admin\OwnershipTypeController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-04 — Business lifecycle & directory (WS4)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth/audience/team group, so
| these inherit the "admin" prefix, the "api.admin." name and the middleware.
| Only Route:: lines belong in this file.
|
| The four `businesses` URIs below are already registered by the shared
| routes/api/v1/admin.php (which this workstream must not edit) against the
| older, read-only BusinessController. Re-registering them here is how a
| module file "extends" an existing endpoint without touching the shared
| file: the route collection keys on method+URI, so the later registration
| replaces the earlier one and `route:list` keeps exactly one entry per URI
| (verified with `php artisan route:list --path=api/v1/admin`). The old
| controller is left untouched on disk for the orchestrator to retire.
|
| `permission:admin.businesses` gates the lifecycle; owner verification also
| requires `permission:admin.users`, the gate legacy put on the verify action
| (it lived in the users domain, reached from the business detail page).
|
| AdminApiActivityLogger rides these routes so who provisioned, edited,
| suspended or deleted a tenant is itself audited (WS-1 middleware; it
| de-dupes per request against the group-level wiring).
*/

Route::middleware(['permission:admin.businesses', AdminApiActivityLogger::class])->group(function () {
    Route::get('businesses', [BusinessLifecycleController::class, 'index'])->name('businesses.index');
    Route::post('businesses', [BusinessLifecycleController::class, 'store'])->name('businesses.store');
    Route::get('businesses/{business}', [BusinessLifecycleController::class, 'show'])->name('businesses.show');
    Route::put('businesses/{business}', [BusinessLifecycleController::class, 'update'])->name('businesses.update');
    Route::delete('businesses/{business}', [BusinessLifecycleController::class, 'destroy'])->name('businesses.destroy');
    Route::post('businesses/{business}/suspend', [BusinessLifecycleController::class, 'suspend'])->name('businesses.suspend');
    Route::post('businesses/{business}/activate', [BusinessLifecycleController::class, 'activate'])->name('businesses.activate');
});

Route::post('businesses/{business}/verify-owner', [BusinessLifecycleController::class, 'verifyOwner'])
    ->middleware(['permission:admin.businesses', 'permission:admin.users', AdminApiActivityLogger::class])
    ->name('businesses.verify-owner');

// Business types and ownership types feed the business/store setup dropdowns
// and sit in the Settings section of the sidebar (legacy gated both with
// permission:admin.content, not admin.businesses).
Route::middleware(['permission:admin.content', AdminApiActivityLogger::class])->group(function () {
    Route::get('business-types', [BusinessTypeController::class, 'index'])->name('business-types.index');
    Route::post('business-types', [BusinessTypeController::class, 'store'])->name('business-types.store');
    Route::put('business-types/{businessType}', [BusinessTypeController::class, 'update'])->name('business-types.update');
    Route::delete('business-types/{businessType}', [BusinessTypeController::class, 'destroy'])->name('business-types.destroy');

    Route::get('ownership-types', [OwnershipTypeController::class, 'index'])->name('ownership-types.index');
    Route::post('ownership-types', [OwnershipTypeController::class, 'store'])->name('ownership-types.store');
    Route::put('ownership-types/{ownershipType}', [OwnershipTypeController::class, 'update'])->name('ownership-types.update');
    Route::delete('ownership-types/{ownershipType}', [OwnershipTypeController::class, 'destroy'])->name('ownership-types.destroy');
});
