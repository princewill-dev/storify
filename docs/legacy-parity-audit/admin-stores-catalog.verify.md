# Verification pass — admin-stores-catalog (audience: admin / superadmin)

**Verifier method:** independently re-read the full legacy surface in scope. Routes: `routes/v1/admin_dashboard.php` lines 36–230 (all nine in-scope controllers, plus `apps/` route registration in `routes/web.php` and `routes/api/v1/admin_storefront.php`; checked against `php artisan route:list`). Controllers (full read): `Admin\{Store,Warehouse,StockTransfer,StorefrontSlide,PageStyling,Product,Category,Feature,CompanyService}Controller`, `App\Http\Requests\Admin\{ProductRequest,FeatureRequest}`, `App\Models\Store` boot/key. Views: all 22 Blade files under `resources/views/admin/{stores,warehouses,transfers,storefront_slides,styling,products,categories,features,company_services}/**` enumerated and opened (shops/index, show; warehouses/index, show; transfers/index, show; storefront_slides/index; styling/index, create, edit; products/index, create, edit, show; categories/index, create, edit; features/index + 3 partials; company_services/index). New stack: `routes/api/v1/admin.php`, `routes/api/v1/management.php`, `routes/api/v1/admin_storefront.php`, `Api\V1\Admin\{Store,Search}Controller`, `Api\V1\Management\{Product,Category,Store}Controller`, `storify-admin` (`src/router/index.ts`, `src/views/**`, `src/api/endpoints.ts`, `src/components/CommandPalette.vue`, `src/layouts/AdminLayout.vue`, `src/views/StoresView.vue`), and `storify-management/src/views/**`. Cross-checked new-API consumers of `StorefrontSlide` / `PageStyling` / `Feature` / `CompanyService` across the whole `app/` tree.

**Coverage verdict:** the audit's **legacy enumeration is complete**. Every in-scope view file (22/22), every route belonging to the nine controllers, and every controller method maps to a feature entry in §1–§4; no legacy feature in the domain is missing from the table, and no `missing` status hides a new-stack endpoint (verified: only `GET /api/v1/admin/stores` and `GET /api/v1/admin/stores/{store}` exist for this domain — plus a store lookup inside `GET /api/v1/admin/search`, see correction 5). The two `partial/partial` entries (STORE-1/2) are correctly rated; warehouses, transfers, products, categories, slides, styling, features and company services are genuinely absent from the new admin API and SPA. However the audit contains **four factual errors (three of them load-bearing for the rebuild) and several glossed legacy behaviours**; they are listed below. No whole feature was missed, so `missed_features` is empty — the findings are field-level corrections.

---

## Corrections

### 1. §5 "Dead legacy screens" — the standalone store create/edit Blade files do **not exist** (also cited in STORE-3 and STORE-4)

The status table and §5 both assert that `resources/views/admin/stores/create.blade.php` and `edit.blade.php` "exist on disk but are not routable". They are not on disk. `ls -la resources/views/admin/stores/` returns exactly two files: `index.blade.php` and `show.blade.php`; `find resources/views -path '*stores*' -name '*.blade.php'` confirms. `routes/v1/admin_dashboard.php:122` excludes `create`/`edit` from the resource, so nothing can reach them.

What actually exists is *dead controller code*: `Admin\StoreController@create` (lines 101–127) and `@edit` (lines 224–232) are unrouted methods that `return view('admin.stores.create'|'edit')` — views that are absent, so the methods would 500 if anyone ever wired a route to them. Correct the entry to: "no standalone create/edit Blade views exist; the controller's `create()`/`edit()` methods are dead code referencing missing views; the only store create/edit UI is the modals in `index.blade.php`/`show.blade.php`." The rebuild conclusion (modal-based) is unchanged, but the audit currently sends a reader looking for two files that aren't there.

### 2. §5 "Dead legacy screens" — `searchProducts` is not the `/api/superadmin` endpoint; it has no route at all

The audit says: *"`Admin\StorefrontSlideController::searchProducts` (the `/api/superadmin/stores/{store}/products` JSON endpoint) duplicates `apiListProducts`; the SPA-facing flow only uses the latter. Rebuild one search endpoint, not two."*

The route file contradicts this. `routes/api/v1/admin_storefront.php:12` maps `GET stores/{store}/products` to **`apiListProducts`** (the paginated one that adds `primary_image_path`); `searchProducts` is routed nowhere (`grep -rn searchProducts routes/` finds only the unrelated POS staff route and no admin mapping). So there is no "second live endpoint to de-duplicate" — `searchProducts` is a dead method; `apiListProducts` + `apiBulkStore` + `reorder` are the only live JSON helpers (confirmed in `route:list`: `api/superadmin/stores/{store}/products api.admin.store-products.index`). Fix the attribution in §5 and in CONTENT-1's "Legacy:" line so the rebuild targets one method, correctly named.

### 3. CATALOG-2 / CATALOG-3 — the admin product forms never exposed digital files or section/warehouse selection

CATALOG-3 lists as edit-form controls: *"delete digital files (`delete_file_ids[]`) and upload new ones"*, and CATALOG-2's server behaviour says *"digital files handled by `ProductFileService` when `is_digital`"* and *"if a section/warehouse is chosen, warehouse stock locations are created too."*

Neither `products/create.blade.php` nor `products/edit.blade.php` renders any of: `is_digital`, `download_limit`, `download_expiry_days`, `digital_files`, `delete_file_ids`, `section_id`, `warehouse_id` (case-insensitive grep for `digital|download|section_id|warehouse` returns no form controls; the full `name="` enumeration in both files contains none of them). `ProductRequest` and `ProductController` accept and act on them, so they are reachable only by hand-crafted requests — no admin UI path. Same for `cost_price`, `is_taxable`, `stock_quantity` in the request rules. The audit should say: "digital-file and section/warehouse fields are request-level only; the legacy admin UI never rendered them" — otherwise the rebuild team will either chase a UI requirement legacy never had, or (worse) assume digital-product management is part of the port. Note `ProductFileService` writes downloads to `products/downloads/{business_id|shared}` (`app/Services/ProductFileService.php:27`).

### 4. STORE-4 — the edit modal can set a store's status to `deleted`, bypassing the STORE-6 guards

The audit describes the edit form as "same 16-field form as create" and documents the main-store guard for inactive/suspended only. In fact the Edit Store status select (both `stores/index.blade.php` lines 312–317 and `stores/show.blade.php` lines 306–310) includes a **`deleted`** option, and `update()` validates status as `required|string|max:50` (free string, no enum). So an admin can move any non-main store to `status = deleted` through Edit — no order/transaction completeness checks, no confirmation copy, no `store_deleted` log line — while `DELETE /admin/stores/{store}` enforces both guards. (Create's modal offers only active/inactive, but `store()` validates the same free string.) A rebuild that ports STORE-4 and STORE-6 verbatim reproduces this divergent second delete path; decide deliberately whether to keep it.

### 5. STORE-1 / Gap 8 — store-ID search **does** work in the new admin app (via global search), contradicting "get nothing back"

Gap 8 states: *"Admins pasting a store ID into search — the identifier they actually have — get nothing back."* That is wrong for the new app. `GET /api/v1/admin/search` (`Api\V1\Admin\SearchController`, `routes/api/v1/admin.php:34`) matches stores on `name` **OR `store_id` OR `slug`** and returns the top 5; the admin SPA mounts `CommandPalette.vue` (Cmd/Ctrl-K and a header button, wired in `AdminLayout.vue:213/258`) which calls it and offers the matched store as a jump target. The stores **list endpoint** is still name-only (so STORE-1's `api_status: partial` stands), but the "no path from a store ID" conclusion — and the headline claim that the new admin API covers "exactly two things in this domain" — should be softened: a third store-touching endpoint exists. Also note the palette navigates to `/stores?q=<store name>`, so its store-ID discovery is still list-scoped, and the drawer remains read-only.

### 6. STORE-3 — `ALLOW_MS_SETUP` does more than refuse creation

The audit says the flag means "when a main store exists and multi-business setup is disabled, creation is refused". There is a second branch: when the flag is **off and no main store exists yet**, creation proceeds but `StoreController@store` ignores the chosen business for ownership — `$data['user_id']` is force-set to the superadmin's linked account (lines 166–174), and the JS-visible business choice only ever affects the pre-override `user_id`. Additionally, in **both** branches `business_id` is `unset($data['business_id'])` before `Store::create()`/`$store->update()` (lines 164–165, 280–281), so the admin create/edit screens never write the store↔business link — a store created from `/office/stores` ends up with `business_id = null` and `$store->business === null` in the new admin payload. If the rebuild keeps a Business select, specify whether it relinks the business or only reassigns the owner.

### 7. STORE-1 — the legacy "Deleted" status filter option can never return rows

The audit lists the filter as "status (all/active/inactive/suspended/deleted)". The controller applies `->where('status', '!=', 'deleted')` to the base query *before* the status filter (`StoreController@index`, line 39), so choosing Deleted always yields an empty list — a dead filter option, not a working one. Same interaction means the new list's exclusion of deleted stores is behaviourally identical for this option. Worth recording so the rebuild either drops the option or makes it work.

### 8. STORE-5 — the detail-page toggle does not mirror the index menu

The audit says "the detail header shows the same toggle" as the index row menu. `stores/show.blade.php:11-19` renders **Activate only when `status === 'suspended'`**; an inactive or pending store shows a *Suspend* button on the detail page, whereas the index menu offers Activate for suspended/inactive/pending. Cosmetic legacy inconsistency, but it is a wrong restatement as written.

### 9. Headline / status summary — feature count is 26, not 27

The headline says "24 of the 27 features below are `missing`/`missing`". The table has 26 numbered rows (STORE-1..6 = 6, CATALOG-1..9 = 9, WH-1..4 = 4, CONTENT-1..7 = 7), of which 24 are `missing`/`missing` and 2 are `partial`/`partial`. Correct to "24 of the 26".

---

## Smaller notes (no status change demanded)

- **CATALOG-1 filters:** besides `status`/`q`/`from`/`to` and the `/admin/stores/{store}/products` route, `GET /admin/products` also accepts a URL-only `?store_id=` filter (matches the numeric id or the public `st_…` id, `ProductController@index` lines 69–74). The filter modal has no store input and nothing links to it; gap #7 already alludes to it, but the feature entry's filter list should mention it if the rebuild is scoped from that list.
- **STORE-1 pagination:** legacy is 15/page; the new endpoint defaults to `per_page=20` (SPA has a per-page control in `TableFooter`). Trivial.
- **STORE-3 redirect:** `Admin\ProductController@store` redirects to `admin.stores.products.index` (the *chosen* store's product list, per the audit's own CATALOG-2 note), and `@update` likewise; `StoreController@store` redirects to the store index and `@update` honours `redirect_to`. Covered in substance.
- **Obsolescence notes verified:** `StorefrontSlide` and `PageStyling` are consumed only by legacy Blade code (`Home\ProductController` for `PageStyling`; `AppServiceProvider:312` for `Feature`); `CompanyService` is live in `Api\V1\Home\HomeController::services()` and `api/v1/storefront/{store}/services`. The audit's "no new-API consumer" claims hold.
- **`admin_storefront.php` load path:** confirmed loaded by `routes/web.php:21` with session `auth` (mounted as `api/superadmin/...`), so the audit's "legacy leftovers, not new-stack evidence" reading is correct.
- **Email/queue side effects:** confirmed no store-lifecycle mails anywhere under `app/Http/Controllers/Api/` (grep for `Mail::`), so gap #9 stands.
