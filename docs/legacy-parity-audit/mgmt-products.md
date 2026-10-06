# Legacy Gap Audit — mgmt-products (business audience)

**Domain:** Product management (business/management UI)
**Legacy source of truth:** `/Users/mac/Desktop/my_files/work/storify/storify-api` — `app/Http/Controllers/Management/ProductController.php`, `app/Http/Requests/Admin/ProductRequest.php`, `resources/views/management/products/**`, plus `StoreTabController` products tab.
**New API:** `/Users/mac/Desktop/my_files/work/storify/storify-api/app/Http/Controllers/Api/V1/Management/ProductController.php` (+ `routes/api/v1/management.php`).
**New SPA:** `/Users/mac/Desktop/my_files/work/storify/storify-management/src/views/ProductsView.vue` (route `/products`), `src/api/endpoints.ts`.

**Headline:** the new stack has a working product list + basic create/edit modal, single status toggle and single delete. Everything else in the legacy product workflow — bulk actions, the full create/edit form (warehouse/section, cost price/VAT/discount/bulk pricing, attributes, variants, image management, featured/COD), the tabbed product detail page, store-scoped product browsing, warehouse-context creation, and the accounting/audit side effects of saving a product — is missing or materially thinner.

Status vocabulary: `exists` = fully usable in new stack; `partial` = present but missing actions/fields/validations from legacy; `missing` = absent from new stack.

---

## Feature inventory

### 1. Product list with search, filters, columns and pagination
- **What the user could do (legacy):** browse all products for their accessible stores; search by name, product code or category name (debounced, autofocus); filter by status (all/active/inactive) and by store (public store id); clear filters. Table columns: row checkbox, Product (name → detail page), Store, Section, Source (warehouse), Price (variant min–max range; strike-through original + "-X%" when discounted), Stock (amber warning when ≤10), Status badge, Code, Actions (view / edit / delete). Server also supports a created-at `from`/`to` range and a `per_page` whitelist of 10/50/100, but neither is surfaced in this view's UI; no pagination controls are rendered (the data-table pagination slot is unused), so the list effectively shows the newest 100 products with no page navigation. Sorting is fixed to newest-first (no sortable columns).
- **Legacy route/controller:** `GET /management/products` → `Management\ProductController@index`
- **Legacy views:** `resources/views/management/products/index.blade.php`, `resources/views/components/management/data-table.blade.php`, `status-badge.blade.php`
- **New API:** `GET /api/v1/management/products` — **partial**. Has q (name/product_code/brand), store_id, warehouse_id, status, category_id, digital_only; paginates (per_page default 20, unbounded). Missing: `from`/`to` date range, category-name search (legacy searched category name; new searches brand instead), variant min–max price aggregates, discount display data, `has_variants` flag, currency symbol per product, views count. Payload exposes image_url and is_digital badge data.
- **New SPA:** `src/views/ProductsView.vue` (`/products`) — **partial**. Filters: q, status, store + Filter button; columns: Product (thumb, name, code, Digital badge), Price, Stock (∞ for digital), Status, Actions (Edit / Activate-Deactivate / Delete); PaginationBar. Missing vs legacy: category filter, warehouse filter, variant price range and discount display, Store/Section/Source columns, low-stock highlight, row selection checkboxes, per-page selector, clickable product name → detail, currency-aware formatting (SPA hardcodes `₦`).
- **Side effects:** legacy writes `Log::info('business.products.viewed')` on every list load.
- **Effort / priority:** M / P1

### 2. Bulk edit products (price, stock, status per selected row)
- **What the user could do (legacy):** select any number of products with checkboxes (select-all), a floating bulk action bar appears showing "N selected"; open Bulk Edit modal listing each selected product with editable Price, Stock and Status inputs; apply changes in one submit. Tenant-scoped per product; returns "N product(s) updated successfully."
- **Legacy route/controller:** `POST /management/products/bulk-update` → `ProductController@bulkUpdate`
- **Legacy views:** `management/products/index.blade.php` (bulk bar + `#bulkEditModal`, `openBulkEdit()` JS)
- **New API:** none — **missing**
- **New SPA:** none — **missing**
- **Effort / priority:** M / P1

### 3. Bulk activate / deactivate
- **What the user could do (legacy):** select products and click "Mark Active" or "Mark Inactive" in the floating bar; server validates status in (active,inactive), scopes to the user's stores, and reports "N product(s) activated/deactivated."
- **Legacy route/controller:** `POST /management/products/bulk-status` → `ProductController@bulkStatus`
- **Legacy views:** `management/products/index.blade.php` (`#bulkActivateForm`, `#bulkDeactivateForm`)
- **New API:** none — **missing**
- **New SPA:** none (only single-row toggle exists) — **missing**
- **Effort / priority:** S / P1

### 4. Bulk delete
- **What the user could do (legacy):** select products, click "Delete Selected", confirm a modal showing the count ("Delete N selected products? This cannot be undone."), submit `product_ids`; each owned product's stored images and digital files are deleted before the row (report: "N product(s) deleted"). Ownership checked per product, so out-of-scope ids are skipped.
- **Legacy route/controller:** `POST /management/products/bulk-delete` → `ProductController@bulkDestroy`
- **Legacy views:** `management/products/index.blade.php` (`#bulkDeleteModal`, `openBulkDelete()`)
- **New API:** none — **missing**
- **New SPA:** none — **missing**
- **Side effects:** deletes image files on `public` disk + digital files for every product.
- **Effort / priority:** S / P2

### 5. Product create/edit form (core details, organization, pricing, inventory, status)
- **What the user could do (legacy):**
  - **Create** (`create.blade.php`): Basic Info (name required, brand, description textarea); Organization (warehouse required for physical products, section optional, with "products are first received at a warehouse" help); Pricing (amount, cost price, discount %, currency select defaulting to business default, "Charge VAT" checkbox default on, bulk min. quantity + bulk price); Product Type (digital toggle); Digital Files card (multi-file upload, download limit per buyer, link expiry days, defaults from config); Inventory (stock quantity required, initial stock); Attributes (size + size unit, color, weight + weight unit, comma-separated tags); Product Images (multi upload, first becomes primary, "up to 5" copy); Settings sidebar (Active, Featured, COD Available, Has Variants). No store selector on create (store assigned later on edit). If "Has Variants" is ticked on create there is no variant editor and validation (`variants required`) blocks submit — a legacy UI quirk.
  - **Edit** (`edit.blade.php`): adds Organization selectors store (incl. "None (warehouse-only)"), category, section, warehouse, status; remaining quantity + initial stock with sold hint; full digital file management (list existing with size and stored/missing-on-disk state, delete checkboxes, add more, limits); pricing amount + currency, discount, cost price, bulk min qty, VAT toggle; attributes size/weight/color/tags; **Has Variants** toggle with full variant row editor (see #6); Existing Images gallery with per-image Primary radio + Delete checkbox, Add Images upload; COD + Featured; rich-text Description via Trix editor.
  - Saving: physical products require a warehouse; section auto-fills warehouse; cost price syncs into accounting inventory costing; images/digital files persisted; activity log written.
- **Legacy route/controller:** `GET /management/products/create`, `POST /management/products`, `GET /management/products/{product}/edit`, `PUT /management/products/{product}` → `ProductController@create/store/edit/update`; validation `App\Http\Requests\Admin\ProductRequest`
- **Legacy views:** `management/products/create.blade.php`, `management/products/edit.blade.php`, `components/management/form-input.blade.php`, `card.blade.php`
- **New API:** `POST /api/v1/management/products`, `PUT /api/v1/management/products/{product}` — **partial**. Accepts name, store_id (required on create), warehouse_id, section_id, category_id, brand, description, tags, status, amount, quantity, stock_quantity, cost_price, discount_percentage, bulk_quantity, bulk_price, is_digital, is_taxable, featured, has_variants, cod_available, download_limit/expiry, images, digital files, delete ids, primary_image_id, limited variants. Missing: `size`, `size_unit_id`, `weight`, `weight_unit_id`, `color`, `currency_id` rules (legacy single-SKU attributes cannot be persisted via the API even though the model has the columns); warehouse required for non-digital products (legacy enforced); `InventoryCostingService::syncFromCostPrice()` call so cost price feeds accounting (silent side-effect regression); `ActivityLog` create/update entries; legacy's rich upload-failure messaging; legacy's `primary_image` handling nuance (first upload becomes primary only). Section→warehouse auto-fill does exist via the `Product` model `saving` hook, so passing section_id alone still sets warehouse_id.
- **New SPA:** `ProductsView.vue` create/edit `AppModal` — **partial**. Fields present: name, store, price, category, digital toggle, stock quantity, download limit/expiry, digital files upload + existing-file delete, images upload, plain-text description, status. Missing: brand input (present in form state but no `<input>` rendered), warehouse and section selectors, cost price, discount %, VAT toggle, bulk quantity/price, size/weight/color/tags attributes, featured and COD toggles, variants, existing-image management (view/delete/set primary), rich-text description, currency selection.
- **Side effects (legacy):** accounting inventory-cost sync on cost price; activity log (`business_create_product` / `business_update_product`); file storage under `products/images` and digital files. New API has **none** of the activity-log/costing side effects.
- **Effort / priority:** M / **P0**

### 6. Product variants management
- **What the user could do (legacy):** on edit, toggle "Has Variants" and get an inline repeatable variant editor — each row has size + size unit, weight + weight unit, color, SKU, quantity (required >0), amount (required >0) + currency, status (active/inactive), featured checkbox, Remove button, "Add Variant" button; existing variants preload; disabling variants deletes them. Variant price range shown on the detail Variants tab. Store/update keeps existing variant ids, deletes removed rows; validation requires `variants` (min 1) whenever `has_variants` is set.
- **Legacy route/controller:** `POST /management/products`, `PUT /management/products/{product}` → `ProductController@store/update`; rules in `ProductRequest`
- **Legacy views:** `management/products/edit.blade.php` (`#variantsSection`, `variantRowTemplate`, add/remove JS)
- **New API:** variants accepted on store/update — **partial**. Only `sku`, `color`, `size`, `quantity`, `amount`, `status` persisted; missing `size_unit_id`, `weight`, `weight_unit_id`, `currency_id`, per-variant `featured` (hardcoded false); no "has_variants ⇒ variants required" validation (a product can be flagged variant-based with zero variants); quantity allows 0 while legacy required >0; update deletes and re-creates all variants (`replace`), churning ids and losing any per-variant identity; show payload for variants only returns id/sku/amount/quantity/status.
- **New SPA:** none — **missing** (no way to see, add, edit or even flag variants; `has_variants` is never sent).
- **Effort / priority:** M / P1

### 7. Product images management
- **What the user could do (legacy):** create — multi-upload (PNG/JPG/JPEG/WEBP), first image becomes primary, per-file upload error reporting; edit — view existing image gallery, choose a new Primary via radio, tick Delete on any image (file is removed from storage), add more images (appended with positions). Images (primary thumb) appear in list/detail.
- **Legacy route/controller:** create/update in `ProductController@store/update`; `ProductImage` rows with `is_primary`/`position`
- **Legacy views:** `management/products/create.blade.php` (upload card), `management/products/edit.blade.php` (Existing Images + Add Images cards), `show.blade.php` (Images card/tab)
- **New API:** `POST/PUT /api/v1/management/products…` — **partial**. Uploads, `delete_image_ids`, `primary_image_id` all implemented; `show` returns image list with url/is_primary. Missing: nothing structural — minor gaps only (no count guidance, create-time `primary_image` legacy field unsupported).
- **New SPA:** `ProductsView.vue` modal — **partial**. Can upload new images on create/edit, but cannot see existing images, delete an existing image, or change the primary image; no thumbnails/gallery in the modal (legacy's core image-management workflow).
- **Side effects:** storage deletes on removal + product delete.
- **Effort / priority:** S / P1

### 8. Digital product files management
- **What the user could do (legacy):** mark a product digital (COD auto-disabled); upload multiple bundled files (PDF/EPUB/MOBI/ZIP/DOC/DOCX/MP3/MP4/WAV, size-limited by config); set download limit per buyer and link expiry days (config defaults shown); on edit, list existing files with formatted size and "stored / missing on disk" status, tick Delete per file, and add more files.
- **Legacy route/controller:** store/update via `ProductController`, `App\Services\ProductFileService`; validation in `ProductRequest` (+ upload-error diagnostics)
- **Legacy views:** `management/products/create.blade.php`, `management/products/edit.blade.php` (Digital Files cards)
- **New API:** `POST/PUT /api/v1/management/products…` — **exists** (digital_files upload, delete_file_ids, download_limit/download_expiry_days, files list in `show`).
- **New SPA:** `ProductsView.vue` modal — **partial**. Upload + delete existing files + limits all present, but no stored/missing-on-disk indicator, no dedicated file listing (files only appear as delete checkboxes while editing), no default-value hints.
- **Effort / priority:** S / P2

### 9. Product detail / preview (tabbed show page)
- **What the user could do (legacy):** open `/management/products/{product}` and see:
  - **Overview tab:** Product Details (store, category, section, source warehouse, brand, slug, color, size+unit, weight+unit, views count, tags, rendered description), Pricing & Stock card (price with discount strike-through + % badge; initial stock; remaining; sold units; stock-level bar with Good/Medium/Low bands; bulk min qty + bulk price), Settings card (has variants, featured, COD, discount), Images card with primary ring, created/updated timestamps.
  - **Variants tab:** count in tab label, price range header, table of SKU, size, weight, color, qty, price (with currency symbol), status.
  - **Images tab:** full image grid with IMG{id} labels and primary badge, plus Metadata card (code, slug, created, updated, views).
- **Legacy route/controller:** `GET /management/products/{product}` → `ProductController@show`
- **Legacy views:** `management/products/show.blade.php`
- **New API:** `GET /api/v1/management/products/{product}` — **partial**. Detailed payload has name/code/slug/brand/description/amount/quantity/is_digital/is_taxable/status/featured/category_id/store_id, cost/discount/bulk/download fields, variants (id/sku/amount/qty/status only), files, images. Missing: store/category/section/warehouse **names**, tags, color/size/weight + units, stock_quantity, cod_available, has_variants, views, sold quantity, stock percentage/level, variant dimensions/units/currency/featured, price ranges.
- **New SPA:** none — **missing**. No product detail route or view; the product name in the list is not a link; the only way to inspect a product is the edit modal (which itself shows no stock math, variants, images, or metadata).
- **Effort / priority:** M / P1

### 10. Single-product status toggle (activate/deactivate)
- **What the user could do (legacy):** `PUT /management/products/{product}/status` with status active/inactive; message "Product activated/deactivated". **No legacy Blade UI called this route** — it was API-only/dead in the business UI (the UI only had bulk status and the edit form's status select).
- **Legacy route/controller:** `PUT /management/products/{product}/status` → `ProductController@updateStatus`
- **Legacy views:** none
- **New API:** `PUT /api/v1/management/products/{product}/status` — **exists**
- **New SPA:** `ProductsView.vue` per-row Activate/Deactivate button — **exists** (this actually exceeds legacy UI)
- **Effort / priority:** S / P2 (no work required)

### 11. Single-product delete
- **What the user could do (legacy):** row delete action opens a confirm modal; on confirm, stored images and all digital files are deleted, then the product row is hard-deleted (no soft delete/archive anywhere in legacy); redirect back with "Product deleted."
- **Legacy route/controller:** `DELETE /management/products/{product}` → `ProductController@destroy`
- **Legacy views:** `management/products/index.blade.php` (per-row `confirm-modal`)
- **New API:** `DELETE /api/v1/management/products/{product}` — **exists** (deletes image rows + files + product in a transaction)
- **New SPA:** `ProductsView.vue` Delete button with `confirm()` — **exists** (weaker confirm UX than legacy modal, same effect)
- **Side effects:** image/file storage cleanup (both stacks).
- **Effort / priority:** S / P2 (no work required)

### 12. Store-scoped product list (store detail "Products" tab)
- **What the user could do (legacy):** from a store's detail page, open the Products tab: search q (name/code), filter status, choose 10/50/100 per page, paginate with "Showing X–Y of Z", see thumbnail/name/code, section, price (incl. variant range), stock (red when ≤5), status; "Add" deep-links to product create for that store. Also `GET /management/stores/{store}/products` as a standalone store-scoped list route.
- **Legacy route/controller:** `GET /management/stores/{store}/tab/{tab}` (`tab=products`) → `Management\StoreTabController@show/products`; `GET /management/stores/{store}/products` → `ProductController@index`
- **Legacy views:** `management/stores/tabs/products.blade.php`, `management/stores/show.blade.php` (tab shell)
- **New API:** `GET /api/v1/management/products?store_id=…` — **exists** (store filter supported; also warehouse_id filter).
- **New SPA:** **missing** — the management SPA has no store detail page at all (`/stores` is a list only; routers has no `stores/:id`); there is no products tab. The global product list can filter by store as a workaround.
- **Side effects:** none.
- **Effort / priority:** M / P2 (depends on a store detail page existing — cross-domain with mgmt-stores)

### 13. Warehouse-context product creation
- **What the user could do (legacy):** from a warehouse page, "Add Product" → create form with the warehouse preselected, a "Warehouse Context" card (name, code, address), warehouse-scoped breadcrumbs, and back-link returning to the warehouse.
- **Legacy route/controller:** `GET /management/warehouses/{warehouse}/add` → `ProductController@create`
- **Legacy views:** `management/products/create.blade.php` (`$preselectedWarehouse`, `Warehouse Context` card)
- **New API:** none specific (store accepts `warehouse_id`, but nothing preselects it) — **missing**
- **New SPA:** none — **missing**. The SPA product form has no warehouse/section fields at all, so products created in the new stack are always warehouse-less; downstream receiving/stock-transfer flows have no warehouse assignment to work with.
- **Effort / priority:** S / P2 (warehouse/section fields themselves are the P0 part of #5)

---

## Legacy quirks worth knowing (not necessarily to port)

- **Product list pagination is broken in legacy UI:** `per_page` defaults to 100 and the pagination slot is never rendered, so stores with >100 products can't page through the main list (only the store tab paginates). The new SPA paginates properly — an improvement.
- **`from`/`to` created-date filters and `per_page` whitelist exist in the legacy controller but are not exposed in the products index UI.**
- **No sorting UI in legacy** — always newest-first.
- **Create form has a "Has Variants" checkbox but no variant editor**; ticking it makes submit fail validation (`variants required`). Variants can only be managed on edit.
- **Create form has no store selector** (products were created warehouse-first with `store_id` null until edited); the new SPA requires a store and does not support warehouse-only products. Decide which model wins before porting.
- **The legacy single-status route had no UI**; the new SPA's per-row toggle is a net-new improvement (keep it).
- **Archive/soft delete never existed** — legacy `destroy`/`bulkDestroy` are hard deletes with file cleanup. "Archive" as a concept does not exist to port; the closest is status inactive.
- **Duplicate product: never existed** in legacy for the business panel (no route, controller method or UI).
- **Import/export: never existed** for products in the business panel (no `app/Imports`, `app/Exports` product code, no CSV/Excel/print view, no export button). Nothing to port; if the business wants it, it is new scope, not a parity gap.
- **Date filters, sortable columns, print views:** none existed for products beyond what's listed above.
- Product images helper copy says "up to 5 images", but neither legacy nor new code enforces a limit.

## Gaps worth calling out (ranked)

1. **Create/edit form is a skeleton of legacy (#5) — P0.** No warehouse/section on create or edit; no cost price (so the new API also silently skips the accounting `InventoryCostingService` sync legacy performed); no discount %, VAT toggle, bulk quantity/price; no attributes (size/size-unit/weight/weight-unit/color/tags) even though the API payload supports some of these at model level — the API rules themselves omit size/weight/color/currency; no featured/COD toggles; brand input missing from the modal despite being in form state. A business cannot faithfully enter a product's real selling data.
2. **Variants are unreachable in the SPA and degraded in the API (#6).** No editor at all; API drops size units, weight, currency and featured, allows variant-flagged products with no variants, and replaces variants wholesale on every update (id churn).
3. **All three bulk workflows are gone (#2, #3, #4).** Legacy's checkbox + floating action bar (bulk edit price/stock/status, mark active/inactive, bulk delete) has no equivalent endpoint or UI.
4. **No product detail page in the SPA (#9), and the API detail payload is thin** — no store/category/section/warehouse names, no stock math (sold, %, level), no tags/color/size/weight, no views, and variant rows lack dimensions/units/currency. Reviewing a product before pricing/stock changes currently means an incomplete edit modal.
5. **Side-effect regressions on save (#5):** activity-log entries (`business_create_product`, `business_update_product`) and cost-price→inventory-costing sync existed on legacy store/update and are absent from the new API. This will surface as accounting/costing and audit-trail gaps.
6. **Currency handling regressed (#1):** the SPA hardcodes `₦` and the API list payload omits currency and variant min–max/discounted price display, which legacy computed (currency symbol, range, strike-through original price, discount %).
7. **Image management is upload-only in the SPA (#7):** cannot view, delete, or re-order/set primary for existing images — a daily catalog task.
8. **Store-scoped browsing (#12) and warehouse-context creation (#13) missing;** the store tab is the only place legacy surfaced per-store product pagination, and products created in the new stack have no warehouse, which blocks downstream stock flows.
9. Lower-priority parity items: legacy's detailed image-upload error messages (`ProductRequest::failedValidation`), digital file "missing on disk" indicator (#8), and the unexposed legacy date-range filter.

**Out of scope note:** the superadmin product manager (`Admin\ProductController`, `admin.products` permission in `routes/v1/admin_dashboard.php`) is a separate admin-audience domain and was not audited here.
