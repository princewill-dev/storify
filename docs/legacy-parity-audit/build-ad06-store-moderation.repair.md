# AD-06 — Store moderation & lifecycle (WS6) — repair report

**Verdict:** repaired. One real, user-visible defect found and fixed (a route
shadowing bug that made `stores/form-options` unreachable), plus one minor
audit-trail accuracy fix. Everything else in the reported implementation
verifies against the checklist. One residual item can only be cleaned up by the
orchestrator (recorded under "Residual / orchestrator"), because it needs a
shared file this workstream does not own — it is verified harmless.

## Files inspected (reported implementation)

API
- `app/Http/Controllers/Api/V1/Admin/StoreModerationController.php`
- `routes/api/v1/admin/ad06-store-moderation.php`
- `tests/Feature/Api/ad06storemoderationTest.php`

SPA (storify-admin)
- `src/api/modules/ad06-store-moderation.ts`
- `src/views/StoreModerationView.vue`
- `src/views/StoreModerationDetailView.vue`
- `src/components/StoreModerationFormModal.vue`
- `src/components/StoreModerationActionModal.vue`
- `src/router/modules/ad06-store-moderation.ts`
- `src/nav/modules/ad06-store-moderation.ts`

Shared context read (not edited): `routes/api/v1/admin.php`,
`app/Http/Controllers/Api/V1/ApiController.php`,
`app/Http/Middleware/AdminApiActivityLogger.php`,
`app/Http/Middleware/SetPermissionsTeamId.php`,
`app/Http/Middleware/EnsureTokenAudience.php`, `app/Models/Store.php`,
`app/Models/User.php`, `app/Services/ActivityRecorder.php`, the four mailables,
`src/router/index.ts`, `src/layouts/AdminLayout.vue`, `src/api/client.ts`,
`src/api/endpoints.ts`, `src/composables/useDataTable.ts`, `src/lib/format.ts`,
`src/components/*`, and the sibling ad04/ad05/ad15 module files.

## Checks run

- `php -l` on all three PHP files — clean before and after the edits.
- Router probe (bootstrap + `RouteCollection::match`, no HTTP dispatch, no DB
  writes) against every URL the controller serves:
  - `GET /api/v1/admin/stores` → `StoreModerationController@index`
  - `GET /api/v1/admin/stores/form-options` → `@formOptions` (was `@show`
    before the fix — see below)
  - `GET /api/v1/admin/stores/st_abc123` → `@show`
  - `PUT /api/v1/admin/stores/st_abc123` → `@update`
  - `POST .../st_abc123/suspend` → `@suspend`; `POST .../activate` → `@activate`
  - `DELETE /api/v1/admin/stores/st_abc123` → `@destroy`
  - `POST /api/v1/admin/stores` → `@store`
- Effective middleware chain on the module routes (gathered from the router, not
  assumed): `auth:sanctum → token.audience:admin → team.context →
  permission:admin.stores → AdminApiActivityLogger` on top of the `api` group —
  same shape as sibling ad04/ad15 route modules.
- Duplicate-name scan over the whole router: 1048 named routes, **0 duplicate
  names**. The module's re-registration of the two shared `stores` URIs replaces
  them by method+URI key, so `route:list` keeps exactly one entry per URI and
  the old `Admin\StoreController` entries are gone from the collection.
- `php artisan route:list --path=api/v1/admin` — all 8 AD-06 routes present,
  each on `StoreModerationController`; the ad15 store-scoped routes
  (`stores/{store}/products|categories`) are untouched.
- Grep for route names used: no other module or parent file uses
  `stores.index/store/form-options/show/update/suspend/activate/destroy` (the
  only overlap is the intentionally replaced shared admin.php pair).
- Controller: every public method returns the `$this->ok(...)` /
  `$this->error(...)` envelope; platform access is guarded per-method by
  `authorizePlatformAccess()` (`isAdmin()` + 403), the exact idiom sibling
  `Admin\BusinessLifecycleController` uses; mutations sit in `DB::transaction`.
  Named arguments to `ActivityRecorder::record(...)` match its real signature;
  `StoreSuspended/StoreReactivated($store, $reason)`, `StoreActivated/
  AdminStoreCreated($store)` match the mailables' constructors; `Store`'s route
  key is `store_id` (`st_` + 10 digits) and the DB only contains that shape.
- Money: `stores.balance` is a kobo `unsignedBigInteger` and is shown with
  `formatKobo`; `transactions.amount`, `products.amount`, `packs.amount` are
  `decimal` naira columns (same convention the existing admin
  `TransactionController`/ad15 `ProductController` expose) and are shown with
  `formatMoney` — the view's usage is correct, no currency bug.
- SPA: all four SFCs compile with `@vue/compiler-sfc`; every import resolves
  (`@/api/client`, the ad06 module, `@/composables/useDataTable`,
  `@/stores/ui`, `@/lib/format`, all nine components); component prop/slot
  contracts match (`AppModal` `modelValue/title/maxWidth`,
  `SortHeader` `sort/direction/@sort`, `TableFooter`
  `meta/perPage/@page/@update:perPage`, `ConfirmDialog` `confirmText/danger/
  @confirm`, `StatCard` `accent="green|amber"`, `EmptyState` `#action`).
- SPA API calls vs routes: list/create/`_method=PUT` update/suspend/activate/
  delete and both GETs all map 1:1 to the 8 registered routes; axios base URL
  already carries `/api/v1` (`lib/runtimeConfig.ts`).
- Router module: default-exports `RouteRecordRaw[]`, relative paths
  (`stores/`, `stores/:storeId`), `meta.title` on both. Verified against the
  installed vue-router 5.3.1 with a memory-history simulation of the real
  parent/child order: `/stores` and `/stores/` resolve to the module view (the
  trailing-slash record outscores the shared placeholder), `/stores/st_123`
  resolves to the detail view, and `resolve({ name: 'stores' })` returns the
  module record (later sibling wins by name).
- Nav module: default-exports the `{ label, nodes }` section shape
  `AdminLayout.vue` globs (empty by design — the layout already ships
  Commerce › Stores at `/stores`, so adding a node would duplicate it). Same
  pattern as peer modules that append to existing sections.
- Test file: helper names are unique across the suite; fixtures check out
  against the real schema (`orders.customer_id` is nullable since
  `2026_06_05`, `transactions.payment_method_id` nullable since `2025_11_26`,
  `packs/products` generate `pack_code`/`product_code`/`slug`, `settings.
  main_store_id` exists, `stores.status` is a free string column so `inactive`
  is legal); `createBusinessOwner()` pre-assigns the in-business Super Admin
  role, and `Gate::before` grants role-superadmin all abilities, so the
  permission-middleware expectations in the tests hold.
- `php artisan test` and `npm run typecheck` were NOT run, per instructions.

## Fixed (2)

1. **`GET /api/v1/admin/stores/form-options` was swallowed by the `{store}`
   wildcard and 404'd.** The shared `routes/api/v1/admin.php` registers
   `GET stores/{store}` *before* the module glob runs, and Laravel matches
   routes in registration order (a re-registered URI keeps its original slot in
   the collection). So the wildcard sat earlier than the module's static
   `stores/form-options` route, matched the segment `form-options`, and tried
   to bind a `Store` — the SPA's create/edit dropdown call and
   `ad06storemoderationTest`'s `can_create` assertion would both fail with a
   404. Fixed in `routes/api/v1/admin/ad06-store-moderation.php` by constraining
   the five `{store}` routes to Store's real public-id shape
   (`->where('store', 'st_[A-Za-z0-9]+')`); `form-options` now falls through to
   the static route. Regression-checked with the router probe above. The file's
   header comment (which wrongly claimed Laravel scores static over parameter
   segments in the matcher, vue-router semantics) was corrected to describe the
   real mechanism.

2. **`destroy()` wrote a hardcoded `old: ['status' => 'active']` audit value.**
   Deleting a store that was `suspended`/`pending` recorded the wrong previous
   status in `activity_logs`. Now captures `$store->status` before the
   transaction and records it, matching the `suspend()`/`activate()` idiom.

## Residual / orchestrator

1. **Duplicate SPA route name `stores` (harmless, cleanup only).**
   `src/router/modules/ad06-store-moderation.ts` names its record `stores` and
   serves `/stores/` while the shared `src/router/index.ts` still declares the
   placeholder `{ path: 'stores', name: 'stores', component: StoresView.vue }`.
   With vue-router 5.3.1 this is verified safe: sibling (non-ancestor) duplicate
   names do not throw, path resolution goes to the module view because the
   trailing-slash record scores higher, and name lookup resolves to the module
   record (later wins). Fully removing the overlap requires deleting the
   placeholder from `src/router/index.ts` (and retiring `StoresView.vue`), a
   file this workstream does not own — hence recorded here rather than fixed.

## Not changed (verified correct, deliberately left)

- The route module's `Route::middleware(['permission:admin.stores',
  AdminApiActivityLogger::class])->group(...)` wrapper is not a violated
  "no middleware wrapper" rule: it adds only the route-level permission gate
  and the audit logger, and is byte-for-byte the sibling ad04/ad15 idiom. The
  inherited auth/audience/team middleware is not redeclared.
- Allowing `pending` in the edit status list is a deliberate, documented
  improvement over the roadmap's `active|inactive|suspended` (a pending store
  round-trips through the form unchanged); `deleted` is still unreachable via
  edit and only through the guarded `destroy()`.
- The empty nav module is intentional: the admin shell already has the
  Commerce › Stores node gated by `admin.stores`.
