# WS-04 repair — Store Settings, Branding & Lifecycle

**Verdict:** repaired and re-verified. All seven checks pass. This pass found and fixed one
additional correctness bug (delivery-route `area` written as `null` into a NOT NULL column under
MySQL strict mode, which 500s whenever a route is saved without an area). Three fixes recorded by
the earlier pass were re-verified present. Two integration items cannot be fixed inside WS-04's
file ownership and are recorded below with exact blockers; one item (POS enablement read-only) is
deliberate, not a gap.

## Files verified

| Piece | Path |
| --- | --- |
| API controllers | `app/Http/Controllers/Api/V1/Management/StoreSettingsController.php`, `StoreLifecycleController.php`, `StoreDeliveryRouteController.php` |
| Route module | `routes/api/v1/management/ws04-store-settings.php` |
| API test | `tests/Feature/Api/ws04storesettingsTest.php` (22 tests) |
| SPA view | `storify-management/src/views/StoreSettingsView.vue` |
| SPA API client | `storify-management/src/api/modules/ws04-store-settings.ts` |
| SPA router module | `storify-management/src/router/modules/ws04-store-settings.ts` |
| SPA nav module | `storify-management/src/nav/modules/ws04-store-settings.ts` |

## Checks

1. **Routes → controller methods.** Reflection pass over the router: all 14 WS-04 routes resolve to
   an existing class + method with the expected parameter list (`show/update/assignStaff/
   removeStaff/storeServiceCharge/updateServiceCharge/destroyServiceCharge/toggleServiceCharge`,
   `store/update/destroy`, `suspend/activate/destroy`). `{store}` binds through
   `Store::getRouteKeyName()` = `store_id`; `{staff}` through `User::getRouteKeyName()` =
   `account_code`; `{charge}`/`{route}` are ints resolved through `$store->serviceCharges()` /
   `$store->deliveryRoutes()` and constrained with `->whereNumber()`, so a sibling store's id is a
   404 rather than an edit target.
2. **Envelope + tenant scoping.** All 14 public controller methods call `authorizeStore()`
   (business_id + `accessibleStores()`) before touching `$store`; every success/refusal path returns
   `$this->ok(...)` / `$this->error(...)`. Non-envelope exits are only `abort()`/`firstOrFail()`
   exceptions, matching the fleet idiom (`WarehouseController`).
3. **SPA calls ↔ routes ↔ api module.** All 14 `storeSettingsApi.*` calls used by the view
   (`show, update, assignStaff, removeStaff, create/update/delete/toggleServiceCharge,
   create/update/deleteDeliveryRoute, suspend, activate, destroy`) are exported by the api module
   and map one-to-one to registered URIs; `update` is multipart POST with `_method=PUT` because the
   logo upload shares the registered `PUT stores/{store}` route.
4. **Route module shape / duplicate names.** No `prefix()` and no auth/audience/team wrapper (both
   inherited); only route-level `permission:` gates. App-wide `route:list` scan: 1107 routes, zero
   duplicate names and zero duplicate method+URI pairs inside the management group. The admin
   module's `stores.update/suspend/activate/destroy` live under the `api.admin.*` name prefix, so
   there is no collision.
5. **Router / nav modules.** Router default-exports one `RouteRecordRaw` child
   (`stores/:storeId/settings`, no leading slash, `meta.title`, `meta.permission`) matching the
   param name (`storeId`) that WS-03/WS-27 callers already deep-link with; the name
   `stores.settings` is unique. Nav module default-exports `NavGroup[]` (empty by design, same as
   WS-03) in the shape AppLayout's glob consumes.
6. **Imports resolve.** `class_exists`/`trait_exists` pass on every import of the three controllers
   and the route module (including the `ResolvesManagementContext` trait) and on the test's
   imports. SPA: `@vue/compiler-sfc` parse + `compileScript` + `compileTemplate` clean on
   `StoreSettingsView.vue`; all three TS modules transpile clean; every shared import
   (`@/api/client`, `@/lib/runtimeConfig`, `@/stores/auth`, `@/stores/ui`, `AppModal`, `StatusBadge`,
   `@/nav/types`) exists with compatible props.
7. **PHP syntax.** `php -l` clean on all three controllers, the route module and the test file;
   `vendor/bin/pint --test` passes; `php artisan route:list` clean.

## Fix applied in this pass

1. `app/Http/Controllers/Api/V1/Management/StoreDeliveryRouteController.php` — **delivery routes
   without an area produced a 500.** `delivery_routes.area` is `varchar(255) NOT NULL` with no
   default (`SHOW COLUMNS` confirms `Null=NO`) and the MySQL connection runs `'strict' => true`
   (`config/database.php`), but both `store()` and `update()` wrote `$data['area'] ?? null`. The
   SPA's Area field is optional, so adding a route without an area hit an integrity-constraint
   error. Both now persist `''`, mirroring the comment and fix already in WS-05's
   `StorefrontController::syncNationwideDelivery` ("Legacy wrote area = null into a NOT NULL
   column, which fails under MySQL strict mode"). The API payload/handling for `area` was already
   null-tolerant (`route.area ?? ''`, `filter(Boolean)`), so no SPA change is needed.
2. `tests/Feature/Api/ws04storesettingsTest.php` — added a regression test: a route created with no
   area returns 201 with `area === ''`, the column stores `''`, and clearing the area on edit
   keeps working. 22 tests total.

## Fixes re-verified from the earlier pass (present in the files)

- `StoreSettingsController` excludes `status = 'deleted'` staff from both the assignable `available`
  list and the `assignStaff` `Rule::exists` (WS-20 soft-deactivation); the test covering it is
  present.
- `StoreSettingsView.vue` links the Bank Accounts card to `/stores/:storeId/payment-methods`
  (registered by WS-11) when the user holds the route's permission.
- `routes/api/v1/management/ws04-store-settings.php` carries `->whereNumber('charge'|'route')` so a
  non-numeric id is a clean 404 instead of a `TypeError` 500.

## Unfixable within this workstream's ownership

- **"Suspend hides the storefront" is only half true.** Suspend itself is correct (status + owner
  mail + log), but the public read paths still resolve a suspended store; each needs a status guard
  in a file owned by another workstream:
  - `app/Http/Controllers/Api/V1/Storefront/Concerns/ResolvesStorefrontContext.php` — `resolveStore()`
    excludes only `'deleted'` (`->where('status', '!=', 'deleted')`).
  - `app/Http/Controllers/Cart/CartApiController.php` — `resolveStore()` (around line 29) has no
    status filter at all.
  - `app/Http/Controllers/Storefront/StoreOrderController.php` — `resolveStore()` (around line 14)
    has no status filter at all.
- **Settings tab inside `StoreDetailView` is not wired.** WS-03's `StoreDetailView.vue` declares the
  `settings` tab (correct permission gate) but does not render `<StoreSettingsView />` for it; that
  file is not WS-04's. WS-04's side of the contract is satisfied: the standalone route
  `/stores/:storeId/settings` works, the view reads `route.params.storeId` or `route.params.id`,
  and its header comment states where it must be mounted.

## Notes (deliberate, no change)

- POS enablement stays read-only in the settings card; WS-17 owns `stores/{store}/pos/enable` and a
  competing route here would be shadowed or shadow it.
- Service-charge amounts remain naira decimals, not kobo: the existing POS read endpoint,
  `ProcessPosSale` (`$total = round($subtotal + $serviceChargeAmount + $tax, 2)`) and
  `orders.service_charge_amount` all treat the column as naira decimals. Delivery-route fees are
  kobo as the plan requires.
- Lifecycle mail is addressed to the store owner (not the acting user) and the reactivation gate
  reads the owner's KYC — both verify-doc corrections implemented as specified.

## Verification performed

- `php -l` on the three controllers, the route module and the test file — clean;
  `vendor/bin/pint --test` — passed.
- `php artisan route:list --path=api/v1/management --json` plus a reflection pass over every
  `/management/stores*` route action (`class_exists` + `method_exists` + parameter list) — 14/14
  WS-04 routes OK.
- App-wide duplicate-name and duplicate method+URI scan over 1107 routes — none added.
- `SHOW COLUMNS` introspection of `stores`, `orders`, `transactions`, `service_charges`,
  `delivery_routes`, `kyc_applications`, `users` (NOT NULL-without-default scan) to validate the
  test file's `create()` calls without executing tests.
- `@vue/compiler-sfc` parse/compile + TypeScript `transpileModule` on the SPA pieces — clean.
- Not run, per the fleet rule: `php artisan test`, `npm run typecheck` (the 22 tests are reviewed
  and schema-checked, not executed).
