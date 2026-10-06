# WS-03 — Store Detail Shell & Dashboard Tab — repair pass

Verified against the actual files (not the completion report). Workstream files:

- API: `app/Http/Controllers/Api/V1/Management/StoreDashboardController.php`,
  `routes/api/v1/management/ws03-store-detail.php`,
  `tests/Feature/Api/ws03storedetailTest.php`
- SPA (management): `src/api/modules/ws03-store-detail.ts`,
  `src/router/modules/ws03-store-detail.ts`, `src/nav/modules/ws03-store-detail.ts`,
  `src/views/StoreDetailView.vue`, `src/views/store-tabs/StoreDashboardTab.vue`,
  `src/views/store-tabs/StoreTabPlaceholder.vue`

## Verdict

All seven checklist items pass after five in-scope repairs across two passes (pass 1: the
low-stock threshold and the POS URL; pass 2: three test fixtures that would have failed on
the file's first execution). Nothing in the checklist is blocked by file ownership; the one
deliberately-unfinished integration (mounting the now-landed tab components) is assigned to
the orchestrator by the tab workstreams' own docstrings, and the shell's acceptance criteria
are met without it.

## Fixed

1. **Low-stock threshold was 5, contradicting the WS-29 reconciliation (10).**
   `StoreDashboardController::LOW_STOCK_THRESHOLD = 5` was the lone outlier in the app:
   `StockVisibilityService::DEFAULT_LOW_STOCK_THRESHOLD` (WS-29, "the one place the 'is this
   stock low?' question is answered"), `DashboardWidgetsController` (WS-28),
   `ProductListController` and the SPA's `StockLevelBadge` all use 10 — and the roadmap for
   WS-03 reads "low-stock **list** with the reconciled threshold (WS-29)". The controller's
   own comment still said "until it lands", stale now that WS-29 has. Set to 10 with a
   comment citing the reconciliation; the card's list/count semantics (active, non-digital,
   `quantity > 0` for the list, `quantity <= 0` split into the out-of-stock footer) are
   unchanged and still agree with WS-28's split. Test assertion updated to 10
   (`ws03storedetailTest.php`, "low stock list uses one documented threshold" — the expected
   items are unaffected: 40 > 10 > 4 > 2).

2. **"Open POS Portal" duplicated the POS URL derivation, ignoring `VITE_POS_URL`.**
   `StoreDetailView.vue` computed `https://pos.${mainDomain()}` itself. WS-17 exports
   `posAppUrl()` (`src/api/modules/ws17-pos-oversight.ts`) precisely so every terminal link
   resolves through the configured `VITE_POS_URL` with the same derived default — its
   docstring says the helper exists because the legacy refactors shipped `href=""`. A
   deployment that sets `VITE_POS_URL` would have had the store header and the POS screens
   point at different hosts. The view now imports and calls `posAppUrl()`; the
   `mainDomain` import was removed (no other use).

Second pass (this run) — the test file had never been executed, so three fixture inserts were
walked against the live schema (`SHOW COLUMNS` + `@@sql_mode`, read-only). All three would
have thrown MySQL error 1364 on the serial suite's first execution:

3. **Both `Customer::create` fixtures omitted `phone` and `password`**
   (`ws03storedetailTest.php`, "the store dashboard returns store-scoped metrics").
   `customers.phone` and `customers.password` are `NOT NULL` with no default while the
   connection runs `STRICT_TRANS_TABLES`, and the model generates neither (only
   `account_id`/`status`). Both inserts now carry `phone`, `password` (`bcrypt`) and
   `Customer::STATUS_ACTIVE`, the same shape ws02/`ManagementModulesApiTest` use.

4. **The invoice fixture omitted the NOT NULL `due_date`** (same file, "invoice payments
   count towards store revenue"). `invoices.due_date` is `date NOT NULL` with no model
   default — `InvoiceController` defaults it to `now()->addDays(14)` — so the fixture now
   does the same.

5. **The category fixture omitted the NOT NULL `slug`** (same file, "the store payload
   carries the fields the legacy detail header rendered"). Unlike `products.slug`, which the
   Product boot hook fills, `categories.slug` has no generator and no default; the fixture
   now sets `'slug' => 'shoes'` (the store's first, so the unique `(store_id, slug)` index is
   satisfied).

## Unfixable / not mine to land

1. **Mounting the real tab components inside the shell.** WS-27 (six tab components), WS-35
   (`StoreWebMetricsPanel`) and WS-17 (store POS control card) have now landed, and their
   own file comments assign the mount to the orchestrator ("Mount inside StoreDetailView.vue
   (WS-03 shell) … Until the orchestrator wires them there, this module registers the
   deep-linkable tab workspace"). The shell currently renders `StoreTabPlaceholder` for
   those tabs, which is exactly what the WS-03 build note recorded as deliberate. WS-03's
   acceptance criteria (shell, hash deep links, lazy loading via `defineAsyncComponent`,
   permission-gated tabs, placeholder empty states) are met; replacing the placeholder with
   the components is a cross-workstream wiring decision, not a defect in this workstream's
   files. Left for the orchestrator.

2. **Sibling workstream tests still create customers without the NOT NULL `phone`/`password`
   columns** — the same strict-mode failure as Fixed 3/4, but in files this workstream does
   not own and must not edit concurrently: `ws21invoicesTest.php` (~line 171, also without
   `password`), `ws12orderfulfilmentTest.php` (~lines 198 and 232, both without
   `phone`/`password`) and `ad10adminaccountsTest.php` (~line 218, `password` present but
   `phone` missing). Flagged for their owners / the orchestrator's serial run.

## Verified clean (no change needed)

1. **Routes → controller methods.** The single route
   `GET stores/{store}/dashboard` → `StoreDashboardController@show(Request, Store)` exists
   with the right signature; `{store}` binds via `Store::getRouteKeyName()` = `store_id`
   (confirmed with `php artisan route:list`).
2. **Envelope + tenancy.** The method returns the house envelope (`$this->ok([...])`); every
   query is scoped to `$store->business_id` + `$store->id`; access runs through
   `authorizeStore()` (business_id + `User::accessibleStores()`), which is exactly the check
   the parent file's `EnsurePosStoreAccess` applies to `stores/{store}`; deleted stores are
   refused explicitly (owners' `accessibleStores()` does not filter status). Refusals use
   `abort(403)` like the reference `WarehouseController`.
3. **SPA calls ↔ routes ↔ api modules.** `storeDetailApi.dashboard()` calls
   `/management/stores/{id}/dashboard`, which is the registered URI; the module exports both
   `storeDetailApi` and every payload type the views import. The only other API surface the
   tab touches is WS-05's `StorefrontEnableModal` (imported, not reimplemented) and, after
   fix 2, WS-17's `posAppUrl` — both exist and are exported.
4. **Route module shape / duplicate names.** `ws03-store-detail.php` contains only the
   controller import and one `Route::middleware('permission:stores view')->group(...)` gate —
   no prefix/name wrapper (it inherits `management`, `api.management.` and the
   auth/audience/team middleware, all confirmed in the route:list middleware column). Full-app
   scan of 1107 registered routes found **zero** duplicate route names;
   `api.management.stores.dashboard` is unique. SPA-side, `stores.detail` / `stores/:id` are
   defined only here.
5. **Router / nav modules.** Router module default-exports `RouteRecordRaw[]` with the child
   path `stores/:id` (no leading slash) and `meta.title`; the nav module default-exports
   `NavGroup[]` (empty on purpose — `AppLayout` already carries the Stores entry and the
   group comment documents the alternative), matching `ws19`/`ws28`/`ws35`.
6. **Imports resolve.** Every `@/...` import in the three SFCs and three TS modules resolves
   to a real file; SFCs parse + compile (`@vue/compiler-sfc`), TS modules parse clean
   (`typescript` syntax only — not a typecheck). Vue Router `{ name: 'stores.settings' }`
   with `params: { storeId }` matches WS-04's `stores/:storeId/settings`; `transfers.index`
   (WS-15) and `storefront` (WS-05) probes match the registered routes; `orders.show`
   (`orderNumber`) exists.
7. **PHP syntax.** `php -l` clean on the controller, route module and test file.

**Test-file review (nine→eleven tests, never executed by the fleet).** Pass 1 walked the
tests against the controller, models, migrations and seeders but missed three NOT NULL
fixture gaps; pass 2 found them with a live-schema audit and fixed them (Fixed 3–5). With
those in, every insert satisfies the schema. Helper names (`ws03*`) are unique
across `tests/`; `createBusinessOwner` + Store Manager/Store Associate role expectations
match `SpatiePermissionSeeder`; `staff_assignments` pivot resolves (`user_id`,
`assignmentable_*`, live schema confirmed); `orders.customer_id`, `invoices.store_id`,
`transactions.invoice_id` and the nullable `products.quantity` all exist; low-stock counts,
revenue bucketing on `COALESCE(paid_at, created_at)`, the 6-month zero-filled series and the
8-order cap all match the assertions. Whole-value floats encode without a fraction (PHP
`serialize_precision=-1`), so `assertJsonPath(..., 7000)` / `->toBe(5000)` pass against the
`round((float) …, 2)` values — the sibling dashboard tests assert the same way.

## Observations (not defects, no change made)

- **Variant stock:** the store dashboard reads `products.quantity` directly, while WS-28,
  `ProductListController` and WS-29's `applyProductState` measure variant-driven products by
  their variant total. For stores with variants the store-scoped "low stock" list can differ
  from the business dashboard's. Wiring `StockVisibilityService::applyProductState` into this
  card would be the follow-up, and would widen the card's semantics beyond legacy parity.
- **Revenue month basis:** this card buckets on `COALESCE(paid_at, created_at)` (the honest
  payment month); the business dashboard buckets on `created_at`. Deliberate and documented
  in the controller; the WS-03 tests pin the `paid_at` behaviour.
- **Breadcrumbs:** the audit describes Dashboard → Stores → {store}; the shell renders a
  "← Stores" link only. Cosmetic.

## Commands run

`php -l` on the controller/route/test; `php artisan route:list --path=api/v1/management` and
a full `--json` duplicate-name scan (1,048 named routes, zero duplicates); `@vue/compiler-sfc`
parse + compileScript on the three SFCs and `typescript` syntax parsing + `@/` import
resolution on the three TS modules; live `SHOW COLUMNS` checks for `staff_assignments` and
every table the tests insert into (`stores`, `products`, `orders`, `transactions`,
`pos_sessions`, `invoices`, `customers`, `categories`) plus `@@sql_mode` (confirmed
`STRICT_TRANS_TABLES`); a `Symfony\JsonResponse` float-encoding check proving whole values
serialise without a fraction (so the strict `assertJsonPath(..., 7000)` / `->toBe(5000)`
expectations hold). `php artisan test` and `npm run typecheck` were not run, per the fleet
instruction.
