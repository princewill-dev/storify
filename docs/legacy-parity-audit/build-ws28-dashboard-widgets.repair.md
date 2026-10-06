# WS-28 — Dashboard Widgets & Store Switcher — repair pass

Scope: verify the WS-28 implementation in place, fix what is broken, record what
cannot be fixed without touching a file this workstream does not own.

Files under verification (all absolute paths):

- API: `app/Http/Controllers/Api/V1/Management/DashboardWidgetsController.php`,
  `routes/api/v1/management/ws28-dashboard-widgets.php`,
  `tests/Feature/Api/ws28dashboardwidgetsTest.php`
- Management SPA: `src/views/DashboardView.vue`,
  `src/api/modules/ws28-dashboard-widgets.ts`, `src/stores/storeScope.ts`,
  `src/components/DashboardStoreSwitcher.vue`,
  `src/router/modules/ws28-dashboard-widgets.ts`,
  `src/nav/modules/ws28-dashboard-widgets.ts`

Verification performed: `php -l` on the controller, route module and test file
(clean, including after this pass's edits); `php artisan route:list
--path=api/v1/management` plus a full `route:list --json` sweep of all 1107
routes for duplicate names and duplicate URIs; source review of every owned
file against the models, enums, relations, migrations and shared SPA modules it
depends on, including a field-by-field cross-check of every payload key the
view reads against the controller's keys and the API module's types; every SPA
import resolved on disk; deep-link targets checked against the route records
other modules actually declare; the test file checked against the real schema
(migrations for orders/order_items/transactions/pos_sessions/stock_transfers/
stock_transfer_items/stock_locations/product_variants/products/stores/
warehouses) and against `SpatiePermissionSeeder` role configs; `git status` in
both repos to confirm no file owned by another workstream was touched.
`php artisan test` and `npm run typecheck` were deliberately not run (the
fleet shares one test database and one toolchain).

---

## Fixed in this pass

### 5. The store-scope pluck crashed for platform admins (superadmin / CFO)

`index()` derived the pluck column from the relation:

```php
$storeRelation = $user->accessibleStores();
... ->pluck($storeRelation->getRelated()->getQualifiedKeyName())
```

`User::accessibleStores()` returns a *relation* for owners and assigned staff
but a **plain `Illuminate\Database\Eloquent\Builder`** for platform admins
(`Store::where('status', '!=', 'deleted')` — see `User::accessibleStores()` /
`isPlatformAdmin()`). `getRelated()` exists only on
`Illuminate\Database\Eloquent\Relations\Relation`; on the builder the call
falls through `Eloquent\Builder::__call` → `Query\Builder::__call` →
`throwBadMethodCallException`, i.e. a 500. The branch is reachable: a
superadmin with `business_id = null` or any user holding the seeded “Chief
Financial Officer” role hits it, both of them pass the route's
`permission:dashboard view` gate (`Gate::before` covers superadmins; the CFO
role is seeded with `dashboard view`), and `ManagementAuthController::login`
issues management-audience tokens to admins.

Fixed by taking the key from the model, which is correct for all three shapes
(owner `HasMany`, assigned-staff `MorphToMany`, platform-admin `Builder`):

```php
->pluck((new Store)->getQualifiedKeyName())
```

The qualified key is still required for the `MorphToMany` shape: the pluck
joins `staff_assignments` (which also has an `id`), and Laravel's
`stripTableForPluck` only strips the qualifier when reading the returned rows,
not in the generated SQL.

### 6. Test asserted a fixed reference against a process-wide sequence

The Walk-in test asserted `data.recent_transactions.0.reference` equals the
literal `'WS28-TXN-1'`, but `ws28Transaction()` builds references from a
function-static counter (`'WS28-TXN-'.$sequence`) that is process-global — it
is not reset between tests. Tests 1–4 run before it and had already consumed
six values, so the row under assertion carried `WS28-TXN-7` and
`assertJsonPath` (strict `assertSame`) would have failed.

Fixed by asserting the created row's own reference:

```php
$transaction = ws28Transaction(ws28Order($store), 1200);
...
->assertJsonPath('data.recent_transactions.0.reference', $transaction->reference);
```

This is the only assertion in the file that depended on a generated sequence;
every other asserted order/transaction code is passed explicitly by the test
(`IKEJA-1`, `YABA-1`, `MINE-1`, `THEIRS-1`, `DELETED-1`, `WS28-ITEM`).

### Verified in place (applied before this pass, confirmed present)

1. The Transfer Requests card reads the top-level `pending_transfer_count`
   (the controller deliberately never ships `stats.transfers`), so the card
   renders and the view type-checks.
2. Entering the `dashboard.overview` route resets the persisted store scope to
   “All Stores”, so `/dashboard` is deterministic per browser.
3. The test's customer fixture supplies the NOT NULL `email`, `phone` and
   `password` columns (`customers.status` is left to the model hook).
4. The out-of-stock test fixture is created at quantity 1 and dropped to 0 with
   a direct `DB::table('products')->update(...)`, avoiding the Product model's
   `$saving` guard (the fleet's ws03 precedent).

---

## Verified clean (no change needed)

**Route → controller method.** The route module registers exactly one route:
`GET dashboard/widgets` → `DashboardWidgetsController@index`, which exists with
the signature `index(Request): JsonResponse`. `route:list -v` confirms the
inherited stack: `api`, `auth:sanctum`, `throttle:api`,
`token.audience:management`, `SetPermissionsTeamId`,
`PermissionMiddleware:dashboard view`. No duplicate route names in the
1107-route sweep, no duplicate URI, and `api.management.dashboard.widgets` is
registered only here.

**Route module hygiene.** No prefix/name/auth wrapper — only the per-module
`permission:dashboard view` group, the same shape as `ws17-pos-oversight.php`
and `ws29-stock-visibility.php`. Imports only the controller. The literal
`dashboard/widgets` URI cannot shadow or be shadowed by the parent file's
`GET dashboard`.

**Envelope and tenant scoping.** `index()` returns `$this->ok($data)`; the only
other exits are the framework 422 validation response and the deliberate
`abort(403)` (the same idiom as `ResolvesManagementContext::authorizeStore`).
`store_id` is validated (`nullable|integer` → 422 non-integer, including
`not-a-number`) and intersected with the non-deleted `accessibleStores()` set
→ 403, never trusted. Every query is business-scoped and, where a location
applies, restricted to the accessible store/warehouse id sets: orders,
transactions (via order *or* invoice store), customers, products, stock
locations, warehouses, staff, POS sessions, transfers, recent panels and the
low-stock panel. Per-card gating uses the legacy permission strings, all
present in `SpatiePermissionSeeder`.

**SPA ↔ API contract.** The view's single call (`dashboardWidgetsApi.index` →
`GET /management/dashboard/widgets`) maps to the route above and to
`src/api/modules/ws28-dashboard-widgets.ts`, which exports the function; the
client's `API_BASE_URL` already ends in `/api/v1`, so the resulting URL is
exact. Every key the view reads exists in the controller payload and in the
module's types (`scope`, `stats.*`, `recent_orders`, `recent_transactions`,
`revenue_series`, `pending_transfer_count`, `transfer_list`, `recent_staff`,
`warehouses`, `low_stock`). All deep links resolve to routes other modules
declare: `/products`, `/orders`, `/orders/{order_number}`, `/transactions`,
`/transactions/{reference}` (ws18), `/customers`, `/warehouses`,
`/warehouses/{code}`, `/staff`, `/stores`, `/inventory/transfers?status=pending`
and `/inventory/transfers/{code}` (ws15), `/pos/sessions` (ws17),
`/dashboard/{id}` (this module).

**SPA router module.** Default-exports `RouteRecordRaw[]`; both records are
children of `/` with relative paths and `meta.title`; the names
(`dashboard.overview`, `dashboard.store`) and the `dashboard` path are unique
across the shell router and every module. The shell's own `/` route is named
`dashboard` (the combined overview), so there is no name collision.

**Nav module.** Default-exports `NavGroup[]` (empty by design — the shell's own
overview entry already links the dashboard and the switcher is a header
control), which is exactly the shape `AppLayout.vue`'s glob loader consumes.

**Imports resolve.** PHP: `ApiController`, `ResolvesManagementContext`, the ten
models and four enums all exist with the members used. SPA: `StatCard`,
`StatusBadge`, `DashboardStoreSwitcher`, `SubscriptionBanner`,
`KycDashboardBanner`, `@/stores/auth` (`stores`, `can`, `user`),
`@/stores/ui` (`success`/`error`/`info`), `@/stores/storeScope`, `@/lib/money`
(`money`, `currencySymbol`, `formatDate`), `@/api/client`
(`api`, `apiErrorMessage`), `@/nav/types` — all verified; `apexcharts` is a
declared dependency.

**Tests.** 13 Pest tests; helper names (`ws28Token`, `ws28Store`, …,
`ws28Staff`) are unique across `tests/`; the token helper and fixtures follow
the sibling idiom (`ManagementWarehouseApiTest`). Required columns for every
table the helpers write are covered by model hooks or explicit attributes
(checked against the migrations; `orders.customer_id` and
`transactions.payment_method_id` are nullable in the current schema,
`order_items.product_code` is nullable, generated codes all come from model
hooks). Permission expectations match the seeder: owner = all permissions;
Cashier holds `dashboard view` but not `transactions view` / `stores view` /
`staff view` / `warehouses view` / `transfers view`, which is what the
restricted-staff and permission-gate assertions build on.

**PHP syntax.** `php -l` clean on the controller, route module and test file.

---

## Notes — real, but not defects to fix here

1. The transfer card's hint ("Pending approval") matches the legacy copy, but
   `pending_transfer_count` counts pending **and** approved transfers (the same
   set the panel lists). Left as-is: both states await action, and count and
   panel agree by construction.
2. The payload's top-level `pos.open_sessions` session **list** is not rendered
   by the view — WS-17 3.3 asks for the KPI card, which the stats block renders.
   Nothing reads the top-level list yet; dropping it would be an API change.
3. `ProductListController`'s `low_stock` filter also counts inactive products
   while the dashboard panel (like the legacy dashboard) counts active ones.
   WS-29 owns reconciling the one documented low-stock definition.
4. Platform admins inherit `accessibleStores()` / `accessibleWarehouses()` from
   the shared `User` model, which for them is unfiltered. WS-28 layers
   `business_id` on every business-data query and uses the accessible sets as
   the location boundary — exactly like `WarehouseController@index` and the
   shell `DashboardController`. The shared model's definition was not changed
   here.

---

## Unfixable

1. **`AppLayout.vue` mount of `DashboardStoreSwitcher`.** The component carries
   its mount note, and `DashboardView.vue` mounts it in the page header so it
   is fully functional today, but the sticky-header placement belongs to the
   shared layout file this workstream must not edit (and which `git status`
   confirms is untouched). The orchestrator wires the layout copy and moves the
   dashboard-header mount rather than keeping both.
