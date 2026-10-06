# WS-29 — Stock Visibility & Low-Stock Model — repair pass

**Scope repaired:** the files reported by the WS-29 implementation — API
`app/Services/StockVisibilityService.php`,
`app/Http/Controllers/Api/V1/Management/StockVisibilityController.php`,
`app/Http/Controllers/Api/V1/Management/StockMovementController.php`,
`routes/api/v1/management/ws29-stock-visibility.php`; feature test
`tests/Feature/Api/ws29stockvisibilityTest.php`; SPA
`src/api/modules/ws29-stock-visibility.ts`,
`src/router/modules/ws29-stock-visibility.ts`,
`src/nav/modules/ws29-stock-visibility.ts`, `src/views/LowStockView.vue`,
`src/views/StockMovementsView.vue`, `src/components/StockLevelBadge.vue`,
`src/components/StockMinLevelEditor.vue`, `src/components/WarehouseActivityTab.vue`.

This pass re-verified the working tree after the first repair pass's fix. The
fix is present and holds; no further edits were needed this round.

Commands run: `php -l` (all five PHP files), `php artisan route:list`
(route detail + app-wide duplicate-name scan: **1107 routes / 1048 named / 0
duplicate names**), `vendor/bin/pint --test` (passed on all five PHP files),
per-file `@vue/compiler-sfc` (parse + `compileScript` + `compileTemplate`) on
all five SFCs, and `ts.transpileModule` on the three `.ts` modules — all clean.
`php artisan test` and `npm run typecheck` were **not** run (the fleet shares
one test database and one toolchain — the orchestrator runs them serially).

## Verification results

| Check | Result |
| --- | --- |
| Route → controller method exists with the right signature | **PASS (fix from the first pass verified in place).** `PATCH stock-locations/{stockLocation}` binds to `StockVisibilityController@updateMinLevel(Request $request, StockLocation $stockLocation)` — the renamed parameter matches the route placeholder, so implicit binding resolves the model. `summary`, `lowStock`, `levels`, `bulkUpdateMinLevels` and `StockMovementController@index` all resolve; `route:list` prints every action with its controller + method. |
| House envelope + tenant scoping | PASS — every action returns `$this->ok(...)`; the single refusal path is `error()` (from > to, 422). Unauthorized ids abort 403 (`optionalStore`, `optionalWarehouse`, `resolveProduct`, `authorizeLocation`, and the pre-transaction id-set check in `bulkUpdateMinLevels` — the last one re-derives the allowed ids from the authenticated user before `DB::transaction` and locks rows inside it). Reads run through `accessibleLocationQuery()` / accessible store+warehouse id sets (deleted hosts excluded). |
| SPA calls ↔ routes ↔ api-module exports | PASS — `LowStockView` calls `summary` + `lowStock`; `StockMinLevelEditor` calls `bulkMinLevels`; `StockMovementsView` and `WarehouseActivityTab` call `movements`; `updateMinLevel` is exported and registered. All six map to `GET /management/stock/summary`, `GET /management/stock/low-stock`, `GET /management/stock-levels`, `GET /management/stock-movements`, `PUT /management/stock-levels/min-levels`, `PATCH /management/stock-locations/{id}`; every query param matches the controller validation (`state ∈ attention/low_stock/out_of_stock/all/in_stock`, `type` ∈ the four `StockMovementType` values, `from`/`to` `Y-m-d`, `product_limit` 1–20). |
| Route module wrapper / duplicate names | PASS — `ws29-stock-visibility.php` has no prefix/name/auth wrapper and no imports beyond the two controllers (inherits them from the glob-include in `routes/api/v1/management.php`). App-wide route-name scan: 0 duplicates. The PATCH `stock-locations/{stockLocation}` coexists with WS-15's `GET stock-locations` (different method + handler; `route:list -v` shows the correct middleware: `permission:products view` for summary/low-stock, `permission:warehouses view` for levels/movements, `permission:warehouses edit` for the two writes). |
| Router/nav module shapes | PASS — router default-exports `RouteRecordRaw[]` with relative child paths (`inventory/low-stock`, `inventory/stock-movements`) and `meta.title`; nav default-exports `NavGroup[]` (`{ label, icon, items: [{ label, to, icon, permission }] }`) gated on `products view` / `warehouses view`; both merge via the existing `import.meta.glob` in `router/index.ts` / `AppLayout.vue`. No SPA route-name collision in `src/router/modules/` (the only duplicate there is a pre-existing `plans`, not WS-29's). |
| Imports resolve | PASS — every `@/` import in the eight SPA files exists (`PaginationBar`, `StatCard`, `AppModal`, `StockLevelBadge`, `StockMinLevelEditor`, `@/api/client`, `@/lib/money`, `@/stores/auth`, `@/stores/ui`, `@/nav/types`, the view paths); `useUiStore().success()` exists. All PHP imports resolve; `route:list` proves the class references load. |
| PHP syntax / Pint | PASS — `php -l` clean on all five PHP files; `vendor/bin/pint --test` reports `passed`. |
| Vue SFC / TS syntax | PASS — all five SFCs parse and compile script + template cleanly; all three `.ts` modules transpile without diagnostics. |

## Fixes applied

**1. (First repair pass, verified present) `StockVisibilityController@updateMinLevel` — the
min-level editor's single-row save was dead on arrival.** The route parameter
`{stockLocation}` did not match the method parameter `$location`, so Laravel's
implicit binding skipped the model and the container injected a fresh, empty
`StockLocation` (`business_id = null`); `authorizeLocation()` then always
aborted 403 and the validation branch (422) was unreachable. The parameter is
now `StockLocation $stockLocation` (all five uses inside the method updated),
with a short comment pinning the name to the route parameter. This is the write
the whole workstream exists to add (legacy never set `min_quantity`), so
without it the acceptance criterion "a min level can actually be set" fails —
the test file asserts it in `a min level can be set and immediately changes
the low-stock verdict` and `stock visibility endpoints validate their input`.

No other defects were found this round; nothing else was changed.

## Verified clean — deliberately not changed

- **The reconciled definition matches audit + verify.** `quantity <= 0` is out;
  otherwise low iff `min_quantity > 0 ? quantity <= min_quantity :
  quantity <= 10` (legacy badge green >10 / amber 1–10 / red 0, dashboard list
  ≤10, verify #5's "`min_quantity` was never settable in legacy, so an editor
  is new scope"). One service publishes it to every read and echoes
  `definition` into the payloads, so the SPA never re-derives a threshold.
- **Money stays integer kobo** — `SUM(quantity * ROUND(price * 100))` in SQL,
  `value_kobo` returned, `value` only as a divided display copy.
- **Movement history semantics** — signed quantity derived per side from the
  movement's own location vs its `from_/to_location_*` (transfers write two
  rows of the same type), balances/actor straight from the ledger rows
  `StockLedgerService` writes, `reference` resolves `StockTransfer` / `Product`.
- **Test file** — helpers (`createBusinessOwner`, `setPermissionsTeamId`) and
  fixtures exist; `recordAddition`/`recordRemoval`/`recordTransfer` signatures
  match the ledger service; assertions line up with the service's row shapes,
  sort orders (out first, then ascending quantity; levels case-sort
  out → low → healthy), the tenant boundary (403 on every foreign id), the
  deleted-warehouse exclusion, and the Cashier gate (the role has
  `products view` but no `warehouses` permission in `SpatiePermissionSeeder`).

## Unfixable within this workstream's file ownership

- **Three other controllers still carry their own low-stock threshold, so
  "one definition" is not yet end-to-end.** `DashboardController.php:67`
  counts `whereBetween('quantity', [1, 5])` (the divergence the audit calls
  out); `ProductListController.php:52` defines `LOW_STOCK_THRESHOLD = 10` and
  uses it at :299/:302/:431; `StoreDashboardController.php:32` defines the same
  constant (:78/:132/:147). All three are existing controllers not named as
  mine; folding them onto `StockVisibilityService` means editing files another
  workstream owns (and WS-28's dashboard renders its own inventory block).
- **`WarehouseController.php:33/:111` counts low stock with
  `lowStock()`/`isLowStock()` — min-level only.** Now that min levels can be
  set this finally fires for explicitly-configured rows, but it ignores the
  default-10 fallback and is a second definition. Existing controller, not
  mine.
- **No existing view mounts the new components.** `WarehouseDetailView.vue`
  still renders its inline movements block (verified: lines 16/37/94–97)
  instead of `WarehouseActivityTab.vue`, and no product/store view mounts
  `StockLevelBadge.vue` / `StockMinLevelEditor.vue`. The components carry
  MOUNT NOTE headers with the intended call sites; wiring them means editing
  WS-06/WS-25-owned views.
- **The sidebar now has three "Inventory" sources** — AppLayout's inline
  Warehouses group, WS-15's module and this module. Coalescing them (the nav
  module's MOUNT NOTE lists the intended single group) requires editing
  `AppLayout.vue` / another module's nav file, neither of which is mine.
