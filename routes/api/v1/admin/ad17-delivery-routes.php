<?php

use App\Http\Controllers\Api\V1\Admin\DeliveryRouteController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-17 — Delivery routes (WS17): platform-wide checkout configuration
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth / audience / team group,
| so the "admin" prefix, the "api.admin." name and the group middleware are
| inherited. Only Route:: lines belong in this file.
|
| `permission:admin.delivery` matches the legacy gate (`admin_dashboard.php`
| under `permission:admin.delivery`); the controller additionally calls
| EnsuresPlatformAdmin because every business's in-business "Super Admin"
| role bundles the admin.* permission names, so a leaked admin-audience token
| from a business account would otherwise pass the gate.
|
| Admin-created rows are platform-wide (`store_id = NULL`) — the platform
| defaults checkout falls back to when a store has no route of its own — so
| every endpoint here operates on `store_id IS NULL` only. Store-scoped
| routes belong to their businesses and are managed on the business side.
|
| AdminApiActivityLogger rides these routes so every create/edit/toggle/delete
| is itself audited (WS-1 middleware; it de-dupes per request against future
| group-level wiring).
|
| No `delivery-routes/{id}` show route: legacy had none (`->except(['show',
| 'create'])`) and the list row carries everything the edit modal needs.
| `delivery-routes/lookups` precedes nothing that could shadow it — it is a
| distinct URI from the {deliveryRoute} binding.
*/

Route::middleware(['permission:admin.delivery', AdminApiActivityLogger::class])->group(function () {
    Route::get('delivery-routes', [DeliveryRouteController::class, 'index'])->name('delivery-routes.index');
    Route::get('delivery-routes/lookups', [DeliveryRouteController::class, 'lookups'])->name('delivery-routes.lookups');
    Route::post('delivery-routes', [DeliveryRouteController::class, 'store'])->name('delivery-routes.store');
    Route::put('delivery-routes/{deliveryRoute}', [DeliveryRouteController::class, 'update'])->name('delivery-routes.update');
    Route::delete('delivery-routes/{deliveryRoute}', [DeliveryRouteController::class, 'destroy'])->name('delivery-routes.destroy');
    Route::post('delivery-routes/{deliveryRoute}/toggle', [DeliveryRouteController::class, 'toggle'])->name('delivery-routes.toggle');
});
