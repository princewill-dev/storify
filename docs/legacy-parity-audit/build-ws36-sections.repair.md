# WS-36 — Sections & Product↔Section — repair pass

Scope repaired: the files reported by the WS-36 implementation —

- API: `app/Http/Controllers/Api/V1/Management/SectionController.php`,
  `routes/api/v1/management/ws36-sections.php`
- Feature test: `tests/Feature/Api/ws36sectionsTest.php`
- SPA: `src/api/modules/ws36-sections.ts`, `src/router/modules/ws36-sections.ts`,
  `src/nav/modules/ws36-sections.ts`, `src/views/SectionListView.vue`,
  `src/views/SectionDetailView.vue`, `src/views/SectionFormView.vue`,
  `src/components/WarehouseSectionsPanel.vue`

Commands run: `php -l` (all three PHP files), `php artisan route:list
--path=api/v1/management` (plus `-v` middleware detail and an app-wide
`--json` duplicate-name scan: **1107 routes / 1048 named / 0 duplicate
names**), a `ReflectionClass` probe of the nine routed methods,
`vendor/bin/pint --test` on the three PHP files, `@vue/compiler-sfc` parse +
`compileScript` + `compileTemplate` over the four SFCs, `ts.transpileModule`
over the three `.ts` modules, an automated import-resolution walk, and a
**read-only** live schema audit of `storify_test` (`sections`, `warehouses`,
`stores`, `products` columns). `php artisan test` and `npm run typecheck`
were **not** run — the fleet shares one test database and one toolchain, and
the orchestrator runs them serially after the workstreams finish.

## Fixes applied

**1. `SectionController.php` failed Pint (4 fixers) while every sibling
controller passes.** `vendor/bin/pint --test` on `WarehouseController`,
`ProductFormController` and `StockVisibilityController` all report `passed`;
the WS-36 controller reported `fully_qualified_strict_types`,
`unary_operator_spaces`, `not_operator_with_successor_space`,
`ordered_imports`. Pint applied two real changes: it imported
`Illuminate\Support\Collection` and used it in the
`accessibleWarehouseIds()` docblock instead of the inline FQCN. Re-run after
the fix: `passed`.

**2. The two product-row queries never eager-loaded `images`, so every row's
`primaryImage()` call lazy-loaded the relation (N+1 up to 100 rows/page).**
`SectionController::productRow()` renders `image_url` from
`Product::primaryImage()`, which walks `$product->images`; the section detail
query loaded only `['store']` and the available-products query only
`['store', 'section']`. The sibling controllers that build rows from the same
helper (`ProductListController:81`, `ProductFormController:83`) eager-load
`'images'` for exactly this reason. Added `'images'` to both `with()` calls
(`show()` → `['store', 'images']`, `availableProducts()` →
`['store', 'section', 'images']`) with a one-line comment on the first. No
payload field changed; the same images are returned, one query per page
instead of one per row.

## Verification results

| Check | Result |
| --- | --- |
| 1. Route → controller method exists with the right signature | **PASS.** All nine module routes resolve: `picker` → `SectionController@picker`; `sections.index/store/show/update/destroy` → the matching methods; `sections.products.available/assign/unassign` → `availableProducts/assignProducts/unassignProducts`. A `ReflectionClass` probe confirms all nine are public; `route:list` prints every action. Controller parameter names match the route placeholders exactly (`Warehouse $warehouse`, `Section $section`) — the WS-29-class trap (mismatched name → empty model injected by implicit binding) is absent. |
| 2. House envelope + tenant scoping | **PASS.** Every action returns `$this->ok(...)`; the three refusal paths use `$this->error(...)` (422) or `abort(403/404)`. Every entry point authorizes the warehouse through `User::accessibleWarehouses()` with deleted warehouses excluded, and every `{section}` is checked for `business_id` **and** for belonging to the warehouse in the URL (and not soft-deleted). `store` sets `business_id`/`warehouse_id` server-side; `assignProducts` scopes candidate products by business and rejects store-less warehouse-less rows for restricted staff. All five mutations (`store`, `update`, `destroy`, `assignProducts`, `unassignProducts`) run inside `DB::transaction`. Cross-business and cross-warehouse cases are covered by tests (`403`/`404` asserted). |
| 3. SPA calls ↔ routes ↔ api-module exports | **PASS.** `sectionsApi.list/show/create/update/destroy/picker/availableProducts/assign/unassign` are all exported and all used calls map to registered routes: list → `GET warehouses/{warehouse}/sections`, show → `GET .../sections/{section}`, create → `POST ...`, update → `PUT ...`, destroy → `DELETE ...`, availableProducts → `GET .../available-products`, assign → `POST .../products`, unassign → `DELETE .../products` (body `{ product_ids }` — Laravel reads JSON bodies on DELETE via `getInputSource()`). Query params match controller validation (`q`, `status`, `per_page`, `page`; caps 100 respected by the views' options). Response fields read by the views (`data.warehouse/sections/stats`, `data.section/stats/products`, `data.assigned/removed`, `meta`) all exist in the controller payloads. |
| 4. Route module wrapper / duplicate names | **PASS.** `ws36-sections.php` declares no prefix/name/auth wrapper — only the inherited group context plus `permission:` groups (the same idiom as `ws14-*`/`ws29-*`). App-wide named-route scan: 0 duplicates; the only other `sections.*` names are the legacy web routes under the separate `management.` name prefix. `route:list -v` shows the correct per-route gates: `warehouses view` (picker/index/show), `warehouses create` (store), `warehouses edit` (update), `warehouses delete` (destroy), `products view` (available-products), `products edit` (assign/unassign) — and the SPA's `auth.can(...)` gates match those strings one for one. |
| 5. Router/nav module shapes | **PASS.** Router default-exports `RouteRecordRaw[]` as child records with relative paths (`warehouses/:code/sections`, `.../create`, `.../:sectionCode`, `.../:sectionCode/edit`), each with `meta.title`; static `create` outranks `:sectionCode` by vue-router ranking. Nav default-exports `NavGroup[]` — empty by design (the roadmap places Sections under a warehouse, not top-level), the same shape as 13 other nav modules in the fleet; the interactive panel's dashboard-tab mount is noted in its header. |
| 6. Imports resolve | **PASS.** Automated walk over the seven SPA files: every `@/` specifier exists (`@/api/client`, `@/api/modules/ws36-sections`, `@/api/endpoints`, `@/stores/auth`, `@/stores/ui`, `@/components/{AppModal,PaginationBar,StatCard,StatusBadge}.vue`, `@/nav/types`); `warehousesApi.show()` and `useUiStore().success()/.error()` exist with the used signatures; AppModal's `footer` slot and `maxWidth` prop match usage. PHP imports resolve (`route:list` loads the controller). |
| 7. PHP syntax / Pint | **PASS.** `php -l` clean on the controller, route module and test file; Pint `passed` after fix 1. |
| Vue SFC / TS syntax | **PASS.** All four SFCs parse and compile script + template cleanly; all three `.ts` modules transpile with no diagnostics. |
| Test fixture viability (schema audit, read-only) | **PASS.** On `storify_test`: `sections.section_code`, `warehouses.warehouse_code`, `stores.store_id`/`slug` and `products.product_code`/`slug` are NOT NULL but generated by their model `creating` hooks; every other NOT NULL column the fixtures write is supplied by the helpers. The WS-25-style "fixture can never insert" defect is absent. Money assertions compare against the types the API actually emits (`stock_value_kobo` int, `stock_value` string, `amount` floats). |

## Verified clean — deliberately not changed

- **Ordering by name** (section list, section products) where legacy used
  `latest()`. The SPA and the tests rely on it (ordered assertions on
  `Aisle A` / `Alpha, Beta, Gamma`), it matches the audit's note that legacy
  had no ordering controls, and it reads better; left as built.
- **The detail table does not reproduce legacy's "View" row action / product
  name link.** The product remains reachable via Edit (`products.edit`) and
  the "Add Product" deep link; adding a link is a parity addition rather than
  a wiring repair, so it was left for a follow-up rather than smuggled into
  this pass.
- **`sectionsApi.picker` is exported but unused by the SPA.** WS-14's product
  form feeds its section select from the form-options payload, so the picker
  is the documented alternate source; it is registered, gated on `warehouses
  view` and scoped to the caller's businesses/warehouses, so it stays.

## Cannot be fixed without touching a file this workstream does not own

- **The warehouse detail's Sections tab still renders its own read-only list.**
  `views/WarehouseDetailView.vue` (WS-06-owned) has no import of
  `components/WarehouseSectionsPanel.vue`, and mounting it means editing that
  existing view, which WS-36 does not own. The component's header carries the
  exact swap the orchestrator needs:
  `<WarehouseSectionsPanel v-if="tab === 'sections'" :warehouse-code="code" />`
  (the view already exposes both `tab` and `code`). Until it is wired, the
  section screens' only in-app entry point is a direct URL, because every link
  into `sections.index` lives inside that panel. This is the single item of
  reported work that is not reachable from the UI in the working tree today.
