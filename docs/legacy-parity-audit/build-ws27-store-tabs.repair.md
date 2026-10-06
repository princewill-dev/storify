# WS-27 — Store Tabs — repair pass

**Scope repaired:** the files reported by the WS-27 implementation — API
`Api\V1\Management\StoreTabController` and
`routes/api/v1/management/ws27-store-tabs.php`; the feature test
`tests/Feature/Api/ws27storetabsTest.php`; SPA
`src/api/modules/ws27-store-tabs.ts`, `src/router/modules/ws27-store-tabs.ts`,
`src/nav/modules/ws27-store-tabs.ts`, `src/views/StoreTabsView.vue` and the six
`src/views/store-tabs/Store*Tab.vue` components.

Two verification passes ran (the first repaired the test file; the second —
after a context recharge — re-ran every check against the current files and
found nothing further to change). `php -l`, `php artisan route:list` (route
detail + app-wide duplicate-name scan), Pint `--test`, and per-file
`@vue/compiler-sfc` (parse + `compileScript` + `compileTemplate`) /
`ts.transpileModule` were run in both passes; `php artisan test` and
`npm run typecheck` were **not** (the fleet shares one test database and one
toolchain — the orchestrator runs them serially).

## Verification results

| Check | Result |
| --- | --- |
| Route → controller method exists with the right signature | PASS — `GET api/v1/management/stores/{store}/tabs` → `Api\V1\Management\StoreTabController@summary(Request $request, Store $store): JsonResponse`, confirmed in `route:list` with `permission:stores view` under the inherited auth/audience/team group (route name `api.management.stores.tabs.summary`). `Store::getRouteKeyName()` is `store_id`, so the `{store}` binding matches the code the SPA/tests use |
| House envelope + tenant scoping | PASS — returns `$this->ok([...])`; `authorizeStore()` re-checks `business_id` + `accessibleStores()` and deleted stores are refused with 403; every count is scoped `business_id` + the bound store. Transactions count joins order **or** invoice store (the WS-18 filter shape), customers count uses `whereHas('orders', store)` — byte-for-byte the base query of `StoreCustomerController@index` (so the tab-strip count equals the tab's own header count) — and staff count mirrors `StaffParityController@index`'s directory clause exactly (`role in staff/owner`, `status != deleted`, `assignedStores`) |
| SPA calls ↔ routes ↔ api module | PASS — `storeTabsApi.summary/products/orders/transactions/customers/invoices/staff` all target registered routes and are either defined here or delegated to exports that exist (`orderParityApi.list`, `transactionsParityApi.index`, `customerParityApi.storeCustomers`, `staffRolesApi.staff.index`, `invoicesApi.index`). Params match each controller's validation (`products?store_id` numeric, `staff?store_id` store **code**). Every `route:list` URI behind a tab was re-confirmed this pass, including `orders/board/list`, `transactions`, `invoices`, `products` and `stores/{store}/customers` |
| Route module wrapper / duplicate names | PASS — only the expected `permission:stores view` group (the same idiom as `ws19-customers.php` / `ws25-products-list.php`); no prefix/name/auth wrapper, and no imports beyond the controller it uses. App-wide `route:list --json` scan: 1107 routes, **no duplicate names**; the `stores/{store}/tabs` URI is registered exactly once |
| Router/nav module shapes | PASS — router default-exports `RouteRecordRaw[]` (relative path `stores/:id/tabs/:tab`, `meta.title`, `meta.permission`); nav default-exports `NavGroup[]` (empty — the tabs are not sidebar destinations, matching WS-25's empty module). SPA module-name scan: `stores.tabs` unique (the only duplicate anywhere is the pre-existing `plans` name in WS-07/WS-09, not this workstream). `stores/:id/tabs/:tab` does not shadow any other registered path |
| Imports resolve | PASS — every `@/` import in the ten SPA files resolves to an existing file and the named exports used exist (`api`, `apiErrorMessage`, `useAuthStore`, `useUiStore`, `StatusBadge`, `PaginationBar`, `InvoiceStatusBadge`, `money`/`formatDate`, `NavGroup`, the five delegated api modules, and the six lazy tab components). The two PHP files' imports resolve via the route table |
| Deep-link targets exist | PASS — `/stores/{store_id}` (WS-03 shell binds the code), `/products/create?store_id=` (honoured by `ProductFormView`), `/products/:code/edit`, `/orders/:orderNumber` (base route in `router/index.ts`), `/transactions/:reference`, `/customers/:accountId`, `/invoices/:invoiceNumber`, `/staff` and `/staff/:id` all exist; `stores.settings` exists with the `storeId` param the Staff tab passes |
| PHP syntax / Pint | PASS — `php -l` on all three PHP files; `vendor/bin/pint --test` passed on them this pass too |
| Vue SFC / TS syntax | PASS — all ten SPA files parse, compile script and compile template cleanly (re-run this pass) |

## Fixes applied

**1. `ws27storetabsTest.php` — the unauthenticated assertion could never pass
(401 vs 403).** `MakesHttpRequests::withToken()` writes the `Authorization`
header into the test's `$defaultHeaders` (framework line 131), and `json()`
merges those into every later request in the same test
(`transformHeadersToServerVars()` → `array_merge($this->defaultHeaders, …)`,
framework line 645). The test therefore sent the permission-less staff token on
the "no token" request, which answered **403** for the missing `stores view`
permission, not 401. Fixed by issuing the unauthenticated request *before*
`withToken()` (with an explanatory comment), keeping both assertions.

**2. `ws27storetabsTest.php` — the products leg of the no-leak test was
vacuous.** No products were created, so
`expect(array_column($products->json('data'), 'store_id'))->each->toBe(…)`
iterated an empty array and proved nothing. Fixed by seeding one product per
store and asserting the filtered payload is exactly `['Mine Widget']` with
`store_id` equal to the scoped store's id.

**Second pass: nothing else changed.**

## Verified clean — deliberately not changed

- **Counts match the lists they label.** Each tab-strip count is computed with
  the same scope as the endpoint the tab renders: products/sales/invoices by
  `business_id`+`store_id`; transactions across order- **or** invoice-borne
  money; customers as buyers of the store (identical base query to
  `StoreCustomerController`); staff as the WS-20 directory filtered to
  `assignedStores` (owner row included, `status = deleted` excluded). The store
  is 403'd when deleted, so no count can disagree with a reachable list.
- **Permission-shaped payload.** Disabled tabs return `count: null` rather
  than leaking a size; tab permissions were checked against
  `StoreDetailView`'s tab definitions and the legacy tab-bar gates
  (products/orders/transactions/customers/invoices/staff × `view`).
- **Legacy columns/filters.** Products q/status/per-page 10/50/100 + Add
  deep-link; Sales q + the legacy seven statuses; Transactions status filter
  from the payload enum; Customers header count + per-store order counts;
  Invoices status/q/Clear with amount-paid and overdue colouring; Staff count
  line + role badges + Add Staff. Pagination stays inside each tab.
- **The standalone host is intentional.** `stores/:id/tabs/:tab` is a
  documented fallback (the components' header comments carry their
  `StoreDetailView` mount snippets); the route/meta shape and the fallback
  behaviour for a forbidden tab (`resolveTab`) are correct.

## Unfixable within this workstream's file ownership

- **The six tab components are not wired into `StoreDetailView.vue`.** That
  view is WS-03's file (not named as mine), and it still renders
  `StoreTabPlaceholder.vue` at `StoreDetailView.vue:279` for the six tabs
  (`allTabs` at lines 36–46); nothing in the shell links to the WS-27 host
  route. Fixing it means editing an existing view I do not own, so per the
  ownership rules it is left to the orchestrator: mount
  `<StoreProductsTab :store="store" />` (etc.) in the `v-else-if` chain beside
  the placeholder, with the mount snippet documented at the top of each of the
  six components.

## Cross-workstream note (not owned, not changed)

`ws35storewebmetricsTest.php` ends with the same pattern
(`$this->withToken(...)` … then `$this->getJson(...)->assertUnauthorized()`)
and has the same latent failure — the last request still carries the token and
will answer 200/403, not 401. Worth the same reorder when WS-35 is repaired.
