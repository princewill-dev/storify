# Legacy Gap Audit — Catalog Organisation (business / management)

**Domain:** `mgmt-catalog-org` · **Audience:** business (management dashboard)
**Legacy source of truth:** `/Users/mac/Desktop/my_files/work/storify/storify-api` (`routes/v1/management.php`, `app/Http/Controllers/Management/{Category,Service,Section}Controller.php`, `resources/views/management/{categories,services,sections}/**`)
**New API:** `/Users/mac/Desktop/my_files/work/storify/storify-api` (`routes/api/v1/management.php`, `app/Http/Controllers/Api/V1/Management/**`)
**New SPA:** `/Users/mac/Desktop/my_files/work/storify/storify-management` (`src/router/index.ts`, `src/views/**`, `src/api/endpoints.ts`)
**Admin SPA:** `/Users/mac/Desktop/my_files/work/storify/storify-admin` — has **no** catalog-organisation screens (checked `src/router/index.ts`, `src/views/`). Legacy superadmin equivalents (`Admin/CategoryController`, `admin/categories/*`, `Admin/CompanyServiceController`) are outside this audit's scope (audience = business) but are likewise unbuilt in `storify-admin`.

## Scope notes

- **"Sections" here are warehouse zones**, not page/content sections. `Management\SectionController` is nested under `warehouses/{warehouse}/sections`, sections carry `section_code`, `warehouse_id`, `status`, and organise products inside a warehouse. There is no page/content "section" entity anywhere in the legacy codebase (grepped `routes/v1/{home,storefront,admin_dashboard}.php` and `Admin/**`); the task scope's phrase "page/content sections" does not map to any in-scope code.
- **No ordering controls existed in legacy for any of these three entities.** Categories and services are ordered by name/latest respectively; sections by latest. There is no position/sort field, no drag-and-drop, no "featured" flag for categories or services. Visibility is a plain `active` / `inactive` string (sections additionally have a soft `deleted` status). This is worth stating explicitly because none needs to be invented for parity.
- **No bulk actions, exports, print views or status-transition workflows** existed for categories, services or sections — each is single-row CRUD. There is no service detail/show page in legacy (edit form is the detail surface); there is no category show page.
- Legacy file paths below are relative to `storify-api`; SPA paths relative to `storify-management`.

---

## 1. Category list (cross-store, product counts, status)

- **What a business user could do:** browse all categories across every accessible store in one table, see name, slug, product count, status, and edit/delete each row via in-page modals. The controller supports a `store_id` query filter (public store id, matched against the user's accessible stores), orders by name and paginates 20/page. No search box, no store-filter control, no pagination links were rendered in the Blade view (the `data-table` component was used without `:filters` / `:pagination` slots). The "Add Category" header button sets an Alpine `showCreateModal` flag but **no create modal markup exists in the view** — a dead control.
- **Legacy:** `GET /management/categories` → `Management\CategoryController@index` · `resources/views/management/categories/index.blade.php`
- **New API:** `GET /api/v1/management/categories` (`Api\V1\Management\CategoryController@index`) — exists; adds `q` name search, `parent_id` in payload, `products_count`; paginates (default 50).
- **New SPA:** `src/views/CategoriesView.vue`, route `/categories` — table Name / Products / Status / Actions; openCreate / openEdit modals; `window.confirm` delete.
- **Status:** api **exists**, spa **partial**.
- **Missing in the new stack:** store filter (API supports `store_id`, SPA never sends it and has no control), search box (`q` unused by SPA), pagination UI (SPA hard-fetches `per_page: 100` and shows no pager — categories beyond 100 are unreachable), slug column, store column. Legacy itself hid the filters, but the SPA cannot replace the URL-driven `?store_id=`/`?page=` workflows either.
- **Effort:** S · **Priority:** P0 (category browsing is part of the daily product workflow).

## 2. Category create

- **What a business user could do:** create a category for a chosen store with a name; the slug was generated server-side (`name + 6-char uuid`). The dedicated create page also showed a Description textarea — but the controller validated `status` as `required|in:active,inactive` while the form **omits any status field**, so submission from the page always bounced with "The status field is required." The description was also never persisted (`Category::$fillable` and the controller's `$data` both exclude it). The create page was reachable only from the empty-state link; the header button was dead. Net legacy effect: **category creation was effectively broken from the Blade UI**, and the JSON branch of `store()` was the only reliable path.
- **Legacy:** `GET /management/categories/create`, `POST /management/categories` → `Management\CategoryController@{create,store}` · `resources/views/management/categories/create.blade.php` (+ unused modal in `index.blade.php`)
- **New API:** `POST /api/v1/management/categories` — validates name/store, optional `parent_id`, optional status (defaults `active`), verifies store access, generates slug. Works correctly.
- **New SPA:** "Add Category" modal in `CategoriesView.vue` — name + store picker; no status control (legacy page had none either).
- **Status:** api **exists**, spa **exists** (SPA is strictly better than the broken legacy page).
- **Missing:** nothing functional. If parity of fields matters, the legacy description field was a no-op and should not be resurrected without persistence; `parent_id` accepted by the API but not exposed (see feature 5).
- **Effort:** — (done) · **Priority:** P0.

## 3. Category edit (rename, status toggle, move store)

- **What a business user could do:** edit a category via a modal from the list (name, store, status) or via the standalone edit page (store, status, name). The slug was regenerated only when the name changed. Store could be changed, moving the category to another accessible store.
- **Legacy:** `GET /management/categories/{category}/edit`, `PUT /management/categories/{category}` → `Management\CategoryController@{edit,update}` · `resources/views/management/categories/edit.blade.php` (orphaned — the index modal is the real path), edit modal in `categories/index.blade.php`
- **New API:** `PUT /api/v1/management/categories/{category}` — accepts `name`, `parent_id`, `status` only. **No `store_id`**, so a category cannot be moved between stores any more.
- **New SPA:** edit modal in `CategoriesView.vue` — **Name only**; store and status fields are not rendered.
- **Status:** api **partial**, spa **partial**.
- **Missing:** status control in the SPA (legacy could set active/inactive from the modal; the API supports it), store reassignment (missing from both API and SPA), error surfacing for the API's rename rules. The orphaned legacy edit page needs no replacement.
- **Effort:** S · **Priority:** P1.

## 4. Category delete (with confirmation)

- **What a business user could do:** delete a category from the row action after a confirm modal. Legacy performed a hard delete with **no product guard** — the `products.category_id` FK is `nullOnDelete`, so products silently lost their category. No ActivityLog/DB audit was written (only `Log::info`).
- **Legacy:** `DELETE /management/categories/{category}` → `Management\CategoryController@destroy` · delete modal in `categories/index.blade.php`
- **New API:** `DELETE /api/v1/management/categories/{category}` — **blocks with 409** ("Move or delete the products in this category first.") when products exist; tenant-scoped via `authorizeCategory()`.
- **New SPA:** Delete button + `window.confirm` in `CategoriesView.vue`; the 409 message is surfaced through the toast.
- **Status:** api **exists**, spa **exists** (stricter than legacy — intentional, note the behaviour change for support/training).
- **Missing:** nothing. Consider a "categories with products" hint in the UI so users understand the 409 pre-emptively.
- **Effort:** — (done) · **Priority:** P1.

## 5. Category hierarchy (parent / child)

- **What a business user could do:** **nothing in the legacy UI.** The schema and model support nesting (`categories.parent_id`, `Category::parent()/children()`, index on `(store_id, parent_id)`), but `Management\CategoryController` never reads or writes `parent_id`, no Blade view has a parent selector, and no storefront code walks children. The admin `CategoryController@index` eager-loads `parent` but there is still no way to set it. Nesting was infrastructure looking for a UI.
- **Judgement:** this is a legacy-schema capability, not a legacy-UI feature. It is cheap to expose (the new management API already accepts `parent_id` on store/update and returns it in the list), and the new storefront still doesn't render child categories, so exposing it now would create data the storefront cannot use. Recommend a parent selector + indented list only when the storefront consumes it.
- **Legacy:** none (schema/model only) · `app/Models/Category.php`, `database/migrations/2025_10_24_020000_create_categories_table.php`
- **New API:** `parent_id` accepted on `POST`/`PUT /api/v1/management/categories` and returned in index — but **no ownership/existence validation** and no cycle prevention.
- **New SPA:** not exposed anywhere.
- **Status:** api **partial**, spa **missing**.
- **Missing:** parent selector on create/edit, parent column / indented tree in the list, validation that the parent belongs to the same business/store and that no cycle is created, child-aware delete semantics.
- **Effort:** M · **Priority:** P2.

## 6. Service list (store/status filters, thumbnails, row actions)

- **What a business user could do:** browse services across accessible stores: primary image thumbnail, name, store, price (₦-prefixed), status badge, and an action menu with Edit and Delete (confirm dialog). The controller genuinely supported `store_id`, `status` (`active|inactive`), text search `q` over `name`/`service_code`, and `per_page` (default 10), and paginated `latest()` — but the Blade view rendered **no filter bar, no search box and no pagination links**, so only the plain list was reachable from the UI. An amber warning banner with a link to "Create a Store" appeared when the user had no accessible stores.
- **Legacy:** `GET /management/services` → `Management\ServiceController@index` · `resources/views/management/services/index.blade.php`
- **New API:** **none** — no service routes exist in `routes/api/v1/management.php`; no `ServiceController` under `Api/V1/Management/`.
- **New SPA:** **none** — no `ServicesView.vue`, no route, no `servicesApi` in `endpoints.ts`, no nav entry.
- **Status:** api **missing**, spa **missing**.
- **Missing:** everything — list endpoint (tenant + store scoping, `store_id`/`status`/`q`/`per_page` filters, `latest` ordering, `products…` no wait, image payload), SPA list view with thumbnails, filters, pagination, row actions; empty state; "no stores" warning path.
- **Effort:** M · **Priority:** P1 — the new **storefront API already publicly serves services** (`routes/api/v1/storefront.php` → `CatalogController@services/serviceShow`), so businesses currently have a live customer-facing services surface they cannot create or edit.

## 7. Service create (name, description, price, currency, images, store)

- **What a business user could do:** create a service with name (required), description, price (`amount`, required, min 0), currency (dropdown of all currencies, default currency preselected), one or more images (jpeg/png/jpg/gif, max 2 MB each), and a store (required, must be one of the user's accessible stores). Status was forced to `active`. Images were stored with `position` ordering and an optional primary flag (`primary_image` input); if no primary was marked the first image became primary. Each create wrote an `ActivityLog` row (`service_created`). Redirected back to the (store-filtered) index.
- **Legacy:** `GET /management/services/create`, `POST /management/services` → `Management\ServiceController@{create,store}` · `resources/views/management/services/create.blade.php`
- **New API:** **missing**.
- **New SPA:** **missing**.
- **Missing:** the whole form/endpoint, plus supporting infrastructure: there is **no currencies endpoint** in the new management API (the SPA has no currency list source at all), so a price+currency panel needs either a new meta endpoint or a business-currency default.
- **Effort:** M · **Priority:** P1.

## 8. Service edit (details, image gallery, store, status)

- **What a business user could do:** edit name (required), description, price, currency; see existing images and per image set **Primary** (radio) or **Delete** (checkbox); upload additional images (jpeg/png/jpg/gif/webp, 2 MB); change the owning store; and switch status `active`/`inactive` (the only visibility control for services — there was no inline toggle on the list). Deleted image files were removed from the `public` disk; new images appended after `max(position)`; setting a primary cleared the flag on all others. Each save wrote an `ActivityLog` (`service_updated`). The header showed the immutable `service_code`.
- **Legacy:** `GET /management/services/{service}/edit`, `PUT /management/services/{service}` → `Management\ServiceController@{edit,update}` · `resources/views/management/services/edit.blade.php`
- **New API:** **missing**.
- **New SPA:** **missing**.
- **Missing:** the whole edit surface, including image gallery management (add/delete/primary/ordering) and the status visibility control.
- **Effort:** M · **Priority:** P1.

## 9. Service delete (with image cleanup)

- **What a business user could do:** delete a service from the list action menu after a `confirm()`; all its image files were removed from storage before the row was deleted. No ActivityLog entry was written for deletes.
- **Legacy:** `DELETE /management/services/{service}` → `Management\ServiceController@destroy` · action menu in `services/index.blade.php`
- **New API:** **missing**.
- **New SPA:** **missing**.
- **Missing:** endpoint + confirm UI + storage cleanup.
- **Effort:** S · **Priority:** P1.

## 10. Section list per warehouse (counts, status, code)

- **What a business user could do:** inside a warehouse, see all non-deleted sections in a table: name (link to detail), description, product count, active/inactive badge, `section_code`, and actions — View, Edit, and Delete (Delete offered only when the section has zero products, behind a confirm modal). The warehouse detail page also showed sections as cards in its Sections tab with an "Add Section" button. Entry to the list was per-warehouse (`management/warehouses/{warehouse}/sections`).
- **Legacy:** `GET /management/warehouses/{warehouse}/sections` → `Management\SectionController@index` · `resources/views/management/sections/index.blade.php`; entry points in `resources/views/management/warehouses/show.blade.php` and `warehouses/index.blade.php`
- **New API:** **missing** — no section or warehouse routes exist under `Api/V1/Management/` or `routes/api/v1/management.php`.
- **New SPA:** **missing** — no `SectionsView.vue`, no warehouses UI at all, no nav group.
- **Status:** api **missing**, spa **missing**.
- **Missing:** everything, plus the warehouse context it hangs off (the management SPA currently has no Warehouses screen — if that belongs to another audit domain, Sections must be built after it, or with a section-only entry that lists warehouses from a new endpoint).
- **Effort:** M (list + warehouse entry) · **Priority:** P2.

## 11. Section create

- **What a business user could do:** create a section in a warehouse with name (required, ≤255), description (optional, ≤500), and an Active checkbox (default checked → `status = active`, unchecked → `inactive`). `section_code` auto-generated (`sec_` + 10 random chars), `warehouse_id` and `business_id` set server-side. Access enforced: owners must own the warehouse; staff must be assigned to it.
- **Legacy:** `GET /management/warehouses/{warehouse}/sections/create`, `POST /management/warehouses/{warehouse}/sections` → `Management\SectionController@{create,store}` · `resources/views/management/sections/create.blade.php`
- **New API:** **missing**.
- **New SPA:** **missing**.
- **Missing:** endpoint + form (name, description, active) + warehouse scoping/permission checks.
- **Effort:** S–M · **Priority:** P2.

## 12. Section detail (stats + products in section)

- **What a business user could do:** open a section to see five metric cards — Products count, Active products, Stock Count (sum of quantity), Total Value (sum of `amount`, ₦-prefixed), Out of Stock (quantity ≤ 0) — and a paginated (50/page) product table with name (link to product), product code, price, a colour-coded stock bar (percentage), status badge, and View/Edit actions. An "Add Product" button prefilled the product create form with the section. **Legacy bug:** the button passes `section_id = $section->section_code` (`sec_…`) while the product form's select uses numeric section ids and `ProductController@store` resolves `Section::find($data['section_id'])` — the prefill silently does not work.
- **Legacy:** `GET /management/warehouses/{warehouse}/sections/{section}` → `Management\SectionController@show` · `resources/views/management/sections/show.blade.php`
- **New API:** **missing**.
- **New SPA:** **missing**.
- **Missing:** stats aggregation endpoint, section product listing, "Add Product" prefill (fix the id/code mismatch when porting), product row actions.
- **Effort:** M · **Priority:** P2.

## 13. Section edit

- **What a business user could do:** edit name (required), description, and Active status; a side card showed read-only `section_code`, the warehouse name and created date. Same warehouse access checks as above.
- **Legacy:** `GET /management/warehouses/{warehouse}/sections/{section}/edit`, `PUT` → `Management\SectionController@{edit,update}` · `resources/views/management/sections/edit.blade.php`
- **New API:** **missing**.
- **New SPA:** **missing**.
- **Missing:** endpoint + form + read-only info card.
- **Effort:** S · **Priority:** P2.

## 14. Section delete (product-guarded soft delete)

- **What a business user could do:** delete a section only when it contains no products ("Cannot delete a section with products." otherwise); deletion was a soft delete (`status = 'deleted'`) so the section disappeared from lists and forms but remained in the DB. There was **no restore/undelete UI** and no bulk delete.
- **Legacy:** `DELETE /management/warehouses/{warehouse}/sections/{section}` → `Management\SectionController@destroy` · confirm modal in `sections/index.blade.php` / `sections/show.blade.php`
- **New API:** **missing**.
- **New SPA:** **missing**.
- **Missing:** endpoint with the product-count guard, soft-delete semantics, confirm UI.
- **Effort:** S · **Priority:** P2.

## 15. Product ↔ section assignment

- **What a business user could do:** assign a product to an optional section in the product create/edit form (section select shows "Name (Warehouse)"); a product with a section and no warehouse inherited the section's warehouse automatically (`ProductController@store` lines ~285-290). Sections exist to organise products and provide the per-section product list/stock stats.
- **Legacy:** `Management\ProductController@{create,store,edit,update}` · `resources/views/management/products/create.blade.php` (Section select, ~line 53), `products/edit.blade.php` (~line 54)
- **New API:** `ProductController::rules()` includes `'section_id' => ['nullable','integer']` and `section_id` is fillable on the `Product` model, so the value persists **if a client sends it** — but there is no sections source endpoint, and no ownership validation.
- **New SPA:** `ProductsView.vue` never sends `section_id` (nor `warehouse_id`); no section picker.
- **Status:** api **partial**, spa **missing**.
- **Missing:** sections lookup endpoint (scoped to the user's warehouses), section picker in the product form, auto-warehouse-from-section parity, validation that the section belongs to the business/user.
- **Effort:** M (mostly the sections API from feature 10) · **Priority:** P2.

## 16. Catalog change logging / audit trail

- **What a business user (or their admin) could do:** legacy wrote a DB `ActivityLog` row (`service_created`, `service_updated`) for service writes — visible in the admin Activity Log screen — while category create/update/delete only wrote `Log::info('category.created' …)` application-log entries, and service deletes/section writes logged nothing. So the audit trail was partial in legacy.
- **Legacy:** `Management\ServiceController@{store,update}` (`ActivityLog::create`), `Management\CategoryController` (`Log::info`)
- **New API:** `Api\V1\Management\CategoryController` writes **no** ActivityLog or application-log entries; service endpoints don't exist.
- **New SPA:** n/a.
- **Status:** api **partial**, spa **missing** (not a UI feature per se).
- **Missing:** activity-log hooks on category create/update/delete (and on future service/section writes) if the business-admin audit trail is to match or exceed legacy. Low urgency because legacy coverage was already inconsistent.
- **Effort:** S · **Priority:** P2.

---

## Gaps worth calling out

1. **Services and Sections are entirely absent from the new stack** — there is no management API, no SPA view, no route, no nav entry for either. This is the biggest hole in the "scanty" management UI: 10 of the 16 features above have no new-stack implementation at all.
2. **The storefront already sells services the business cannot manage.** `routes/api/v1/storefront.php` exposes `GET services` / `GET services/{slugOrCode}` via `Api\V1\Storefront\CatalogController`, but `routes/api/v1/management.php` has no service endpoints. Any service data can only come from legacy/DB writes today. This asymmetry makes service CRUD the highest-priority build after category polish.
3. **Sections have a missing prerequisite:** they are warehouse-scoped, and the management SPA has no warehouses UI or API either. Decide whether Warehouses/Sections lands as one domain build; `ProductController@rules()` already accepts `section_id` in anticipation, and `ProductsView.vue` silently drops it.
4. **Categories: the new SPA is thinner than it needs to be, and the legacy create path was broken anyway.** Legacy's standalone create page omitted the required `status` field (always failed validation) and its list header button opened a non-existent modal — so the modern create modal is an improvement. What the new SPA still lacks versus the legacy *controller's* capabilities: store filter, name search, pagination UI, slug/store columns, and any status control on edit (API supports `status`; SPA never renders it). It also cannot move a category between stores (legacy's edit modal could; the new API's update rules don't accept `store_id`).
5. **Category nesting is schema-only in both stacks.** `parent_id` exists and the new API accepts/returns it with no ownership or cycle validation, but no UI ever set it — legacy included. Either expose it deliberately (list tree + parent selector + validation) or stop accepting it silently; don't ship a half-wired `parent_id`.
6. **Legacy bugs worth not porting:** the section "Add Product" prefill passes `section_code` where the product form expects a numeric id; the category create form's missing status field; the dead "Add Category" modal trigger. Also note the deliberate improvement in the new delete guard (409 when category has products) — legacy hard-deleted and let products fall back to "uncategorised", so behaviour and support messaging changed.
7. **Supporting gaps for rebuilding services:** the new management API has no currencies endpoint (`Service` pricing in legacy offered a currency dropdown with the default currency preselected), no service-image upload/management contract, and no service `service_code` handling. Plan these as part of the service build rather than after it.
8. **Navigation parity:** the legacy sidebar's Catalog group had Products / Categories / **Services**; the new SPA Catalog group has Products / Categories only. There is no Warehouses group, so Sections have no home even conceptually yet.
