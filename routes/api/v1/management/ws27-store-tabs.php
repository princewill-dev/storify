<?php

use App\Http\Controllers\Api\V1\Management\StoreTabController;

/*
|--------------------------------------------------------------------------
| WS-27 — Store tabs
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside the management group, so only
| Route:: lines belong here — the prefix, name and auth/audience/team
| middleware are inherited.
|
| Only one route is new. The six tabs' row lists deliberately reuse the
| store-scoped filters the domain modules already expose
| (`products?store_id`, `orders/board/list?store_id`, `transactions?store_id`,
| `stores/{store}/customers`, `invoices?store_id`, `staff?store_id`); this
| endpoint supplies what none of them can: the tab strip's per-tab counts and
| the caller's permission shape. Access is re-checked against the caller's
| accessible stores inside the controller, deleted stores included.
*/

Route::middleware('permission:stores view')->group(function () {
    Route::get('stores/{store}/tabs', [StoreTabController::class, 'summary'])
        ->name('stores.tabs.summary');
});
