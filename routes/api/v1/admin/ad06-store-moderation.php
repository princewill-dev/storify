<?php

use App\Http\Controllers\Api\V1\Admin\StoreModerationController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-06 — Store moderation & lifecycle (WS6)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth / audience / team group,
| so these inherit the "admin" prefix, the "api.admin." name and the
| middleware. Only Route:: lines belong in this file.
|
| The two existing `stores` URIs are already registered by the shared
| routes/api/v1/admin.php (which this workstream must not edit) against the
| older, read-only StoreController. Re-registering them here is how a module
| file "extends" an existing endpoint without touching the shared file: the
| route collection keys on method+URI, so the later registration replaces the
| earlier one and `route:list` keeps exactly one entry per URI. The old
| controller stays on disk untouched for the orchestrator to retire, and every
| field it returned is preserved in the new payloads.
|
| `stores/form-options` feeds the create/edit modal's dropdowns and the
| multi-business guard state. It must not be swallowed by the `{store}`
| wildcard: the shared admin.php registered `stores/{store}` before this file
| was globbed in, and Laravel matches routes in registration order (a
| re-registered URI keeps its original slot), so the wildcard sits *earlier*
| in the collection than any module route. The `{store}` routes below are
| therefore constrained to Store's public id shape (`st_…`); `form-options`
| fails the constraint, the matcher moves on and reaches the static route.
|
| `permission:admin.stores` is the seeded gate the roadmap maps to store
| moderation. AdminApiActivityLogger rides the routes so who suspended or
| deleted a store is itself audited; it de-dupes per request against WS-1's
| group-level wiring.
*/

Route::middleware(['permission:admin.stores', AdminApiActivityLogger::class])->group(function () {
    Route::get('stores', [StoreModerationController::class, 'index'])->name('stores.index');
    Route::post('stores', [StoreModerationController::class, 'store'])->name('stores.store');
    Route::get('stores/form-options', [StoreModerationController::class, 'formOptions'])->name('stores.form-options');
    Route::get('stores/{store}', [StoreModerationController::class, 'show'])->where('store', 'st_[A-Za-z0-9]+')->name('stores.show');
    Route::put('stores/{store}', [StoreModerationController::class, 'update'])->where('store', 'st_[A-Za-z0-9]+')->name('stores.update');
    Route::post('stores/{store}/suspend', [StoreModerationController::class, 'suspend'])->where('store', 'st_[A-Za-z0-9]+')->name('stores.suspend');
    Route::post('stores/{store}/activate', [StoreModerationController::class, 'activate'])->where('store', 'st_[A-Za-z0-9]+')->name('stores.activate');
    Route::delete('stores/{store}', [StoreModerationController::class, 'destroy'])->where('store', 'st_[A-Za-z0-9]+')->name('stores.destroy');
});
