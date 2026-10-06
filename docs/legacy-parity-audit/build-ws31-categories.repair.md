# WS-31 — Categories Polish & Audit Logging — repair pass

**Scope verified:** the files reported by the WS-31 implementation —

- API: `app/Http/Controllers/Api/V1/Management/CategoryParityController.php`,
  `routes/api/v1/management/ws31-categories.php`
- test: `tests/Feature/Api/ws31categoriesTest.php`
- SPA: `src/views/CategoriesView.vue`, `src/api/modules/ws31-categories.ts`,
  `src/router/modules/ws31-categories.ts`, `src/nav/modules/ws31-categories.ts`

**Commands actually run this pass:** `php -l` on all three PHP files;
`php artisan route:list --path=api/v1/management/categories --json` plus an
app-wide `route:list --json` duplicate-name scan; `vendor/bin/pint --test` on
the three PHP files; `@vue/compiler-sfc` parse + `compileScript` +
`compileTemplate` on `CategoriesView.vue`; `ts.transpileModule` on the three TS
modules; a read-only tinker probe printing the SQL of the shared trait's pluck;
and inspection of the installed vue-router 5.3.1 dist. `php artisan test` and
`npm run typecheck` were **not** run (the fleet shares one test database and
one toolchain — the orchestrator runs them serially).

## Verification results

| Check | Result |
| --- | --- |
| Route → controller method exists, right signature | PASS — `route:list --json` shows exactly 4 routes, all resolving to `CategoryParityController@index/store/update/destroy`, names `api.management.categories.*`, with `permission:products view/create/edit/delete` inside the inherited auth/audience/team group |
| House envelope + tenant scoping | PASS — every method returns `$this->ok(...)` / `$this->error(...)`; list filters `business_id` + accessible non-deleted store ids; `store()` re-checks membership (422 `Invalid store selection.`); `update`/`destroy` call `authorizeCategory()` (business_id + store access, 403 otherwise); create/update/delete run inside `DB::transaction` |
| SPA calls ↔ routes ↔ api module | PASS — `categoryApi.index/create/update/destroy` are all exported from `ws31-categories.ts` and map exactly onto the 4 registered routes; `CategoriesView.vue` calls nothing else. The axios envelope typing (`api.get<{ data; meta }>` → `data.data` / `data.meta`) matches the `Paginated<T>` convention in `src/api/endpoints.ts`. The only other consumer of the endpoint (legacy `ProductsView.vue` via `endpoints.ts`, `per_page=100`) reads only `id`/`name` and 100 is in the whitelist |
| Route module wrapper / duplicate names | PASS — no prefix/name/auth wrapper; only the required per-route `permission:` groups (WS-25 idiom). The `categories` URIs are re-registered on purpose (same technique as WS-20/WS-25/WS-30): Laravel keys routes by method+URI, so the later module registration replaces the inline `CategoryController` routes. App-wide `route:list --json`: 1107 routes, **0 duplicate names**; all four names/URIs resolve to the parity controller |
| Router/nav module shapes | PASS — router default-exports `RouteRecordRaw[]` with relative path `categories`, `meta.title` and `meta.permission`; the deliberately duplicated name replaces the shared `router/index.ts` record — verified in the installed vue-router 5.3.1 dist: `addRoute` calls `removeRoute(record.name)` for a root add and `matcherMap.set` re-points the name, so the module record (same `CategoriesView.vue`) wins and no error is raised. Nav default-exports an empty `NavGroup[]`, correct because `AppLayout.vue:62` already carries Catalog → Categories (`products view`) |
| Imports resolve | PASS — every `@/` import in the four SPA files exists; controller/trait/model imports resolve (proved by the booted route table) |
| PHP syntax / Pint | PASS — `php -l` clean on all three files; `pint --test` → `{"tool":"pint","result":"passed"}` |
| Vue SFC / TS syntax | PASS — `CategoriesView.vue` parses and compiles its script and template; `ts.transpileModule` clean on the three TS modules |

## Fixes applied

**None were required this pass.** The single repair this workstream needed is
already in the file and was re-verified:

- `CategoryParityController::storeIds()` plucks `stores.id` (qualified), at
  `app/Http/Controllers/Api/V1/Management/CategoryParityController.php:269`.
  The unqualified form is genuinely broken for restricted staff: the
  restricted-staff branch of `User::accessibleStores()` is the
  `assignedStores()` MorphToMany join against `staff_assignments`, which also
  carries an `id`. A read-only probe printed the SQL —
  ``select `id` from `stores` inner join `staff_assignments` on …`` — which
  MySQL rejects as SQLSTATE 1052, so the Store Associate GET in this
  workstream's own permission test would have 500'd instead of returning 200.
  The qualified form works on all three branches (owner HasMany, restricted
  staff MorphToMany, platform-admin Builder). No other change was made.

## Verified clean — deliberately not changed

- **Payload is a superset of the base slice minus `parent_id`.** The base
  `CategoryController` row keys were id/name/slug/store_id/parent_id/status/
  products_count; the parity row drops only the retired `parent_id` (roadmap
  D9) and normalises `products_count` to int. No test or backend code besides
  this workstream's test references the management category routes; the only
  SPA consumer reads id/name.
- **Store scoping agrees with the shell.** `storeIds()` mirrors auth's
  `storesPayload()` (`accessibleStores()` + `status != 'deleted'`), so the
  SPA filter dropdown cannot offer a store the API will 403.
- **Slug regeneration.** Regenerated only on a real rename, six random chars,
  uniqueness probe against the `(store_id, slug)` unique index; status-only
  saves leave the slug alone (matches legacy's rename behaviour).
- **`parent_id` guard (D9).** `['nullable','prohibited']` refuses non-null on
  create and update with the custom 422 message, explicit `null` still passes,
  and the field is absent from the row payload; no SPA code sends it.
- **ActivityLog.** `category_created/updated/deleted` with old/new snapshots;
  every written column exists on `activity_logs` (including the
  `business_id` added by `2026_05_30_000002`); no log is written on the 409.
- **Permissions and the 409.** Same `products *` strings the replaced inline
  routes used; delete with products keeps the documented stricter 409 and the
  SPA surfaces the hint pre-emptively from `products_count`.
- **Test file.** Helpers are uniquely prefixed (`ws31*`, no collisions —
  grep-checked); the owner/staff setup matches the reference
  `ManagementWarehouseApiTest` idiom; factory users are `email_verified_at`
  set by default and the management middleware stack does not check
  `is_verified`, so the associate happy path is not gated.

## Unfixable within this workstream's file ownership

- **`ResolvesManagementContext::accessibleStoreIds()` carries the same latent
  ambiguity** (`app/Http/Controllers/Api/V1/Management/Concerns/ResolvesManagementContext.php:27`
  — `->accessibleStores()->pluck('id')`). Any controller that calls it 500s
  (SQLSTATE 1052) when the requester is a restricted staff member, and it is
  used by controllers from several other workstreams (OrderController,
  ProductController, InvoiceController, DispatchController,
  PaymentSettingsController, StaffParityController, CustomerSearchController,
  OrderParityController). It is shared infrastructure consumed across
  workstreams and is not named as a WS-31 file, so it was left untouched to
  avoid clobbering a concurrent owner; WS-31 itself no longer calls it. The
  one-word fix is `->pluck('stores.id')`.
