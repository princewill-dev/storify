# Verification — Catalog Organisation (business / management)

**Verifier pass (independent re-scan):** `routes/v1/management.php` (catalog groups + sections resource), `Management/{Category,Service,Section}Controller.php`, all 10 Blade files under `management/{categories,services,sections}/**`, `Category`/`Service`/`Section` models + migrations; new API (`routes/api/v1/management.php`, `Api/V1/Management/CategoryController.php`, `ProductController::rules()`), business SPA (`CategoriesView.vue`, `router/index.ts`, `endpoints.ts`, `AppLayout.vue`, `ProductsView.vue`), admin SPA, `SpatiePermissionSeeder`, and the storefront services read-side.

**Verdict:** the audit's inventory is structurally complete. All 10 in-scope views and all 13 catalog routes map to a feature entry; every status checked (services/sections api+spa "missing", categories api "exists" but genuinely functional, feature 15/16 "partial") is accurate. No management service/section/warehouse API or SPA exists, and the storefront `ServicesView`/`ServiceDetailView` really do consume `Api\V1\Storefront\CatalogController@services`, so the audit's headline gaps stand. The findings below are corrections to specific claims plus one cross-cutting behaviour the audit omits entirely.

## Corrections

1. **Service list — the "no stores" warning banner never rendered; that branch errors.**
   - Audit says (feature 6): "An amber warning banner with a link to 'Create a Store' appeared when the user had no accessible stores."
   - Reality: `ServiceController@index` passes the warning as *view data* (`view(...)->with('warning', …)`; `Illuminate\View\View::with()` merges `$data` only — it does not flash the session), while `services/index.blade.php:15` reads `session('warning')`. Worse, that branch (`ServiceController.php:32-40`) omits `$breadcrumbs`, which the view dereferences in `<x-management.page-header :breadcrumbs="$breadcrumbs" …>` (line 5). With Laravel's handler (`error_reporting(-1)`, `handleError` throws on warnings) an undefined variable throws; I compiled the Blade and reproduced `ViewException: Undefined variable $breadcrumbs` semantics. So `/management/services` with zero accessible stores 500s, and the banner cannot appear even on the flash path (the redirect from `create()` lands back in the same broken branch). Rebuild as an empty state + create-store CTA; don't port this as "working".

2. **Category list — "store column" is not a legacy feature.**
   - Audit says (feature 1, repeated in Gap #4): "Missing in the new stack: … slug column, store column."
   - Reality: the legacy table header is Name / Slug / Products / Status / Actions (`categories/index.blade.php:16-20`) — there is no store column. Only the slug column is a genuine parity gap; the store appeared solely as the edit modal's selector (already covered by feature 3).

3. **Category edit — slug regeneration on rename was dropped without note.**
   - Legacy `CategoryController@update` regenerates the slug whenever the name changes (`slug = Str::slug(name).'-' + 6-char uuid`, lines 167-169). The new API `update()` validates only `name`/`parent_id`/`status` and never touches `slug`, so renames leave a stale storefront-facing slug. Feature 3's Missing list should state this divergence so it is a deliberate choice, not an accident.

4. **Section detail — the "Add Product" prefill is broken at an earlier point than attributed.**
   - Audit (feature 12) attributes the failure to `section_id = $section->section_code` vs numeric ids resolved by `ProductController@store`.
   - Reality: `ProductController@create` never reads `section_id` from the query string at all (`products/create.blade.php:56` selects only via `old('section_id')`), so the prefill fails even if a numeric id were passed; the code-vs-id mismatch is a second, independent bug (it would corrupt the value if the prefill ever worked).

5. **Service create — no `primary_image` control exists in the form.**
   - Audit (feature 7) says images had "an optional primary flag (`primary_image` input)".
   - Reality: `services/create.blade.php` has only the multi-file input; nothing in the legacy UI sends `primary_image`. The first image becomes primary because `(int) null === 0` happens to equal the first file's array index — not through the "if no primary was marked" fallback the audit describes.

6. **Service edit — update() writes `store_id`, `currency_id` and `status` with no validation.**
   - Audit (feature 8) presents "change the owning store" and the status switch as normal validated capabilities.
   - Reality: legacy `update()` validates only name/amount/images (`ServiceController.php:219-226`) then persists `$request->only(['store_id','name','description','amount','currency_id','status'])` — any store id, any status string. `create()` does validate store access; `update()` does not. A legacy bug the rebuild must not port (validate store accessibility and enum status/currency).

7. **Category list — the `store_id` filter uses a different identifier than legacy.**
   - Audit (feature 1): "The controller supports a `store_id` query filter (public store id…)" and "API supports `store_id`".
   - Reality: legacy `?store_id=` is the **public** store code (`stores.store_id`, resolved through `accessibleStores()->where('store_id', …)`), while the new API filters `where('store_id', $request->integer('store_id'))` on the **internal** `stores.id` (same convention as products/orders in the new stack). Same parameter name, different identifier — wire the SPA filter with `auth.stores[].id`, and don't reuse the legacy public-code semantics.

## Missed — one cross-cutting legacy behaviour omitted from every feature entry

**Permission-gated catalog access (products / warehouses scope).** Nothing in the audit mentions that catalog screens are permission-gated end to end:
- the sidebar Products group (All Products / Categories / Services) is hidden from users without `products view` (`sidebar.blade.php:78`);
- "Add Service" is hidden without `products create` (`services/index.blade.php:7`);
- all category/service routes sit behind `products view|create|edit|delete` middleware, so a staff member with only `products view` gets a 403/login redirect even by URL (`routes/v1/management.php:223-242`);
- the entire sections resource sits behind `warehouses view` (`routes/v1/management.php:459-464`), with `SectionController` additionally allowing only the warehouse owner or staff assigned to that warehouse and aborting 403 otherwise.
The new stack already replicates this for categories (API middleware `permission:products *`, SPA `auth.can('products …')`), and `SpatiePermissionSeeder` defines `products view/create/edit/delete` and `warehouses view` (no orders-style seeding gap). The omission matters for the services/sections builds: new endpoints must reuse `products *` / `warehouses view` + warehouse ownership checks, not ship ungated.

## Minor / editorial

- Feature 6's "Missing" list contains a garbled fragment ("…, `products…` no wait, image payload)").
- Legacy service routes bind by `service_code`, sections by `section_code` (`getRouteKeyName`); the new management API uses numeric ids — decide the service/section route/API key before building.
- Both legacy service views hardcode a ₦ prefix while the model carries `currency_id` (the storefront payload uses `currency.symbol ?? '₦'`); the rebuild should use the currency relation.
- No `duration` field exists anywhere in the legacy services schema/migrations, and no page/content-section entity exists (only the warehouse-zone `Section`) — the audit's scope note is correct on both counts.
