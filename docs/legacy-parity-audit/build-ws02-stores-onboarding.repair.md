# WS-02 — Store Onboarding & Slug — repair report

**Scope:** the WS-02 implementation as reported — `StoreOnboardingController`, `routes/api/v1/management/ws02-stores-onboarding.php`, `tests/Feature/Api/ws02storesonboardingTest.php`, and the management SPA files `src/api/modules/ws02-stores-onboarding.ts`, `src/router/modules/ws02-stores-onboarding.ts`, `src/nav/modules/ws02-stores-onboarding.ts`, `src/views/{StoresView,StoreCreateView,StoreCreatedView}.vue`.

**Method (this pass):** re-read every WS-02 file and the shared dependencies it relies on (`ApiController`, `ResolvesManagementContext`, `CreateStoreRequest`, `CreateStore`, `ReservedStoreSlug`, `Store`, `StoreBank`, `Customer`, `Order`, `User`, `Business::hasActiveSubscription`, `StoreType`, the shared parent route file, WS-05's `StorefrontController::checkSlug`, and the shared SPA router/layout/stores/components). Cross-checked fixture assumptions against the live `storify_test` schema read-only via `information_schema` (NOT NULL columns + defaults for `stores`, `customers`, `orders`, `store_banks`, `users`, the `store_bank`/`staff_assignments` pivots, and the unique indexes on `stores.support_email`/`support_phone`/`slug`/`store_id`). Ran `php -l` on all three PHP files, `php artisan route:list --path=api/v1/management` plus a full `route:list --json` duplicate-name scan (1107 routes / 1048 named), reflected the four controller methods, and syntax-checked the three `.vue` SFCs (`@vue/compiler-sfc` parse + `compileScript`) and the three `.ts` modules (`typescript.transpileModule`, syntax only). Did **not** run `php artisan test` or `npm run typecheck`, per instructions.

**Verdict: repaired in place — no defects remain that WS-02 files can fix.** This pass found no new defects in the controller, route module, SPA modules/views or imports; the two test-file fixes carried in the working tree were re-verified as correct and are recorded below. Three items that need files owned by other workstreams are recorded under §4.

---

## 1. Routes and controller — verified sound

`php artisan route:list --path=api/v1/management` shows all four WS-02 routes registered with the inherited prefix/name/middleware stack (`api` → `auth:sanctum` → `ThrottleRequests:api` → `EnsureTokenAudience:management` → `SetPermissionsTeamId` → permission):

| Route | Name | Permission | Action (reflected) |
|---|---|---|---|
| `GET stores/onboarding/list` | `api.management.stores.onboarding.list` | `stores view` | `index(Request)` |
| `GET stores/onboarding/options` | `api.management.stores.onboarding.options` | `stores create` | `createOptions(Request)` |
| `POST stores` | `api.management.stores.store` | `stores create` | `store(CreateStoreRequest, CreateStore)` |
| `GET stores/{store}/finalize` | `api.management.stores.finalize` | `stores view` | `finalize(Request, Store)` |

- Every route points at a method that exists with a resolvable signature (verified by PHP reflection on `StoreOnboardingController`, parent `ApiController`).
- The module file adds only per-route `permission:` groups (the house gating form, identical to sibling modules such as `ws04`/`ws05`); there is no `prefix()`/`name()`/auth wrapper, so it correctly inherits `management` + `api.management.` from the parent group.
- Route names are unique platform-wide: `route:list --json` reports zero duplicate names across all 1107 registered routes. `stores.store` under `api.management.` exists exactly once; the same name under `api.admin.` (admin module) and in the unloaded legacy `routes/v1/management.php` is a different namespace.
- The three-segment paths (`stores/onboarding/*`, `stores/{store}/finalize`) are required because the parent registers `GET stores/{store}` **before** the `glob()` of feature modules; the module's header comment documents this. No other module registers a colliding URI (`stores/onboarding/*` appears only here).
- `POST stores/check-slug` is correctly **not** duplicated: WS-05 registers it (`api.management.storefront.check-slug`, no permission gate, matching legacy's pre-store use) and its `{data: {available, slug, url, original}}` contract matches the WS-02 SPA type and tests.
- Envelope + tenancy: all four methods return `$this->ok(...)`/`$this->error(...)`; `finalize` gates on the house `authorizeStore()` (business check + `accessibleStores()` membership, 403); the list uses `$request->user()->accessibleStores()`; options scope banks/staff by `business_id`; creation delegates to `CreateStore`, which runs inside `DB::transaction` and re-derives `business_id`/`user_id` from the authenticated owner. Bank and staff ids from the request are validated against the caller's business before the action. No `vendor` naming; money stays integer kobo (`balance` cast to int).

## 2. SPA wiring — verified sound

| View call | Module export | API route |
|---|---|---|
| `storeOnboardingApi.list` | `ws02-stores-onboarding.ts` | `GET /management/stores/onboarding/list` |
| `storeOnboardingApi.options` | same | `GET /management/stores/onboarding/options` |
| `storeOnboardingApi.create` | same | `POST /management/stores` |
| `storeOnboardingApi.finalize` | same | `GET /management/stores/{store_id}/finalize` |
| `checkStoreSlug` | same | `POST /management/stores/check-slug` (WS-05) |

- Response types match the envelopes actually returned (`data.stores` + top-level `meta` for the list; `data.data` unwrapping for options/finalize/create; `data.data.store.store_id` on create before pushing `{name: 'stores.finalize', params: {id}}`).
- Router module default-exports `RouteRecordRaw[]`, children of `/`, no leading slashes, both routes carry `meta.title` (`stores.create`, `stores.finalize`). Names are unique among all SPA router modules; `stores/create` precedes (and outranks) WS-03's `stores/:id` so the static segment wins.
- Nav module default-exports `NavGroup[]` importing `@/nav/types`; `AppLayout` glob-merges `src/nav/modules/*.ts` and filters empty/permission-less groups, so the `stores create`-gated item disappears for staff.
- Links resolve: `/stores/{store_id}` → WS-03 `stores/:id` (param matches `Store::getRouteKeyName()`), `/stores/{id}#settings` → WS-03's hash sync (verified in `StoreDetailView.vue`), `/orders?store_id={id}` → `OrdersView` reads the query param.
- All imports resolve on disk (`@/api/client`, `@/api/modules/ws02-stores-onboarding`, `@/stores/{auth,ui}`, `@/components/{StatusBadge,PaginationBar}.vue`, `@/nav/types`); `@` → `src` is configured in `tsconfig.json` and `vite.config.ts`. `StatusBadge` accepts `status`; `PaginationBar` accepts `meta` and emits `page`, matching the view usage. `StoresView.vue` is the component mounted by the shared router at `/stores`.

## 3. Fixes in place (tests/Feature/Api/ws02storesonboardingTest.php) — re-verified

1. **Customer fixtures carry the NOT NULL `phone` and enum-safe `status`** (lines 95/97 and 105/107). `customers.phone` is `varchar(255) NOT NULL` with no default in `storify_test` and the connection runs MySQL strict mode, so the two `Customer::create` calls in "the store list counts the distinct customers behind its orders" would have thrown a `QueryException`; `status` is an `ENUM('ACTIVE','SUSPENDED','DELETED')`, so `Customer::STATUS_ACTIVE` is the correct literal.
2. **`$this->flushHeaders()` before the guest assertion** (line 306). `withToken()` writes into `$this->defaultHeaders` and every later request merges those defaults, so the guest `postJson` would have been sent as the permission-less staff user and returned 403 instead of the asserted 401.

No other fixture mismatch found: every `Store::create` / `User::factory()` / `StoreBank::create` / `Order::create` in the file satisfies the NOT NULL columns (the `store_id`, `account_id`, `order_number`, `uuid` values are generated by model boot hooks/factories), and the `store_bank` / `staff_assignments` pivots accept the `attach`/`sync` payloads.

## 4. Out-of-scope findings (recorded, not fixable from WS-02-owned files)

1. **Sibling tests create customers without the NOT NULL `phone` (some without `password`)** — they will fail the serial suite for their own workstreams: `ws03storedetailTest.php:83,89`, `ws21invoicesTest.php:165,540,548`, `ws28dashboardwidgetsTest.php:178`, `ws12orderfulfilmentTest.php:198,232`, `ad10adminaccountsTest.php:218`. Those files belong to other workstreams; only their owners (or the orchestrator) can apply the same fixture fix.
2. **`GET /management/stores` (the existing `StoreController@index`) was not extended** with the parity payload/filters; the new `stores/onboarding/list` endpoint serves the SPA instead. This is what the file-ownership rules require ("write a NEW controller … beside it") and the repair checklist only covers the reported routes, so no change is possible from WS-02 files without editing another workstream's controller.
3. **Slug-check throttle parity (plan text).** The route is owned by WS-05 (`ws05-storefront-enable.php`); registering a second route on the same URI from the WS-02 module would shadow WS-05's controller (ws02 loads first). Current state is not a gap: `route:list` shows `POST stores/check-slug` already carries the api-group `ThrottleRequests:api` middleware, and the legacy route had no route-level throttle either — so no legacy-parity loss, only the legacy "pre-store, pre-permission" placement which WS-05 deliberately kept by registering it without a permission gate.

## 5. Verification performed

- `php -l` clean on `StoreOnboardingController.php`, `ws02-stores-onboarding.php`, `ws02storesonboardingTest.php`.
- `php artisan route:list --path=api/v1/management` — all four WS-02 routes present with the expected names/permissions/actions; `route:list --json` duplicate-name scan returns 0 duplicates application-wide (1107 routes, 1048 named).
- Reflection check of the four controller methods and their parameter types.
- Read-only `information_schema` checks of every table the controller and tests touch (NOT NULL columns, defaults, unique indexes).
- SFC parse/compile of the three WS-02 views and TS transpile of the three WS-02 modules (syntax only).
- Static review of every SPA call against registered URIs and the exporting module; imports resolved on disk; router/nav module shapes checked against their glob consumers.
