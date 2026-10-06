# AD-07 — Dashboard parity completion (WS7) — repair report

**Verdict:** repaired. Two defects found and fixed; every other reported piece
verifies. Two items remain for the orchestrator because they need shared files
this workstream does not own (recorded below).

## Files inspected (reported implementation)

API
- `app/Http/Controllers/Api/V1/Admin/DashboardParityController.php`
- `routes/api/v1/admin/ad07-dashboard-parity.php`
- `tests/Feature/Api/ad07dashboardparityTest.php`

Compared against (read-only): `app/Http/Controllers/Api/V1/ApiController.php`,
`app/Http/Controllers/Api/V1/Admin/DashboardController.php` (shadowed by the new
module), `app/Http/Controllers/Admin/AdminDashboardController.php` (legacy),
`routes/api/v1/admin.php`, `AdminApiActivityLogger`, `IsPlatformAdmin`,
`SetPermissionsTeamId`, `SpatiePermissionSeeder`, `ActivityRecorder`,
`tests/Feature/Api/AdminApiTest.php`, and the Store/Order/Transaction/Product/
StockTransfer/StockLocation/PosSession/KycApplication/Customer models.

SPA (storify-admin)
- `src/views/DashboardView.vue`
- `src/api/modules/ad07-dashboard-parity.ts`
- `src/router/modules/ad07-dashboard-parity.ts`
- `src/nav/modules/ad07-dashboard-parity.ts`

Consumers read (read-only): `src/router/index.ts`, `src/layouts/AdminLayout.vue`,
`src/api/endpoints.ts`, `src/api/client.ts`, `src/lib/format.ts`,
`src/stores/ui.ts`, `StatusBadge.vue`, `EmptyState.vue`.

## Checks run

- `php -l` on the three PHP files — clean before and after the edits.
- `php artisan route:list --path=api/v1/admin/dashboard -v` — exactly one entry:
  `GET|HEAD api/v1/admin/dashboard`, name `api.admin.dashboard`,
  `Api\V1\Admin\DashboardParityController@index`, chain `api → auth:sanctum →
  throttle:api → EnsureTokenAudience:admin → SetPermissionsTeamId →
  permission:admin.dashboard → IsPlatformAdmin → AdminApiActivityLogger`.
- Global name audit: `php artisan route:list --json` over all 1107 registered
  routes — **zero duplicate route names**, so the module's re-registration of
  `dashboard` (which shadows the shared `admin.php` registration at runtime)
  leaves one URI entry and no clobbered name.
- Route module contains only `Route::` lines — no prefix/middleware wrapper. Its
  only imports are the controller and the `AdminApiActivityLogger` class it
  references (no alias exists for the logger in `bootstrap/app.php`, so the
  class import is required).
- Controller method/class imports all resolve; the house envelope is used
  (`ok()` / `error()`, signatures match `ApiController`); the store filter is
  validated and returns a 422 with `errors.store_id`; every referenced model
  constant/relation/enum exists (`Store::STATUS_*`, `activePosSession`,
  `OrderStatus`, `TransactionStatus`, `TransferStatus` cases + `label()`,
  `PosSession::STATUS_OPEN`, `KycApplication::STATUS_SUBMITTED`,
  `Warehouse::STATUS_DELETED`, `User::ROLE_BUSINESS_OWNER`,
  `Customer::full_name`, StockTransfer `fromLocation`/`toLocation`, ActivityLog
  `metadata` cast).
- Read-only smoke of `index()` against the dev DB (temp script, deleted after):
  no filter → 200 with all 16 top-level payload keys; `days=7` → 200;
  `store_id=<numeric id>` and `store_id=<st_ code>` → 200 with `filters`
  populated and `top_stores` narrowed to 1; `from`/`to` +
  `low_stock_threshold=5` → 200; unknown store code → 422. No SQL errors on any
  store-scoped branch (donut `whereHas`, store table, transfers).
- Cross-check against the fleet's existing `AdminApiTest`: its dashboard test
  asserts only keys the new payload still carries (`stats.*` superset,
  `recent_businesses`, both daily series, both monthly series, `range_days`
  30/7 lengths), and its superadmin / management-token cases still resolve
  through the new middleware stack. Test helper function names (`ad07*`) do not
  collide with any other test file's top-level functions.
- SPA syntax: `typescript.transpileModule` (syntax-only, not a project
  typecheck) over all three TS modules and the `DashboardView.vue` script block
  — clean. Every import resolves (`@/api/client`, `@/api/modules/ad07-…`,
  `@/stores/ui`, `@/lib/format`, `StatusBadge`, `EmptyState`, `apexcharts`
  ^4.5.0 is a dependency). The API module targets `/admin/dashboard`, which the
  client's `/api/v1` base URL maps onto the registered route.
- Money convention: the admin SPA's `formatMoney` is the naira-decimal helper
  the endpoint's sums already used (same as `TransactionsView`/`OrdersView`);
  no kobo/decimal mismatch, sums run in SQL, PHP only casts for output.
- Vue Router name audit: `dashboard.alias` is unique across `index.ts` and all
  router modules; the redirect preserves query/hash; path `dashboard` (no
  leading slash) is a child of `/` and inherits `requiresAuth` from the shell.
- Nav module exports `[]`, which the `AdminLayout.vue` glob flat-maps into its
  sections safely; the comment documents the badge wiring it exists for.

## Fixes applied

1. `DashboardParityController::index` — `store_id` is now validated as
   `['nullable', 'string']` and read from `$validated` instead of
   `$request->query()`. Previously `?store_id[]=x` reached
   `(string) $rawStoreId`; the PHP array-to-string warning is escalated by
   Laravel's error handler to an `ErrorException` (HTTP 500) instead of the
   intended 422. A non-scalar now yields a normal validation 422 with
   `errors.store_id` (smoke-verified).
2. `src/router/modules/ad07-dashboard-parity.ts` — added
   `meta: { title: 'Dashboard' }` to the alias redirect record so the module
   satisfies the router-module contract (array of child routes, `meta.title`,
   no leading slash).

## Verified as correct (no change needed)

- The route re-registration is safe and intentional: Laravel's RouteCollection
  keys on method + domain + URI, so the later module registration replaces the
  shared one; `route:list` shows exactly one entry and no duplicate names
  anywhere in the route table.
- Scoping matches verify correction C1 exactly: the four legacy-scoped KPI
  tiles, stock value/counts, both daily charts, the payment donut, the pending
  transfers list and the store table honour `store_id`; the businesses/stores/
  customers counts, the stats grid, the transfer counters and the recent
  feeds stay platform-wide. The business-scoped "Super Admin" role leaks
  `admin.*` permission names, and `platform.admin` closes that hole.
- Legacy read-out parity: low-stock band restored to `1..10` and made
  configurable; store table lists every non-deleted store with today/MTD pulse,
  POS live/offline and last-sale; panels keep the legacy limits (5 transfers,
  10 confirmed transactions, 10 orders) and statuses; the payload is a superset
  of both the legacy view variables and the previous API payload consumed by
  `AdminLayout.vue`.
- The SPA view's filter bar, maybe-link guard, chart lifecycle and
  auto-refresh timer are all sound; the deep links to screens owned by other
  workstreams degrade to inert elements until those routes exist.

## Left for the orchestrator (shared files — not editable here)

- `routes/api/v1/admin.php` still carries the original
  `Route::get('dashboard', DashboardController@index)` registration. The
  module's identical method+URI registration shadows it deterministically
  (confirmed with `route:list`), but the dead line and
  `Api\V1\Admin\DashboardController` can be retired when the shared file is
  next edited.
- Sidebar badges (Businesses › KYC Submissions = `stats.kyc_pending`,
  Commerce › All Orders = `stats.orders_pending`) need mounting in the shared
  `src/layouts/AdminLayout.vue`; the payload already exposes both counts and
  the nav module carries the mount hint. AD-07 deliberately exports `[]`
  because the layout already owns the Dashboard node.
- Not run here per fleet rules: `php artisan test`, `npm run typecheck` (the
  orchestrator runs them serially).
