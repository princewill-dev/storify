# Legacy Gap Audit — Admin: Stores, Warehouses, Stock & Catalog Oversight

**Domain:** `admin-stores-catalog` (audience: **admin / superadmin**)
**Legacy source of truth:** `/Users/mac/Desktop/my_files/work/storify/storify-api`
**New API:** `routes/api/v1/admin.php` + `app/Http/Controllers/Api/V1/Admin/**`
**New SPA:** `/Users/mac/Desktop/my_files/work/storify/storify-admin` (`src/router/index.ts`, `src/views/**`, `src/api/endpoints.ts`)

**Scope:** Admin/StoreController, Admin/WarehouseController, Admin/StockTransferController, Admin/StorefrontSlideController, Admin/PageStylingController, Admin/ProductController, Admin/CategoryController, Admin/FeatureController, Admin/CompanyServiceController; views `admin/stores/**`, `warehouses/**`, `transfers/**`, `storefront_slides/**`, `styling/**`, `products/**`, `categories/**`, `features/**`, `company_services/**`.

---

## Headline finding

The new admin API (`routes/api/v1/admin.php`) covers **exactly two** things in this domain: `GET /admin/stores` and `GET /admin/stores/{store}` — both read-only, both thinner than legacy. The new admin SPA (`storify-admin`) has **one screen** for the domain: `/stores`, a read-only table with a detail drawer.

Everything else in scope — store create/edit/suspend/activate/delete, the entire product & category catalogue, **all** warehouse and stock-transfer screens, storefront slides, page styling, feature CTAs and company services — **does not exist in the new stack at all**, neither as API routes/controllers nor as SPA views. 24 of the 27 features below are `missing`/`missing`.

The permissions `admin.stores`, `admin.products`, `admin.warehouses`, `admin.content` are already seeded (`SpatiePermissionSeeder`), so the auth/permission plumbing for these screens is ready; only the endpoints and views are absent.

> Note: `routes/api/v1/admin_storefront.php` defines `/api/superadmin/stores/{store}/products`, `/storefront-slides/bulk`, `/storefront-slides/reorder`. These are **legacy leftovers** — they resolve to the old session-auth `App\Http\Controllers\Admin\StorefrontSlideController`, not to a new `Api\V1\Admin` controller, and the new admin SPA does not call them. They are not evidence of a new-stack implementation.

---

## Status summary

| # | Feature | API | SPA | Effort | Priority |
|---|---|---|---|---|---|
| STORE-1 | Store directory list with filters | partial | partial | S | P0 |
| STORE-2 | Store detail (info, business/owner, product/category/pack panels) | partial | partial | M | P1 |
| STORE-3 | Store create (multi-field, logo, main-store bootstrap) | missing | missing | M | P1 |
| STORE-4 | Store edit (incl. logo replace, main-store status guard) | missing | missing | M | P1 |
| STORE-5 | Store suspend / reactivate with reason | missing | missing | S | P0 |
| STORE-6 | Store delete with order/transaction guards | missing | missing | S | P1 |
| CATALOG-1 | Platform product list with filters + per-store scope | missing | missing | M | P1 |
| CATALOG-2 | Admin product create form (8 field groups, variants, bulk pricing, media) | missing | missing | L | P1 |
| CATALOG-3 | Admin product edit form (variant sync, image/file delete, primary image) | missing | missing | L | P1 |
| CATALOG-4 | Admin product detail with Overview / Variants / Images & Meta tabs | missing | missing | M | P2 |
| CATALOG-5 | Product activate / deactivate | missing | missing | S | P1 |
| CATALOG-6 | Product delete (image + digital file cleanup, paging-preserving return) | missing | missing | S | P2 |
| CATALOG-7 | Category directory list (platform-wide + per-store) | missing | missing | S | P1 |
| CATALOG-8 | Category create (modal + standalone page, auto-slug) | missing | missing | S | P1 |
| CATALOG-9 | Category edit and delete | missing | missing | S | P2 |
| WH-1 | Warehouse directory list with status/search filters | missing | missing | M | P1 |
| WH-2 | Warehouse detail (stock metrics, sections, recent movements) | missing | missing | M | P1 |
| WH-3 | Stock transfer list with status/search filters | missing | missing | M | P1 |
| WH-4 | Stock transfer detail + timeline + approve/reject/dispatch/receive/cancel | missing | missing | L | P1 |
| CONTENT-1 | Storefront slides per store (bulk add, edit, delete, drag reorder) | missing | missing | L | P2 |
| CONTENT-2 | Page styling list | missing | missing | S | P2 |
| CONTENT-3 | Page styling create/edit form (colour picker, custom CSS) | missing | missing | S | P2 |
| CONTENT-4 | Page styling delete | missing | missing | S | P2 |
| CONTENT-5 | Feature CTA list with drag-and-drop ordering | missing | missing | M | P2 |
| CONTENT-6 | Feature CTA create/edit/delete (icon upload) | missing | missing | M | P2 |
| CONTENT-7 | Company services list + CRUD + toggle + drag reorder | missing | missing | M | P2 |
| — | *(dead)* legacy `admin/stores/create.blade.php` and `edit.blade.php` | n/a | n/a | — | — |

---

# 1. Stores (Admin/StoreController)

## STORE-1 — Store directory list with filters

**What the admin could do (legacy).** Open a platform-wide store table with **S/N, Name (+ logo thumbnail + "Main" badge for the homepage store), Business (linked name + business code), Owner, Type (business type), Status (colour badge from `Store::statusBadgeData()`), Shop Link ("view shop" deep link to the public storefront), Actions**. Paginated 15/page with query string preserved. "Filter" modal offered **status** (all/active/inactive/suspended/deleted), free-text **q** matching store name **OR store_id OR owner name**, and a **created-at date range** (from/to), plus Reset / Apply Filters. "Add Store" button in the header. Per-row action menu: View, Edit, Activate (when suspended/inactive/pending), Suspend (otherwise), Delete.

**Legacy:** `GET /admin/stores` → `Admin\StoreController@index`; view `resources/views/admin/stores/index.blade.php`.

**New API:** `GET /api/v1/admin/stores` → `Api\V1\Admin\StoreController@index`. Supports `status`, `q` (name only), `sort` (name|slug|status|balance|created_at), `direction`, `per_page`. Returns `id, store_id, name, slug, status, store_type, has_website, pos_enabled, balance, business, business_id, products_count, orders_count, created_at`.

**New SPA:** `/stores` → `src/views/StoresView.vue` (table + `DetailDrawer`, `SortHeader`, `TableFooter`, `EmptyState`).

**api_status:** `partial` — no `from`/`to` date range; `q` does not search store_id or owner/business name; no `ownership_type`/`business_type`; no `is_main_store` flag; no logo path.

**spa_status:** `partial` — no date-range filter, no shop-link column, no Main badge, no logo, no Owner/Type columns, **no row actions at all** (no View/Edit/Suspend/Activate/Delete menu; the row click opens a drawer instead). Also exposes a "pending" status option that the legacy filter did not list.

**key_ui:** filter panel/modal, table with 8 columns and status badges, pagination, empty state, row action menu.
**side_effects:** legacy wrote a `Log::info('stores_viewed')` audit line. Emails: none.
**Missing exactly:** date-range filter, store_id/owner search, owner & business-type columns, Main-store badge, shop link, per-row action menu (see STORE-3…6).
**Effort:** S. **Priority:** P0.

## STORE-2 — Store detail

**What the admin could do (legacy).** Open one store and see: four metric tiles (**Total amount earned, Customers (both hard-coded to 0 in legacy), Products count, Sales**); a **Store Info** card (logo, name, store_id code, status, description, support email/phone, ownership type, business type, business link, address, social links Instagram/Facebook/Twitter/TikTok as outbound buttons); a **Business & Owner** card (business name + business_code, owner name/email/phone, or a "No Business record found" warning); and three panels — **Products** (count badge, 10 most recent with per-row Edit link, "View all products"), **Categories** (count, full name list, "Manage categories"), **Packs** (count, name + amount list). Header quick actions: **Edit Store, Activate/Suspend, Add Product, Add Category, Products, Categories, Edit Slides**. The Edit Store modal is embedded here too (with a `redirect_to` hidden field so the admin returns to this page).

**Legacy:** `GET /admin/stores/{store}` → `Admin\StoreController@show`; view `resources/views/admin/stores/show.blade.php`.

**New API:** `GET /api/v1/admin/stores/{store}` (bound by `store_id`) → `Api\V1\Admin\StoreController@show`, loads `business` and counts `products`/`orders`.

**New SPA:** `StoresView.vue` `DetailDrawer` (copyable Store ID and Slug, status/Website/POS badges, dl of Business, Type, Products, Orders, Balance, Created).

**api_status:** `partial` — payload lacks store description, support email/phone, address, logo, socials, ownership type, business type, business_code, owner details; no recent products, categories or packs.
**spa_status:** `partial` — drawer has no business/owner contact block, no description/socials/address, no Products/Categories/Packs panels, and **no quick actions** (Edit / Suspend / Activate / Add Product / Add Category / Edit Slides).
**key_ui:** header quick-action bar, 4 metric tiles, Store Info card, Business & Owner card, Products/Categories/Packs panels, embedded edit modal.
**side_effects:** legacy logged `store_show_viewed`.
**Missing exactly:** all detail enrichment above; the entire quick-action/panel layer depends on STORE-4/5 and CATALOG-1/7 and CONTENT-1.
**Effort:** M. **Priority:** P1 (this is the hub screen every other store-scoped admin task hangs off).

## STORE-3 — Store create

**What the admin could do (legacy).** "Add Store" modal with field groups: **Business** (select from businesses, shown with owner name), **Name, Slug (auto from name if blank), Description, Logo (PNG/JPG/WEBP ≤2 MB with live preview), Support Email, Support Phone, Address, Instagram/Facebook/Twitter/TikTok URLs, Ownership Type, Business Type, Status (active/inactive)**. On submit the server normalises the slug, stores the logo on the `public` disk, resolves `user_id` from the chosen business, and (when the actor is a superadmin and no `main_store_id` is set yet) **auto-configures the new store as the homepage/main store**. Guarded by the `ALLOW_MS_SETUP` env flag — when a main store exists and multi-business setup is disabled, creation is refused with "Multi-business controls are disabled."

**Legacy:** `POST /admin/stores` → `Admin\StoreController@store`; views `admin/stores/index.blade.php` (modal) and the unrouted `admin/stores/create.blade.php`.

**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects (legacy):** queues `AdminStoreCreated` to all superadmins and `StoreActivated` to the business owner; writes `Log::info('store_created')` and `main_store_configured`; may mutate `settings.main_store_id`.
**Missing exactly:** everything, including the two queued emails and the main-store bootstrap rule.
**Effort:** M. **Priority:** P1.

## STORE-4 — Store edit

**What the admin could do (legacy).** Same 16-field form as create, reachable from the index row menu (values injected by JS) and from the store detail page (values server-rendered, with `redirect_to` back to the detail page). Update behaviour: slug regenerated/retried with a random 3-digit suffix until unique, **old logo deleted from disk and replaced**, `user_id` re-resolved from business, and a **guard that silently blocks setting the homepage/main store to inactive or suspended** (returns a warning flash and keeps other edits). If status changes to inactive/suspended, the owner is emailed. Returns to the index (or `redirect_to`).

**Legacy:** `PUT /admin/stores/{store}` → `Admin\StoreController@update`; views `admin/stores/index.blade.php`, `admin/stores/show.blade.php`, unrouted `admin/stores/edit.blade.php`.

**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects (legacy):** deletes the previous logo file; queues `StoreSuspended` on transition to inactive/suspended; logs `store_updated` and `store_status_change_blocked_main_store`.
**Missing exactly:** the form, slug uniqueness handling, logo replacement, main-store status guard, suspension email.
**Effort:** M. **Priority:** P1.

## STORE-5 — Store suspend / reactivate with reason

**What the admin could do (legacy).** Suspend a store from the index row menu or the detail header: a modal shows the store name (read-only) and **requires a reason text (max 2000 chars)**; on submit the store status becomes `suspended` and the owner is emailed the reason. Activate works the mirror-image: **reason/notes required**, status becomes `active`, owner emailed a reactivation notice. **The main/homepage store cannot be suspended** (blocked with an error flash). The index menu shows Activate for suspended/inactive/pending stores and Suspend otherwise; the detail header shows the same toggle.

**Legacy:** `POST /admin/stores/{store}/suspend`, `POST /admin/stores/{store}/activate` → `Admin\StoreController@suspend|activate`; views `admin/stores/index.blade.php`, `admin/stores/show.blade.php`.

**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects (legacy):** `StoreSuspended($store, $reason)` and `StoreReactivated($store, $reason)` queued to the owner; logs `store_suspended`, `store_activated`, `store_suspend_blocked_main_store`.
**Missing exactly:** both actions, the mandatory-reason validation, the main-store block, both emails.
**Effort:** S. **Priority:** P0 — moderation is the primary reason the platform office exists; without it an abusive or fraudulent store cannot be taken down from the new UI.

## STORE-6 — Store delete (soft, with guards)

**What the admin could do (legacy).** Delete from the row menu behind a confirmation modal that warns "This action will mark the store as deleted… only proceeds if all orders and transactions associated with this store are completed." Server guards, in order: **(1)** main/homepage store → refused; **(2)** any order for the store not in `completed` status → refused with the store name; **(3)** any transaction whose order belongs to the store not in `confirmed` status → refused. If both pass the store is marked `status = 'deleted'` (no hard delete), and the index excludes deleted stores by default.

**Legacy:** `DELETE /admin/stores/{store}` → `Admin\StoreController@destroy`; view `admin/stores/index.blade.php` (modal).

**New API:** none. **New SPA:** none (the new list already excludes `deleted`).
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects (legacy):** logs `store_delete_requested` / `store_deleted` / `store_delete_rejected_incomplete_orders` / `store_delete_rejected_incomplete_transactions` / `store_delete_blocked_main_store`.
**Missing exactly:** the action, the three guards, the warning modal.
**Effort:** S. **Priority:** P1.

---

# 2. Warehouses & stock transfers

## WH-1 — Warehouse directory list

**What the admin could do (legacy).** Platform-wide warehouse table filtered inline by **status** (all / active / inactive / deleted — default hides deleted) and **q** matching warehouse name, `warehouse_code` **or business name**, with a Clear button when filters are active. Columns: **Name (+ address sub-line, linked to detail), Code, Business (linked, falls back to "—"), Owner, Stock (stock-locations count), Sections, Status (colour badge)**. Paginated 15/page.

**Legacy:** `GET /admin/warehouses` → `Admin\WarehouseController@index`; view `resources/views/admin/warehouses/index.blade.php`.

**New API:** none — `grep` over `routes/api/v1/**` finds no warehouse route for either the admin or the management audience, and there is no `Api/V1/Admin/WarehouseController`.
**New SPA:** none — `storify-admin` has no warehouses route (`src/router/index.ts`), no view, no endpoint in `src/api/endpoints.ts`. `warehouses_count` only appears as a number on the business detail payload.
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects:** legacy logged `admin_warehouses_viewed`.
**Effort:** M. **Priority:** P1.

## WH-2 — Warehouse detail

**What the admin could do (legacy).** Open a warehouse and inspect: a back link, header with name, warehouse_code, status badge and address; **four metric tiles — Total Stock (sum of stock-location quantities), Low Stock ≤10 (count of locations with 0 < qty ≤ 10), Stock Items, Sections**; a **Business & Owner** card (business name linked + business_code, owner name, owner email); a **Details** card (City, State, Contact person, Contact phone, Description — each shown only when set); a **Sections** table (name, stock items count, status badge) rendered only when sections exist; and a **Recent Stock Movements** table (last 15 movements where this warehouse is the from- or to-location; columns Product, Type `added`/`removed` with colour badge, signed Qty, Date).

**Legacy:** `GET /admin/warehouses/{warehouse}` → `Admin\WarehouseController@show`; view `resources/views/admin/warehouses/show.blade.php`.

**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects:** logs `admin_warehouse_show_viewed`.
**Effort:** M. **Priority:** P1.

## WH-3 — Stock transfer list

**What the admin could do (legacy).** Platform-wide transfer table with a **status** dropdown covering all eight `TransferStatus` cases (draft, pending, approved, awaiting acknowledgment, dispatched, received, rejected, cancelled — human labels from `->label()`), a free-text **q** matching transfer code, source location name or destination location name, Filter and Reset buttons, and a table of **Code (linked to detail, monospace), From, To, Items (count pill), Requested By, Status (colour-coded badge), Date**. Paginated 15/page.

**Legacy:** `GET /admin/transfers` → `Admin\StockTransferController@index`; view `resources/views/admin/transfers/index.blade.php`.

**New API:** none for the admin audience. (Management-side transfer routes in `routes/v1/management.php` are the legacy Blade business dashboard and have no `Api/V1/Management` counterpart either — there is no transfer endpoint anywhere under `routes/api/v1/`.)
**New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects:** logs `admin_transfers_viewed`.
**Effort:** M. **Priority:** P1.

## WH-4 — Stock transfer detail, timeline and status transitions

**What the admin could do (legacy).** Open one transfer: a summary strip (From → To, Items, Units, Requested by, Date); an **Items** table showing each product's name + product_code, **Requested** quantity and **Approved** quantity — with an amber highlight + strikethrough when the approved quantity was adjusted downward; an **Approve Transfer** form with one `approved_quantities[item]` number input per line (default = requested, `min 1`, `max = requested`) and a **Reject** button opening a modal requiring a `rejection_reason`; a **Timeline** sidebar deriving steps Created → Submitted → Awaiting Ack → Approved → Dispatched → Received (plus Rejected / Cancelled) with the acting user where known; a **Details** card (from, to, requester, approver, dispatcher, receiver, notes, rejection reason); and an **Actions** card with **Dispatch** (confirm modal), **Confirm Receipt** (confirm modal) and **Cancel Transfer** (confirm modal), gated by `canBeDispatched()` / `canBeReceived()` / `canBeCancelled()`.

Both approve and reject are only rendered when `canBeApproved()`. The admin controller **delegates** approve/reject/dispatch/receive to `Management\StockTransferController` so that the stock-ledger side effects and status-transition rules live in one place — the admin path is a permission-widened wrapper.

**Legacy:** `GET /admin/transfers/{transfer}`, `PATCH /admin/transfers/{transfer}/approve|reject|dispatch|receive` → `Admin\StockTransferController` (delegating to `Management\StockTransferController`); view `resources/views/admin/transfers/show.blade.php`.
**Transition rules** (`App\Enums\TransferStatus::canTransitionTo`): draft→pending; pending→approved|awaiting_acknowledgment|rejected; approved→dispatched|cancelled; awaiting_acknowledgment→approved; dispatched→received; received/rejected/cancelled terminal.

**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects (legacy):** the delegated management actions move stock between locations (stock ledger writes) and stamp approver/dispatcher/receiver ids. Admin routes did **not** expose cancel — the legacy detail page's Cancel button actually posts to `management.transfers.cancel`, an inconsistency worth fixing in the rewrite. Logs `admin_transfer_show_viewed`.
**Missing exactly:** the screen, the timeline, per-line approved quantities, rejection reason, all four delegating actions, and a proper admin-side cancel.
**Effort:** L. **Priority:** P1.

---

# 3. Product & category catalogue (platform oversight)

## CATALOG-1 — Platform product list with filters and per-store scope

**What the admin could do (legacy).** Browse **all products across all stores**, or the same list scoped to one store (`/admin/stores/{store}/products`). Filter modal: **status** (all/active/inactive), **q** matching product name, product_code, **store name or category name**, and a **created-at date range**. A **per-page selector** (10 / 50 / 100, default 10) that preserves the other query params. Table columns: **Image (primary image thumbnail or "N/A"), Code, Name, Store, Category, Amount, Status (badge), Featured (badge)** and a row action group: **View, Edit, Activate/Deactivate (confirm modal), Delete (confirm modal)**. The Amount column is computed server-side and displays, for variant products, the min–max variant range with the matching currency symbols; for simple products with a discount, `₦1,000.00 -> ₦900.00 (-10%)`.

**Legacy:** `GET /admin/products` and `GET /admin/stores/{store}/products` → `Admin\ProductController@index`; view `resources/views/admin/products/index.blade.php`.

**New API:** none for the admin audience. (`GET /api/v1/management/products` exists but is business-scoped, permission `products view`, and returns the authenticated business's catalogue only — it is not a platform-wide admin list. `storify-admin` does not call it.)
**New SPA:** none in `storify-admin` (the equivalent screen lives in `storify-management` → `/products` → `ProductsView.vue`, different audience).
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects:** logs `products_viewed`; legacy also built an image-URL map and currency symbol map for rendering.
**Effort:** M. **Priority:** P1.

## CATALOG-2 — Admin product create form

**What the admin could do (legacy).** Create a product on behalf of any store from a single long form organised into field groups:

- **Store & classification:** Store (select; auto-locked to the only store when just one exists; accepts `?store_id=` pre-selection), Category, Status (active/inactive).
- **Identity:** Name (required), Brand, **Tags** (comma separated).
- **Pricing / stock (single-SKU):** Quantity (required, > 0), Amount + **per-product Currency** select, **Discount %** (0–100, with an inline explanation that final price = amount × (1 − discount/100)), Size + Size Unit, Weight + Weight Unit, Color.
- **Bulk pricing:** a "Has Bulk Pricing?" checkbox that reveals **Bulk Quantity (threshold)** and **Bulk Price (total for the threshold)**; values captured as `bulk_quantity` / `bulk_price`.
- **Variants:** a "Has variants?" checkbox that reveals a repeatable **Add Variant / Remove** row set — each row has Size, Size Unit, Weight, Weight Unit, Color, SKU, Quantity (required > 0), Amount + Currency, Status, Featured. Enabling variants disables and ignores the base quantity/amount/size/weight/color fields.
- **Flags:** "Can be paid on delivery" (COD) and "Mark as featured".
- **Description:** Trix WYSIWYG bound to a hidden `description` input.
- **Images:** multi-file upload with a **primary-image picker** rendered from the chosen files.

Server behaviour: creates inside a DB transaction; **product_code and slug are always server-generated**; when quantity > 0 a `StockLocation` for the store is created and the **stock ledger records an initial addition** ("Product created (Admin) — initial stock"); if a section/warehouse is chosen, warehouse stock locations are created too; images stored on the `public` disk with `is_primary`/`position`; digital files handled by `ProductFileService` when `is_digital`; **an ActivityLog `create_product` entry** is written. Validation is a dedicated `ProductRequest` with variant-aware rules, Trix-rich-media image mime limits (≤20 MB), and a custom `failedValidation` that translates PHP upload errors (`upload_max_filesize` / `post_max_size`) into human-readable messages.

**Legacy:** `GET /admin/stores/{store}/product/create` (+ `GET /admin/products/create`) and `POST /admin/products` → `Admin\ProductController@create|store`; `App\Http\Requests\Admin\ProductRequest`; view `resources/views/admin/products/create.blade.php`.

**New API:** none for the admin audience. **New SPA:** none in `storify-admin`.
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects (legacy):** initial stock-ledger entry, ActivityLog `create_product`, image/digital-file writes to `public` disk.
**Missing exactly:** everything; the `ALLOW_MS_SETUP` store-restriction logic, the main-store currency defaults, and the upload-limit error translation are all worth carrying over.
**Effort:** L. **Priority:** P1 (platform support needs to create products for merchants; also the assisted-onboarding path).

## CATALOG-3 — Admin product edit form

**What the admin could do (legacy).** Same 8 field groups as create plus update-only controls: **delete individual images** (`delete_image_ids[]`), **choose an existing image as primary** (`primary_image_id`), add new images appended at the end of the position order, **delete digital files** (`delete_file_ids[]`) and upload new ones, and full **variant synchronisation** — existing variants are updated in place by id, new ones created, and variants missing from the payload deleted; if the admin turns variants off, all variants for the product are deleted. A `backUrl` returns to the store-scoped product list when the product belongs to a store. Bulk fields are nulled when cleared.

**Legacy:** `GET /admin/products/{product}/edit` and `PUT /admin/products/{product}` → `Admin\ProductController@edit|update`; `ProductRequest`; view `resources/views/admin/products/edit.blade.php`.

**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects (legacy):** deletes image/digital files from disk, writes ActivityLog `update_product` + `product_updated_debug` log lines.
**Effort:** L. **Priority:** P1.

## CATALOG-4 — Admin product detail with tabs

**What the admin could do (legacy).** View a product through three tabs: **Overview** (store, price — with strike-through original + discounted figure + `-X%` badge when a discount exists, or a min–max variant price range; category; product code; slug; status; featured yes/no; COD yes/no; view count; rich description rendered from a whitelist of tags; an **Images** panel with a "Primary" badge; and, for non-variant products, a **Pricing & Stock** panel with quantity, amount, bulk pricing "total for N units" when set, and colour); **Variants** (SKU, Size + unit, Weight + unit, Color, Qty, Amount + currency symbol, Status, Featured, with the overall price range in the header); **Images & Meta** (image gallery + created/updated timestamps). Header has **Edit** and **Back**. The same view is reused for the store-scoped route, which looks-up by `product_code` inside the store and sets a store-specific back link.

**Legacy:** `GET /admin/products/{product}` and `GET /admin/stores/{store}/products/{code}` → `Admin\ProductController@show|showInStore`; view `resources/views/admin/products/show.blade.php`.

**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**Effort:** M. **Priority:** P2.

## CATALOG-5 — Product activate / deactivate

**What the admin could do (legacy).** From the list, toggle a product's status behind a confirmation modal ("Are you sure you want to activate/deactivate <name>?"). `status` is validated to `active|inactive`; the redirect target is recomputed with the current `per_page` and `page` so the admin stays on the same page of the list, with a success flash naming the action.

**Legacy:** `PUT /admin/products/{product}/status` → `Admin\ProductController@updateStatus`; view `admin/products/index.blade.php` (modals).

**New API:** none for the admin audience (management has `PUT /management/products/{product}/status`, business-scoped).
**New SPA:** none in `storify-admin`.
**api_status:** `missing`. **spa_status:** `missing`.
**Effort:** S. **Priority:** P1 (this is the admin's fastest lever for pulling a bad listing).

## CATALOG-6 — Product delete

**What the admin could do (legacy).** Delete behind a "This action cannot be undone" confirm modal. The controller captures the store first, **deletes every image file from the `public` disk**, deletes all digital files via `ProductFileService`, then deletes the record; the redirect preserves `per_page` and `page` and returns to the store-scoped list.

**Legacy:** `DELETE /admin/products/{product}` → `Admin\ProductController@destroy`; view `admin/products/index.blade.php` (modal).
**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects (legacy):** disk deletions (`products/images/**`, digital files) — irreversible.
**Effort:** S. **Priority:** P2.

## CATALOG-7 — Category directory list

**What the admin could do (legacy).** See **all categories across all stores** ordered by store then name, 20/page, columns **Store, Name, Status (badge), Actions (Edit, Delete)**. A store-scoped variant (`/admin/stores/{store}/categories`) shows only that store's categories and offers a Back button to the store overview. A "New category" button opens a modal directly on the list.

**Legacy:** `GET /admin/categories` and `GET /admin/stores/{store}/categories` → `Admin\CategoryController@index`; view `resources/views/admin/categories/index.blade.php`.
**New API:** none for the admin audience (`GET /api/v1/management/categories` is business-scoped).
**New SPA:** none in `storify-admin` (`storify-management` has `/categories`).
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects:** logs `categories_viewed`.
**Effort:** S. **Priority:** P1.

## CATALOG-8 — Category create

**What the admin could do (legacy).** Two entry points — the inline modal on the list (store is a hidden field when store-scoped) and a standalone page (`/admin/stores/{store}/categories/create`) for the store-scoped flow — with fields **Store (select or locked), Name, Status (active/inactive)**. The slug is generated server-side as `Str::slug(name)` + a 6-char UUID fragment, so names may repeat. On success the admin is redirected to the **store overview page** for the chosen store (not back to the category list).

**Legacy:** `GET /admin/categories/create`, `GET /admin/stores/{store}/categories/create`, `POST /admin/categories` → `Admin\CategoryController@create|store`; views `resources/views/admin/categories/create.blade.php`, `admin/categories/index.blade.php` (modal).
**New API:** none for the admin audience. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects:** logs `category_created`.
**Effort:** S. **Priority:** P1.

## CATALOG-9 — Category edit and delete

**What the admin could do (legacy).** Edit a category (Store, Name, Status). The slug is **kept stable unless the name changes**, in which case it is regenerated with a fresh UUID fragment. Returns to the store-scoped category list. Delete uses a confirm modal naming the category, then redirects back to the store-scoped list.

**Legacy:** `GET /admin/categories/{category}/edit`, `PUT /admin/categories/{category}`, `DELETE /admin/categories/{category}` → `Admin\CategoryController@edit|update|destroy`; views `resources/views/admin/categories/edit.blade.php`, `admin/categories/index.blade.php`.
**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects:** logs `category_updated` / `category_delete_requested`.
**Effort:** S. **Priority:** P2.

---

# 4. Storefront content (slides, styling, features, company services)

## CONTENT-1 — Storefront slides per store

**What the admin could do (legacy).** For a chosen store, manage the homepage carousel:

- **Table** of slides ordered by position, with a drag handle, product thumbnail, product name + "Code: X • $Y", status pill, Edit and Delete buttons.
- **Add Slides modal:** debounced product search (300 ms) with pagination and a **Load more** button, multi-select checkboxes, rows already in slides shown greyed with an "Already in slides" badge and disabled, a **status** select applied to the whole batch, an inline error box that renders Laravel validation errors, an "Add Selected" button disabled until something is checked, and a success toast + reload after the bulk call.
- **Edit slide modal:** product search/select picker with "Selected: name • Code • $price — Edit product" and a link to the product edit page; status select; Save.
- **Delete slide** behind a confirm modal.
- **Drag-and-drop reordering** with positions persisted automatically to the reorder endpoint (JSON).

**Legacy:** `GET|POST /admin/stores/{store}/storefront-slides`, `PUT|DELETE /admin/stores/{store}/storefront-slides/{slide}` → `Admin\StorefrontSlideController`; JSON helpers `searchProducts`, `apiListProducts`, `apiBulkStore`, `reorder` exposed at `/api/superadmin/stores/{store}/products`, `/storefront-slides/bulk`, `/storefront-slides/reorder`; view `resources/views/admin/storefront_slides/index.blade.php`.

**New API:** none in the new namespace — the `/api/superadmin/**` routes still resolve to the **legacy session-auth controller**, and the new admin SPA does not call them.
**New SPA:** none.
**side_effects (legacy):** position reassignment writes on every drag; logs `Reordering storefront slides`, `API bulk add slides hit/request/result`, `Failed creating slide`.
**Also note:** `StorefrontSlide` is not consumed anywhere in the new API — the new storefront/home apps do not render these slides yet, so this screen alone would not make the carousel appear. Ship it together with the storefront consumer, or treat as P2/obsolete-until-then.
**Effort:** L. **Priority:** P2.

## CONTENT-2 — Page styling list

**What the admin could do (legacy).** See every configured page style: **#, Page Label, Page Name (code pill), Background Color (swatch + hex code), Status (Active/Inactive badge), Actions (edit, delete-with-confirm)**, ordered by page label, with an empty state linking to the create form.

**Legacy:** `GET /admin/styling` → `Admin\PageStylingController@index`; view `resources/views/admin/styling/index.blade.php`.
**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**Effort:** S. **Priority:** P2.
**Obsolescence note:** `PageStyling` has no consumer anywhere in the new API — no endpoint reads it. If the new home/storefront apps hard-code their styling, this whole feature (CONTENT-2/3/4) is a candidate for retirement rather than rebuilding; flag for product decision.

## CONTENT-3 — Page styling create/edit form

**What the admin could do (legacy).** Create or edit a page style with **Page Label** (human name), **Page Name (identifier)** — unique, lowercase-with-underscores hint (`product_details`, `home`, `checkout`), **Background Colour** with a **native colour picker synced two-way to a hex text input**, **Custom CSS** textarea (advanced), and an **is_active** checkbox (default on). Validation: page_name required+unique (ignoring self on update), page_label required, background_color ≤ 7 chars, custom_css free text.

**Legacy:** `GET /admin/styling/create`, `GET /admin/styling/{styling}/edit`, `POST /admin/styling`, `PUT /admin/styling/{styling}` → `Admin\PageStylingController@create|edit|store|update`; views `admin/styling/create.blade.php`, `admin/styling/edit.blade.php`.
**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**Effort:** S. **Priority:** P2 (see obsolescence note above).

## CONTENT-4 — Page styling delete

**What the admin could do (legacy).** Delete a style behind a confirm modal from the list.

**Legacy:** `DELETE /admin/styling/{styling}` → `Admin\PageStylingController@destroy`; view `admin/styling/index.blade.php`.
**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**Effort:** S. **Priority:** P2.

## CONTENT-5 — Feature CTA list with drag-and-drop ordering

**What the admin could do (legacy).** See the marketing "feature CTAs": **#, order badge with drag handle, Icon thumbnail, Title, truncated Description (120 chars), Actions (edit, delete)**, paginated 20/page. Rows are **reorderable by drag-and-drop** (SortableJS); on drop every row's position is POSTed to the reorder endpoint and the order badges are rewritten in place — a "Drag & Drop to reorder features. Changes are saved automatically." banner explains the interaction.

**Legacy:** `GET /admin/features`, `POST /admin/features/reorder` → `Admin\FeatureController@index|reorder`; view `resources/views/admin/features/index.blade.php`.
**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**Effort:** M. **Priority:** P2.
**Obsolescence note:** `Feature` has no consumer in the new API; confirm the new home app still needs this content type before rebuilding.

## CONTENT-6 — Feature CTA create / edit / delete

**What the admin could do (legacy).** Create a feature via modal with **Display order (min 0), Title, Description (required), Icon upload** (jpg/jpeg/png/webp ≤ 5 MB, **required on create, optional on update**). Edit uses the same modal with the current icon shown as a background preview and "uploading a new icon will replace the previous one"; the old icon file is deleted from disk on replace and on delete. Delete is behind a confirm modal naming the title.

**Legacy:** `POST /admin/features`, `PUT /admin/features/{feature}`, `DELETE /admin/features/{feature}` → `Admin\FeatureController@store|update|destroy`; `App\Http\Requests\Admin\FeatureRequest`; views `admin/features/partials/create-modal.blade.php`, `edit-modal.blade.php`, `delete-modal.blade.php`.
**New API:** none. **New SPA:** none.
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects (legacy):** `features/icons/**` file writes and deletes on the `public` disk.
**Effort:** M. **Priority:** P2.

## CONTENT-7 — Company services list, CRUD, toggle and reorder

**What the admin could do (legacy).** Manage the platform's public service pages/links:

- **List:** **#, order badge with drag handle, Title, Page Link (shown as `/link`, or "—"), Status badge, Actions** — plus a **Visit** button that opens the page link in a new tab (disabled when no link).
- **Create modal:** Display order (hint "lower numbers appear first"), Title, Description, **Page link** with a rendered `https://host/` prefix and validation that it is unique and stored without a leading slash, **Background image** (jpg/jpeg/png/webp ≤ 10 MB), Status. Validation errors render inside the modal.
- **Edit modal:** same fields with a **current background image preview**; uploading a new file deletes and replaces the old one; the page link is unique-ignoring-self.
- **Toggle:** an activate/deactivate button per row opening a confirm modal that names the service and the action.
- **Delete:** confirm modal naming the service; deletes the background image file too.
- **Drag-and-drop reorder** of rows (auto-saved via the reorder endpoint, JSON response, order badges rewritten, toast on success/failure).

Both create/update/delete/toggle/reorder **invalidate the `nav_company_services` cache** so the public navigation updates immediately.

**Legacy:** `GET /admin/company-services`, `POST`, `PUT /admin/company-services/{companyService}`, `DELETE`, `POST /admin/company-services/{companyService}/toggle`, `POST /admin/company-services/reorder` → `Admin\CompanyServiceController`; view `resources/views/admin/company_services/index.blade.php`.
**New API:** none — but note `Api\V1\Home\HomeController` **already reads `CompanyService`** (`status = active`, ordered) to serve the public services list, so the content model is live in the new stack while its admin UI is not.
**New SPA:** none (`storify-home` has a `ServicesView.vue` that consumes the read API).
**api_status:** `missing`. **spa_status:** `missing`.
**side_effects (legacy):** `company_services/**` file writes/deletes; `Cache::forget('nav_company_services')` on every mutation; extensive logging (`company_service_store_attempt`, `..._validation_failed`, `..._reorder_failed`).
**Effort:** M. **Priority:** P2 — bump to P1 if the marketing site's Services page is expected to change without a deploy.

---

# 5. Dead legacy screens (do not rebuild)

- `resources/views/admin/stores/create.blade.php` and `resources/views/admin/stores/edit.blade.php` exist on disk but are **not routable**: `Route::resource('stores', …)->except(['show','create','edit'])` with `show` re-added separately. The store create/edit UI actually lives in modals inside `admin/stores/index.blade.php` and `admin/stores/show.blade.php`. The two standalone views can be ignored when scoping the rebuild.
- `Admin\StorefrontSlideController::searchProducts` (the `/api/superadmin/stores/{store}/products` JSON endpoint) duplicates `apiListProducts`; the SPA-facing flow only uses the latter. Rebuild one search endpoint, not two.

---

# Gaps worth calling out

1. **The admin panel is ~10% rebuilt for this domain.** Two read-only endpoints and one read-only screen exist; 22 of 27 legacy features have no new-stack implementation at all. The most conspicuous hole is that an admin **cannot change a store's status**: suspend/activate/delete have no new endpoint, so the platform office currently has no way to take down a bad store — this should be treated as P0 despite being "just one button".

2. **Warehouses and stock transfers are completely absent from the new stack** — not just from the admin SPA, but from the new API entirely. `Warehouse`, `StockTransfer`, `StockMovement` and `StockLocation` all still exist as models and the legacy transfer workflow (with `canTransitionTo` rules) is intact, but nothing in `routes/api/v1/**` touches them. Any new-screen effort here is greenfield: controller + routes + SPA view + navigation.

3. **Stock-transfer approval rules are non-trivial and must be ported, not re-invented.** Eight statuses with an explicit transition matrix, per-line `approved_quantities` clamped to `1..requested`, a mandatory `rejection_reason`, and stamping of approver/dispatcher/receiver. The admin-side legacy implementation deliberately **delegates the mutations to `Management\StockTransferController`** so stock-ledger writes happen once — the rewrite should keep that single-writer shape rather than implementing a second stock-moving code path.

4. **Legacy's admin Cancel-Transfer button posts to a management route.** `admin/transfers/show.blade.php` renders the cancel confirm modal with `action="{{ route('management.transfers.cancel', $transfer) }}"`, while there is no `admin.transfers.cancel` route. It works only because superadmins can pass management permissions. When rebuilding the admin transfer screen, add a proper admin-scoped cancel route instead of copying this bug.

5. **Content features have no consumers yet.** `PageStyling`, `Feature` and `StorefrontSlide` are referenced by no new-API controller; only `CompanyService` is (via `Api\V1\Home\HomeController`). Rebuilding their admin screens without the storefront/home consumers means admins can edit content that nothing renders. Recommend confirming which of these three content types survive the rewrite before spending effort — `CompanyService` is the one clearly worth doing now because the read side is already live.

6. **New store list is thinner in ways that break daily scanning.** Admins used the list to spot-check owner, business code, business type, store logo, the homepage "Main" badge, and a direct "view shop" link. None of these are in the new payload or screen, and the new table drops the per-row action menu entirely — the drawer is read-only, so there is no path from the list to any operation.

7. **Two product-catalogue surfaces will confuse the rebuild.** `Api\V1\Management\ProductController` already implements a full business-scoped product CRUD (`index/show/store/update/status/destroy`, with categories too). The admin's platform-wide catalogue is a different screen with different scoping (cross-store, store filter, `/admin/stores/{store}/products`), but the payloads should be shared deliberately rather than duplicated. Decide now whether the admin screens reuse the management endpoints with a widened scope or get their own — either is defensible, but doing both creates two divergent product serializers.

8. **Search-quality regressions in the one screen that does exist.** Legacy `q` searched store name **or** `store_id` **or** owner name, and supported a created-at range; the new endpoint searches name only and has no date filter. Admins pasting a store ID into search — the identifier they actually have — get nothing back.

9. **Emails that will silently disappear if not ported.** Store creation notifies superadmins (`AdminStoreCreated`) and the owner (`StoreActivated`); suspend/reactivate email the owner with the reason (`StoreSuspended`, `StoreReactivated`); an edit that moves a store to inactive/suspended also emails the owner. None of these have a new-stack equivalent, and there is no new-stack code path that would fire them.
