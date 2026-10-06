# WS-35 repair — Store Web Metrics & Storefront Analytics

**Verdict:** verified clean on this pass. All seven repair checks pass; no WS-35-owned file
required an in-place change, so nothing was edited or reformatted. Two wiring items remain
outside this workstream's file ownership (both live in WS-03's `StoreDetailView.vue` /
`StoreDashboardTab.vue` — see *Unfixable* below).

Re-verified against the implementation reported complete:

| Piece | Path |
| --- | --- |
| API controller | `app/Http/Controllers/Api/V1/Management/StoreWebMetricsController.php` |
| Route module | `routes/api/v1/management/ws35-store-web-metrics.php` |
| API test | `tests/Feature/Api/ws35storewebmetricsTest.php` (9 Pest tests) |
| SPA API module | `storify-management/src/api/modules/ws35-store-web-metrics.ts` |
| SPA panel (tab + page) | `storify-management/src/components/StoreWebMetricsPanel.vue` |
| SPA standalone view | `storify-management/src/views/StoreWebMetricsView.vue` |
| SPA router module | `storify-management/src/router/modules/ws35-store-web-metrics.ts` |
| SPA nav module | `storify-management/src/nav/modules/ws35-store-web-metrics.ts` |
| Route | `GET /management/stores/{store}/web-metrics` → `api.management.stores.web-metrics` |

## Checks

1. **Routes → existing controller method, right signature.** `ReflectionClass` confirms
   `StoreWebMetricsController@show` is public with `(Request $request, Store $store)` and a
   `JsonResponse` return — exactly what the route action references. Implicit binding
   `{store}` resolves through `Store::getRouteKeyName() = 'store_id'`, matching both the
   test URLs (`$store->store_id`) and the SPA ("`storeId` is the store route key
   (`store_id`), not the numeric id"). `store/{store}` (parent) and `stores/{store}/dashboard`
   (WS-03) cannot shadow the three-segment path.

2. **House envelope + tenant scoping.** Both exits use the house envelope: `$this->ok([...])`
   and `$this->error($message, 422, ['store' => ['no_website']])`; the `error()` argument
   order matches `ApiController::error(string, int, array)`. Every read is scoped to
   `$store->business_id` and `$store->id` (orders, revenue, series, products, activity),
   and `ResolvesManagementContext::authorizeStore()` re-checks business ownership **and**
   `accessibleStores()` (staff assignment) before any query. Deleted stores abort 403, the
   same idiom WS-03 uses. The legacy service filtered invoice revenue by store id alone;
   the new queries add the business scope — a documented tightening, not a behaviour gap.

3. **SPA calls ↔ registered routes ↔ api-module exports.** The panel's only backend call is
   `storeWebMetricsApi.get(storeId, params)` → `GET /api/v1/management/stores/{id}/web-metrics`
   (client `baseURL` ends `/api/v1`), which is the single route the module file registers; the
   api module exports `storeWebMetricsApi` and `isNoWebsiteRefusal`, both imported by the
   panel. Response readers (`data.data.store/metrics/range/web_orders_series/top_products/
   recent_activity`) match every controller key 1:1, including the `web_revenue_kobo` /
   `average_order_value_kobo` kobo convention and the `errors.store[0] === 'no_website'`
   marker the 422 handler keys off.

4. **Route-module shape / duplicate names.** `ws35-store-web-metrics.php` contains only
   `Route::` lines — no prefix/name wrapper (inherits `management`, `api.management.` and the
   auth/audience/team middleware), and the `permission:stores view` group is the required
   gate, byte-for-byte the same idiom as the WS-03 sibling module. A whole-app
   `route:list --json` scan reports **1107 routes, 0 duplicate names**; the legacy blade route
   `management.stores.web-metrics` is a different name and does not collide.

5. **Router / nav module shapes.** Router module default-exports `RouteRecordRaw[]` with one
   child (`path: 'stores/:id/web-metrics'`, no leading slash,
   `meta: { title: 'Web Metrics', permission: 'stores view' }`); the name
   `stores.web-metrics` is unique across `router/index.ts` plus all 36 module files (the
   app-wide duplicate-name scan hits only other workstreams' pre-existing `products` /
   `categories` / `plans` / `dashboard` entries). The nav module default-exports `NavGroup[]`
   (`[]`, with the type imported from `@/nav/types`) — deliberately empty because WS-35 owns
   a store-scoped tab/page and the parity nav list names no sidebar entry; the file comment
   records where an entry would belong if the sidebar is ever refactored.

6. **Imports resolve.** PHP: `ApiController`, `ResolvesManagementContext`, `ActivityLog`,
   `Currency`, `Order`, `Product`, `Store`, `Transaction`, `TransactionStatus`, `Carbon`,
   `JsonResponse`, `Request` all exist; route module imports only its controller. SPA:
   `@/api/client` (`api`, `apiErrorMessage`), `@/stores/ui` (`useUiStore` with
   `success()`/`error()`), `@/components/StatCard.vue` (`label`/`value`/`hint`/`icon`/`accent`
   with the `indigo|green|amber` accents used), `apexcharts` (dependency, imported as a
   default export exactly like `DashboardView.vue`/`StoreDashboardTab.vue`),
   `@/nav/types`, `@/views/StoreWebMetricsView.vue`, `@/components/StoreWebMetricsPanel.vue`
   — all present; `@/*` → `src/*` is configured in both `vite.config.ts` and `tsconfig.json`.

7. **PHP syntax / static compile.** `php -l` clean on the controller, route module and test
   file; `vendor/bin/pint --test` on the same three → `{"tool":"pint","result":"passed"}`.
   `@vue/compiler-sfc` `parse` + `compileScript` + `compileTemplate` clean on both SFCs; the
   three TS modules transpile without diagnostics.

## Fixes applied (in place)

**None.** Every check passed without a defect to repair; no WS-35 file was modified by this
pass. (`git status` confirms all eight WS-35 files are new/untracked and none of the shared
files — `routes/api.php`, `routes/api/v1/management.php`, `router/index.ts`, `AppLayout.vue`,
`api/endpoints.ts`, `api/client.ts` — carry WS-35 edits.)

## Notes (judgement calls, deliberately unchanged)

- **New controller, not an extension of `StoreDashboardController`.** The roadmap suggested
  extending it, but the fleet's ownership rule forbids editing existing controllers; a new
  `StoreWebMetricsController` beside it keeps the same route/response contract without
  touching WS-03's file.
- **Money:** the legacy `orders.total` / `transactions.amount` columns are decimal naira. The
  sums happen in SQL and are converted **once** at the response boundary into integer kobo
  (`(int) round($sum * 100)`); the average uses `intdiv()` on kobo, so no per-row float
  accumulation. This mirrors what the WS-03 sibling dashboard does.
- **View counters are lifetime.** `stores.views` / `products.views` are plain counters with no
  per-hit timestamps, so `from`/`to` scope orders, revenue and the chart series only; the SPA
  labels the two tiles "Lifetime" and the tests pin that behaviour.
- **`has_website` refusal is 422 + a machine-readable `no_website` marker** (not the legacy
  redirect + flash), which is exactly the deliberate departure the roadmap acceptance
  criteria asked for; the panel renders an enable-storefront state in place.
- **The legacy six-month chart window** (`subMonths(5)->startOfMonth()` → now, zero-filled,
  6 buckets) and the `StoreAnalyticsService@web` definitions (checkout source, confirmed
  transactions on checkout orders *or* store invoices, top-10 by views, last-10 activity) all
  match the legacy service and the raw audit (`mgmt-stores.md` 2.9 /
  `mgmt-storefront-styling.md` 7).
- **WS-27's cross-workstream flag is already handled.** Its repair report warned that
  `ws35storewebmetricsTest.php` might end with a still-authenticated request; the current file
  calls `$this->withoutToken()` before the final `assertUnauthorized()`, and
  `MakesHttpRequests::withoutToken()` (verified in the installed framework) removes the
  default `Authorization` header, so the assertion is sound.

## Unfixable within this workstream's ownership

1. **The Web Store tab is not mounted in `StoreDetailView.vue`.** That view belongs to WS-03;
   it still renders `StoreTabPlaceholder.vue` for `activeTab === 'web'`
   (`StoreDetailView.vue:279`, tab defined at line 44). `StoreWebMetricsPanel.vue` carries the
   required MOUNT NOTE at the top of the file with the exact snippet
   (`v-else-if="activeTab === 'web' && store"`, `:store-id="store.store_id"`,
   `:has-website="store.has_website"`). Mounting it means editing an existing view this
   workstream does not own, so it is left to the orchestrator (WS-03's own repair report
   records the same handoff for the WS-27/WS-17 components).
2. **Nothing links to the standalone `/stores/:id/web-metrics` page from inside the SPA.**
   The route is registered and deep-linkable, but the legacy entry point was the store
   dashboard's "Web Storefront" card (the same placement WS-03's
   `views/store-tabs/StoreDashboardTab.vue` renders), which is again WS-03's file. The
   orchestrator can add the link when wiring item 1; no WS-35-owned file can host it.

## Verification performed (this pass)

- `php -l` on the controller, route module and test file — no syntax errors.
- `vendor/bin/pint --test` on the same three files — passed.
- `php artisan route:list --path=api/v1/management` and `--path=stores --json` — the route
  registers as `api.management.stores.web-metrics` with middleware
  `auth:sanctum → token.audience:management → team.context → permission:stores view`.
- `php artisan route:list --json` duplicate-name scan — 1107 routes, zero duplicate names.
- `ReflectionClass` probe of `StoreWebMetricsController@show` (public, `(Request, Store)` →
  `JsonResponse`, parent `ApiController`, trait `ResolvesManagementContext`).
- `@vue/compiler-sfc` parse/compile of both SFCs and `typescript.transpileModule` of the three
  TS modules — clean.
- Static replay of every test expectation against the controller: fixture columns vs schema
  (`products.quantity`/`amount`/`views`, `orders.source`/`business_id`/`total`, nullable
  `transactions.order_id`/`invoice_id`/`payment_method_id`, `activity_logs.business_id`/
  `ip_address`, invoice NOT NULL `issue_date`/`due_date`), enum values
  (`TransactionStatus::CONFIRMED`, `InvoiceStatus::SENT`, `OrderStatus::PENDING`,
  `Store::STATUS_DELETED`), helper uniqueness (`ws35*` functions have no collisions;
  `createBusinessOwner` exists in `tests/Pest.php`), `RefreshDatabase` binding, and the
  route middleware chain. Per the fleet rule, `php artisan test` and `npm run typecheck` were
  **not** run — the 9-test suite is written but unexecuted, as the build report recorded.
