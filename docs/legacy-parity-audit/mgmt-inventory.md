# mgmt-inventory — Legacy → New Stack Feature Inventory

**Domain:** Inventory — warehouses, stock levels, stock adjustments/transfers (audience: business)
**Legacy source of truth:** `storify-api` — `routes/v1/management.php` (lines 438–492), `app/Http/Controllers/Management/{Warehouse,StockTransfer,WarehouseTransfer,Location}Controller.php`, `resources/views/management/{warehouses,transfers,locations}/**`
**New stack:** `storify-api` (`routes/api/v1/management.php`, `app/Http/Controllers/Api/V1/Management/**`) + `storify-management` (`src/router/index.ts`, `src/views/**`, `src/api/endpoints.ts`)
**Status vocabulary:** `exists` = fully usable; `partial` = endpoint or screen present but missing actions/fields/validations; `missing` = absent.

---

## Executive summary

The new management stack has **no inventory module at all**. There is no warehouse endpoint, no transfer endpoint, no location endpoint and no corresponding SPA route, view, nav entry or endpoint binding. The only inventory-adjacent things that exist are three thin fragments:

1. `GET /api/v1/management/dashboard` returns a `products.low_stock` count (a different definition than legacy — see §21) and `DashboardView.vue` prints it as a hint on the Products stat card.
2. `GET /api/v1/management/products` accepts a `warehouse_id` query filter and `POST/PUT products` accepts a nullable `warehouse_id`, but the SPA never sends or renders it, so the product form **cannot assign a product to a warehouse** — legacy *required* a warehouse for every non-digital product (`ProductController@store` rejects with "Please assign the product to a warehouse").
3. `POST/PUT /api/v1/management/staff` accepts `warehouse_ids` and syncs the pivot, but `StaffView.vue` only exposes store checkboxes — there is no warehouse assignment UI, and no warehouse list endpoint to populate one.

Meanwhile the plumbing that inventory depends on is already in place and being exercised: the `Warehouse`, `StockLocation`, `StockMovement`, `StockTransfer`, `StockTransferItem` models, `TransferStatus`/`StockMovementType`/`WarehouseStatus` enums, `StockLedgerService` (idempotent, balanced-before/after ledger writes) and the Spatie permissions `warehouses view|create|edit|delete` and `transfers view|create|approve|dispatch|receive` are all seeded. New POS checkout (`ProcessPosSale`) and storefront checkout (`PlaceStorefrontOrder`) plus order returns already write `StockMovement` rows, so **stock history data accumulates with no UI able to read it**.

**Headline numbers:** 0 of 22 audited features are `exists`; 5 are `partial` (thin indicators or partial API plumbing, never a usable screen); 17 are entirely `missing`. Warehouse CRUD, the warehouse detail screen, the whole stock-adjustment (transfer) workflow and low-stock visibility are P0 — a business that runs warehouses/stores cannot perform a single inventory operation from the new UI.

**Route-level reality check (new API)** — the complete business inventory surface in `routes/api/v1/management.php`:

```php
// nothing. No warehouse, transfer, stock or location routes exist.
// Only vestigial params/filters:
Route::get('products', ...)->when($request->filled('warehouse_id'), ...); // filter never used by SPA
Route::post('products', ...)->validate(['warehouse_id' => ['nullable','integer'], ...]); // never sent by SPA
```

| Area | Legacy features | exists | partial | missing |
|---|---|---|---|---|
| Warehouses, staff & product assignment | 9 | 0 | 3 | 6 |
| Stock adjustment / transfers workflow | 9 | 0 | 0 | 9 |
| Stock levels, low stock & history | 3 | 0 | 2 | 1 |
| Locations (dead legacy module) | 1 | 0 | 0 | 1 |
| **Total** | **22** | **0** | **5** | **17** |

Note on overlap: `LocationController` + `resources/views/management/locations/**` are also listed in the sibling **mgmt-stores** audit; they are included here (§23) because they are explicitly in this domain's scope, with the same conclusion — legacy dead code.

Admin-audience inventory (legacy `Admin/WarehouseController`, `Admin/StockTransferController`, `routes/v1/admin_dashboard.php` lines 142–152, `resources/views/admin/{warehouses,transfers}/**`) is **also entirely absent** from `routes/api/v1/admin.php` and `storify-admin` — but that belongs to the admin-side audit, not this business-audience domain.

---

## 1. Warehouses

### 1.1 Warehouse list (card grid, pagination, quick actions) — API `missing` / SPA `missing`
- **What the user could do (legacy):** Browse all accessible warehouses newest-first, 20 per page (`paginate(20)->withQueryString()`). Staff see only warehouses they are assigned to (`assignedWarehouses`); owners see all their warehouses. Each card shows: warehouse name + `warehouse_code`, active/inactive status badge, contact person + phone, address/location line, count of sections, and total items (`sum(stockLocations.quantity)`). Per-card quick actions: **View**, **Sections**, **Edit** (opens modal with only name / contact person / contact phone / active checkbox), **Delete** (confirm modal → soft delete). Empty state with "Add Warehouse" CTA. There are **no** search, status-filter, sort or bulk actions on this screen.
- **Legacy route:** `GET /management/warehouses` (`management.warehouses.index`); sidebar entry "Warehouses" with a count badge
- **Legacy controller:** `Management\WarehouseController@index` (lines 24–36)
- **Legacy views:** `resources/views/management/warehouses/index.blade.php`
- **New API:** none. No `GET /management/warehouses` endpoint.
- **New SPA:** none. No `/warehouses` route; `AppLayout.vue` has no Inventory/Warehouses nav group.
- **Missing:** the whole screen + endpoint: card grid, status badge, contact/section/item summaries, quick-edit and delete modals, pagination, staff scoping.
- **Side effects:** none (read-only).
- **Effort:** M — **Priority:** P0

### 1.2 Warehouse create form — API `missing` / SPA `missing`
- **What the user could do (legacy):** Create a warehouse with fields: **Name** (required), Contact Person, Address, Country (fixed "Nigeria"), State (select from Nigerian states), City, Contact Phone, Description, **Active** checkbox (default checked), and an **Assign Staff** dropdown of active staff holding the `warehouses view` permission (labelled "Optional — manage later from Staff page"). On save: `user_id` + `business_id` are stamped, `is_active` maps to `status` active/inactive, and `assignedStaff` pivot is synced. Warehouse code (`whs_*`) is auto-generated by the model. Redirects to the index with "Warehouse created."
- **Legacy route:** `GET /management/warehouses/create` (`warehouses.create`), `POST /management/warehouses` (`warehouses.store`) — both behind `permission:warehouses create`
- **Legacy controller:** `Management\WarehouseController@create` / `@store` (lines 38–88)
- **Legacy views:** `resources/views/management/warehouses/create.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** everything — no endpoint, no form, no route. Without this no warehouse can be created, so no stock location can exist for a new business.
- **Side effects:** warehouse row; `staff_assignments` pivot rows.
- **Effort:** M — **Priority:** P0

### 1.3 Warehouse edit — full page, quick-edit modal and AJAX settings tab — API `missing` / SPA `missing`
- **What the user could do (legacy):** Three edit surfaces for the same fields (name, contact person, address, country/state/city, contact phone, description, active, assigned staff):
  1. **Full edit page** (`GET /warehouses/{warehouse}/edit`) — the create form pre-filled, including the staff select; staff syncs only when `staff_ids` is present in the request.
  2. **Quick-edit modal on the index** — only name / contact person / contact phone / active.
  3. **Settings tab on the detail page** — loaded via AJAX (`GET /warehouses/{warehouse}/tab/settings`, HTML fragment) and rendered with `x-html`; a spinner/error/retry state surrounds it; saving PUTs the full update.
  All surfaces enforce ownership (staff must be assigned to the warehouse; otherwise 403).
- **Legacy route:** `GET /management/warehouses/{warehouse}/edit`, `PUT /management/warehouses/{warehouse}` (`warehouses.update`); `GET /management/warehouses/{warehouse}/tab/{tab}` (`warehouses.tab`)
- **Legacy controller:** `Management\WarehouseController@edit` / `@update` / `@loadTab` / `@tabSettings` (lines 276–337, 361–392)
- **Legacy views:** `resources/views/management/warehouses/edit.blade.php`, `index.blade.php` (modal), `tabs/settings.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** everything. In the new SPA the settings tab pattern can collapse into a normal edit form/modal — the AJAX-tab implementation is a legacy surface, not a requirement; the field set and staff sync are the requirement.
- **Side effects:** warehouse update; `staff_assignments` sync.
- **Effort:** M — **Priority:** P0

### 1.4 Warehouse lifecycle: deactivate / soft delete — API `missing` / SPA `missing`
- **What the user could do (legacy):** Toggle a warehouse inactive via the create/edit form (status flips active↔inactive while the record and its stock remain). Delete a warehouse from index or detail with a confirm modal; delete is a **soft delete** (`status = 'deleted'`) with no guard for remaining stock or sections; deleted warehouses drop out of selection lists (transfer create, move-products destinations) but the record/ledger survives.
- **Legacy route:** part of `PUT /management/warehouses/{warehouse}` and `DELETE /management/warehouses/{warehouse}` (`warehouses.destroy`, behind `permission:warehouses delete`)
- **Legacy controller:** `Management\WarehouseController@update` / `@destroy` (lines 327–356)
- **Legacy views:** `index.blade.php` (delete modal), `edit.blade.php`, `show.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** everything: activation toggle, delete confirmation, soft-delete semantics, deleted-status filtering in any list.
- **Side effects:** status change only (no stock cleanup).
- **Effort:** S — **Priority:** P1

### 1.5 Warehouse detail shell: stock summary + info panel + tab framework — API `missing` / SPA `missing`
- **What the user could do (legacy):** Open a warehouse to see four stat cards — **Total Stock** (sum of quantities, with distinct product count), **Sections** (total non-deleted, active count), **Low Stock** (count of stock locations where `quantity <= min_quantity && min_quantity > 0`, amber when > 0), **Assigned Staff** count — plus a tab bar (Products · Sections · Activity · Settings, deep-linkable via `#hash`) and a "Warehouse Info" panel: code, address, city/state, contact, phone, created date. Header actions: **Add Product** (permission `products create`) and **Stock Adjustment** (deep-links to transfer create with `?from_warehouse=CODE`), plus active/inactive badge. Ownership/assignment enforced with 403s.
- **Legacy route:** `GET /management/warehouses/{warehouse}` (`warehouses.show`)
- **Legacy controller:** `Management\WarehouseController@show` (lines 90–146)
- **Legacy views:** `resources/views/management/warehouses/show.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the whole detail hub. In the new stack there is no way to open a warehouse, see its stock totals, or navigate to its products/activity.
- **Side effects:** none (read-only).
- **Effort:** M — **Priority:** P0

### 1.6 Warehouse Products tab: stock levels per product, search, bulk & row actions — API `partial` / SPA `partial`
- **What the user could do (legacy):** On the warehouse detail Products tab, browse the products held at that warehouse (products with `warehouse_id = warehouse` and `quantity > 0`, ordered by name, plus non-zero stock locations) as cards: image, name, section name, price, stock badge (green >10, amber 1–10, red 0), "At Store: {store}" indicator, and a per-card 3-dot menu (View / Edit / Delete product). A client-side **search box** filters cards by name. A **bulk selection bar** appears when checkboxes are ticked — Select All / Deselect All, count of selected, and a **Delete** button that opens a confirm modal posting `product_ids[]` to the products bulk-delete endpoint (permission `products delete`). Empty state with "Add Product".
- **Legacy route:** `GET /management/warehouses/{warehouse}` (Products tab content); bulk delete posts to `POST /management/products/bulk-delete`
- **Legacy controller:** `Management\WarehouseController@show`
- **Legacy views:** `resources/views/management/warehouses/show.blade.php` (Products tab)
- **New API:** `partial` — `GET /api/v1/management/products` supports a `warehouse_id` filter and returns `quantity`, but there is no warehouse entity, no per-location breakdown and no `min_quantity`; `POST /management/products/bulk-delete` (legacy endpoint) has no new-API equivalent at all.
- **New SPA:** `partial` — `ProductsView.vue` shows a flat "Stock" column and a "Stock quantity" form field, and has search/status/store filters; it has no warehouse scope, no per-section grouping, no bulk selection, and cannot be opened "for a warehouse".
- **Missing:** warehouse-scoped stock browsing, per-section grouping, product-card stock badges, bulk select/delete, the warehouse-context Add Product entry, and the server-side warehouse filter wiring in the SPA.
- **Side effects (legacy):** bulk delete removes products (with their images/files).
- **Effort:** M — **Priority:** P0

### 1.7 Add product to warehouse (warehouse-context product form) — API `partial` / SPA `missing`
- **What the user could do (legacy):** From the warehouse detail header or empty state, a warehouse-scoped product create form (`/management/warehouses/{warehouse}/add`): the warehouse is pre-selected and displayed in a "Warehouse Context" panel; the **Assign to Warehouse** select is required for every non-digital product ("Please assign the product to a warehouse"); the **Section** select is scoped to that warehouse (or all of the user's sections); stock quantity + initial stock fields are filled in. On save the product is bound to `warehouse_id` (and section-derived warehouse).
- **Legacy route:** `GET /management/warehouses/{warehouse}/add` (`warehouses.products.create`) → `ProductController@create` with warehouse pre-selection; `GET /management/products/create`
- **Legacy controller:** `Management\ProductController@create` / `@store` (warehouse-required validation, section→warehouse auto-assign)
- **Legacy views:** `resources/views/management/products/create.blade.php` (Warehouse Context panel, warehouse/section selects)
- **New API:** `partial` — `POST/PUT /api/v1/management/products` accept a nullable `warehouse_id` and `section_id`, but nothing validates the warehouse belongs to the business/staff scope, nothing requires it for physical products, and the payload never returns `warehouse_id`.
- **New SPA:** `missing` — `ProductsView.vue`'s form requires a **store** and has no warehouse or section selector; there is no entry point from a warehouse.
- **Missing:** warehouse selector + warehouse-required validation, warehouse-scoped section list, warehouse-context entry point, and the "products are first received at a warehouse" model. Without this, physical products created in the new UI are never attached to any warehouse, so they can never be stocked or transferred.
- **Side effects (legacy):** product row; stock seeded on the warehouse product quantity.
- **Effort:** M — **Priority:** P0

### 1.8 Warehouse Sections tab (linked sections overview) — API `missing` / SPA `missing`
- **What the user could do (legacy):** On the detail page's Sections tab, see the warehouse's non-deleted sections as cards (name, section code, product count, active/inactive badge) that link to the section detail, with an **Add Section** button and an empty state CTA. (Section CRUD itself lives in the catalog/org domain — `SectionController` + `resources/views/management/sections/**` — and is not re-audited here.)
- **Legacy route:** `GET /management/warehouses/{warehouse}/tab/...` content; section links via `management.sections.*`
- **Legacy controller:** `Management\WarehouseController@show` (Sections tab)
- **Legacy views:** `resources/views/management/warehouses/show.blade.php` (Sections tab)
- **New API:** none.
- **New SPA:** none.
- **Missing:** the warehouse-scoped sections overview and Add Section entry. Blocked on the warehouse detail screen existing; section CRUD is covered by the catalog audit.
- **Effort:** S — **Priority:** P2

### 1.9 Assign staff to warehouses — API `partial` / SPA `missing`
- **What the user could do (legacy):** On warehouse create/edit (and the settings tab), pick the staff member responsible for the warehouse from active staff holding `warehouses view`; the assignment drives staff data scoping — assigned staff see only their warehouses in the warehouse list, detail, transfer source/destination pickers, and stock data. Staff-side, `warehouse_ids` is a multi-assignment pivot.
- **Legacy route:** part of `POST/PUT /management/warehouses` and the staff edit form; scoping is enforced throughout `WarehouseController` and `StockTransferController@create`
- **Legacy controller:** `Management\WarehouseController@store`/`@update`; `Management\StaffController`
- **Legacy views:** `warehouses/create.blade.php`, `warehouses/edit.blade.php`, `tabs/settings.blade.php`
- **New API:** `partial` — `Api\V1\Management\StaffController@store/@update` validate `warehouse_ids[]`, intersect with `accessibleWarehouses()` and sync the pivot; `@show` returns `warehouses` in the staff payload. But there is no warehouse list endpoint for a picker, and no warehouse-side assignment UI.
- **New SPA:** `missing` — `StaffView.vue` renders store checkboxes only (`store_ids`); `warehouse_ids` is never sent or shown.
- **Missing:** warehouse assignment checkboxes/dropdown in staff create/edit + the warehouse form's staff select + the warehouse list source for those pickers.
- **Side effects (legacy):** `staff_assignments` pivot rows; changes staff data visibility.
- **Effort:** S — **Priority:** P1

---

## 2. Stock adjustment / transfers (the "Stock Adjustment" workflow)

Legacy route block lines 467–492: `transfers create`, `store`, `submit`, `cancel`, `acknowledge` (create permission), `index`, `show` (view), `approve`, `reject` (approve), `dispatch`, `receive`. Status machine: `draft → pending → (approved | awaiting_acknowledgment → approved) → dispatched → received`, plus `rejected` (from pending) and `cancelled` (from draft/pending/approved). The new API has **none** of these routes.

### 2.1 Transfer list with status filter tabs — API `missing` / SPA `missing`
- **What the user could do (legacy):** Sidebar "Inventory → Stock Adjustment" opens a paginated (20) list of transfers, with **status tabs** for All + every `TransferStatus` (Draft, Pending, Approved, Awaiting Acknowledgement, Dispatched, Received, Rejected, Cancelled). Each row: transfer code (link to detail) + requester name, From location, To location, item count, status badge, created date. Empty state CTA "New Transfer". Header action "New Adjustment" (permission `transfers create`). No search, date range, export or bulk actions.
- **Legacy route:** `GET /management/transfers` (`management.transfers.index`)
- **Legacy controller:** `Management\StockTransferController@index` (lines 22–38)
- **Legacy views:** `resources/views/management/transfers/index.blade.php`
- **New API:** none.
- **New SPA:** none (no `/transfers` route or nav entry).
- **Missing:** the entire list screen + endpoint + status filter.
- **Effort:** M — **Priority:** P0

### 2.2 Create transfer (stock adjustment): source/destination picker + product grid + draft/submit — API `missing` / SPA `missing`
- **What the user could do (legacy):** Build a stock adjustment between any two locations:
  - **From**: Warehouse or Store radio toggle, then a location select (staff see only assigned locations; owners see all non-deleted).
  - **To**: same, with source and destination prevented from being identical (server-side validation).
  - **Products**: after choosing a source, a card grid of all products with available stock at that source loads (live `StockLocation` quantity > 0 plus legacy `Product.quantity` fallback); each card shows image, product code, available count with green/amber/red badge, and a **search box** (name/code) + **Select All / Deselect All**; selecting a product reveals −/+ quantity steppers and a numeric input capped at available (min 1); item count and total units are shown in a fixed bottom bar with "Clear all".
  - **Notes**: optional free-text note.
  - **Two submit modes**: **Save Draft** (status `draft`) or **Submit for Approval** (status `pending`), disabled until source, destination and ≥1 item are chosen. Server validates location types/IDs, min 1 item, quantity ≥ 1, and redirects to the transfer detail with a flash ("Transfer request submitted for approval." / "Transfer saved as draft.").
  - The form also supports **pre-selection** via `?from_warehouse=CODE` / `?to_warehouse=CODE` (used by the warehouse Send/Receive quick links), which preselects the source/destination warehouse.
- **Legacy route:** `GET /management/transfers/create` (`transfers.create`), `POST /management/transfers` (`transfers.store`), both behind `permission:transfers create`
- **Legacy controller:** `Management\StockTransferController@create` / `@store` (lines 40–266); also orphaned `WarehouseTransferController` variants (see §18)
- **Legacy views:** `resources/views/management/transfers/create.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** everything. This is the primary day-to-day inventory action ("Stock Adjustment") of the whole domain and is entirely unbuilt. Validation log (`transfer.created`) also missing.
- **Side effects (legacy):** `StockTransfer` + items rows (no stock moves until dispatch); activity log entry.
- **Effort:** L — **Priority:** P0

### 2.3 Transfer detail: summary, items, timeline, details, action bar — API `missing` / SPA `missing`
- **What the user could do (legacy):** Open a transfer to see: status badge + label; a summary strip (From → To with codes, item count, total units, requester, created date/time); an **items table** with product image/name/code, requested quantity, and approved quantity (editable inline when the viewer holds `transfers approve` and the transfer is pending/awaiting-ack; adjusted values shown amber with the original struck through); a **timeline** (Created → Submitted → Approved → Dispatched → Received, or Rejected/Cancelled, each with actor + timestamp when done, "Pending" otherwise); a **details** panel (From, To, Requested by, Approved by, Dispatched by, Received by, Notes, Rejection reason); and a permission-aware **action bar** (Approve, Reject, Submit, Acknowledge, Dispatch, Receive, Cancel) with confirm modals for dispatch/receive/cancel. An "Awaiting acknowledgement" banner explains that quantities were adjusted.
- **Legacy route:** `GET /management/transfers/{transfer}` (`management.transfers.show`)
- **Legacy controller:** `Management\StockTransferController@show` (lines 268–276)
- **Legacy views:** `resources/views/management/transfers/show.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the whole detail screen, timeline, per-line approved quantities and action bar.
- **Effort:** L — **Priority:** P0

### 2.4 Transfer approval with quantity adjustment + acknowledgement loop — API `missing` / SPA `missing`
- **What the user could do (legacy):** A user with `transfers approve` approves a pending transfer, optionally **lowering the approved quantity per line** (server clamps to `min(approved, requested)`, defaults to requested; min 1). If any line was adjusted, the transfer moves to `awaiting_acknowledgment` and the requester/source side must **Acknowledge** to confirm the reduced quantities before the transfer becomes `approved`; if nothing was adjusted it goes straight to `approved`. **Reject** requires a mandatory rejection reason (max 1000 chars) and sets `rejected` with the approver recorded. Both transitions are state-guarded (`canBeApproved`, `canBeRejected`) and logged.
- **Legacy route:** `PATCH /management/transfers/{transfer}/approve` / `reject` (`transfers.approve`, `transfers.reject`) behind `permission:transfers approve`; `PATCH /management/transfers/{transfer}/acknowledge` (`transfers.acknowledge`) behind `permission:transfers create`
- **Legacy controller:** `Management\StockTransferController@approve` / `@reject` / `@acknowledge` (lines 291–356, 487–510)
- **Legacy views:** `transfers/show.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the entire approval semantics — per-line approved quantities, adjusted→awaiting-ack state, reject reason, and all state guards. Notifications: legacy sends none (log only); the new implementation still needs the log/audit and ideally notifications.
- **Side effects (legacy):** item `approved_quantity` writes; status + `approved_by`; log entries `transfer.approved`, `transfer.rejected`, `transfer.acknowledged`.
- **Effort:** M — **Priority:** P0

### 2.5 Transfer dispatch with stock validation — API `missing` / SPA `missing`
- **What the user could do (legacy):** A user with `transfers dispatch` dispatches an approved transfer (confirm modal). For each line the server looks up the source `StockLocation` (variant-aware) and **fails the whole transaction** with a clear error ("Insufficient stock for "X": N available, M requested.") if stock is short. On success it writes a `removed` ledger entry per line (balance before/after, performed-by, reference), decrements `Product.quantity` for the source location and clears `warehouse_id`/`store_id` when the product hits zero, then sets status `dispatched` + `dispatched_by`. All atomic in a DB transaction.
- **Legacy route:** `PATCH /management/transfers/{transfer}/dispatch` (`transfers.dispatch`) behind `permission:transfers dispatch`
- **Legacy controller:** `Management\StockTransferController@dispatch` (lines 358–414), `@syncProductQuantity` (lines 528–563)
- **Legacy views:** `transfers/show.blade.php` (Dispatch confirm modal)
- **New API:** none. (`StockLedgerService::recordRemoval` already exists and is used by POS/storefront, so the service layer is ready.)
- **New SPA:** none.
- **Missing:** the endpoint, the stock-sufficiency guard, ledger + product-quantity sync, and the confirm action.
- **Side effects (legacy):** `StockMovement` rows (type `removed`); product quantity/location updates; status + `dispatched_by`; log `transfer.dispatched`.
- **Effort:** M — **Priority:** P0

### 2.6 Transfer receive into destination — API `missing` / SPA `missing`
- **What the user could do (legacy):** A user with `transfers receive` receives a dispatched transfer (confirm modal). For each line the destination `StockLocation` is found or **created** (variant-aware, business-scoped, qty 0), an `added` ledger entry is written, and the destination `Product.quantity` is incremented (product re-assigned to the destination store/warehouse). Status → `received` + `received_by`. Atomic; errors roll back.
- **Legacy route:** `PATCH /management/transfers/{transfer}/receive` (`transfers.receive`) behind `permission:transfers receive`
- **Legacy controller:** `Management\StockTransferController@receive` (lines 416–485)
- **Legacy views:** `transfers/show.blade.php` (Receive confirm modal)
- **New API:** none.
- **New SPA:** none.
- **Missing:** the endpoint, destination stock-location creation, ledger addition and product sync.
- **Side effects (legacy):** `StockMovement` (type `added`); `StockLocation` create/update; product quantity/location; status + `received_by`; log `transfer.received`.
- **Effort:** M — **Priority:** P0

### 2.7 Transfer submit & cancel — API `missing` / SPA `missing`
- **What the user could do (legacy):** **Submit** a draft (requires ≥1 item) to move it into the approval queue (status `pending`); populates `transfer.submitted` log. **Cancel** a draft, pending or approved transfer (confirm modal) → status `cancelled`; dispatched/received/rejected transfers cannot be cancelled. Both actions appear in the detail action bar conditioned on `canBeSubmitted()` / `canBeCancelled()` and the user's permissions (`transfers create`).
- **Legacy route:** `PATCH /management/transfers/{transfer}/submit` / `cancel` (`transfers.submit`, `transfers.cancel`) behind `permission:transfers create`
- **Legacy controller:** `Management\StockTransferController@submit` / `@cancel` (lines 278–289, 512–523)
- **Legacy views:** `transfers/show.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** both transitions and their guards.
- **Effort:** S — **Priority:** P1

### 2.8 Warehouse Send / Receive quick flows (legacy consolidated; orphaned controllers/views) — API `missing` / SPA `missing`
- **What the user could do (legacy):** Two dedicated screens reached from a warehouse:
  - **Send Inventory** (`GET /management/warehouses/{warehouse}/send` → `WarehouseTransferController@sendForm`, view `warehouses/send.blade.php`): destination type (warehouse/store) + destination select + optional note + product grid of stock at the warehouse with search/select-all/quantity steppers, "Send" creates a `pending` transfer (`@initSend`).
  - **Receive Inventory** (`GET /management/warehouses/{warehouse}/receive` → `@receiveForm`, view `warehouses/receive.blade.php`): source-warehouse select, then an AJAX product list (`GET .../products-json` → `productsJson`) of that warehouse's available stock, search/select-all/quantities, "Request" creates a `pending` transfer from source to this warehouse (`@initReceive`).
  Both are **no longer linked from the routed UI**: the current routes for `warehouses/{warehouse}/send|receive` (lines 476–477) are redirects that open `transfers/create` with `?from_warehouse=` / `?to_warehouse=` pre-selection, and `WarehouseTransferController` has **no routes at all** — the dedicated pages and JSON endpoint are dead code superseded by §2.2 + §2.5/§2.6. **Judgement:** the screens themselves are obsolete by deliberate consolidation; the *capability* (send from a warehouse, request into a warehouse) must survive as pre-seeded transfer-create flows, not as standalone pages.
- **Legacy route:** redirect stubs `GET /management/warehouses/{warehouse}/send` / `receive`; orphaned `WarehouseTransferController` methods
- **Legacy controller:** `Management\WarehouseTransferController` (lines 21–286, unrouted)
- **Legacy views:** `resources/views/management/warehouses/send.blade.php`, `receive.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** nothing additional beyond §2.2/2.5/2.6 **if** those are built with pre-seeded source/destination deep links (warehouse → "Send stock", warehouse → "Request stock" buttons).
- **Effort:** S (deep links on top of 2.2) — **Priority:** P1

### 2.9 Bulk "Move all products" warehouse action — API `missing` / SPA `missing`
- **What the user could do (legacy):** `POST /management/warehouses/{warehouse}/move-products` (permission `warehouses edit`) takes selected product IDs + a destination (store or warehouse) + optional notes and optional `complete_immediately`; it creates a transfer containing **every unit of each selected product** (`quantity = product.quantity`) and, when the user holds all four transfer permissions and requests it, auto-approves, dispatches and receives in one transaction (writing ledger entries and reassigning products), then redirects to the transfer. **Judgement:** this route is not referenced by any Blade view — it is an orphaned API-style bulk action with no UI — and its behaviour duplicates §2.2 with a "move everything" shortcut. Likely obsolete unless a bulk "relocate warehouse stock" shortcut is wanted; flag rather than rebuild blindly.
- **Legacy route:** `POST /management/warehouses/{warehouse}/move-products` (`warehouses.move-products`) — unreferenced by views
- **Legacy controller:** `Management\WarehouseController@moveProducts` (lines 148–274)
- **Legacy views:** none
- **New API:** none.
- **New SPA:** none.
- **Missing:** nothing required; note for product decision.
- **Effort:** S — **Priority:** P2

---

## 3. Stock levels, low stock & history

### 3.1 Inventory metrics on the dashboard — API `partial` / SPA `partial`
- **What the user could do (legacy):** The business dashboard surfaces inventory as first-class widgets: stat cards for **Stock Value** (Σ quantity × product amount across accessible store+warehouse stock locations), **Warehouses** (count + total items stocked), **Transfer Requests** (pending + approved count), the main subtitle "N in stock" (total units); a **Low Stock Products** card listing up to 6 active store products with quantity ≤ 10 (> 0) showing product name, store and "N left", plus an out-of-stock count badge; all scoped to the user's accessible stores/warehouses (staff see assigned locations only).
- **Legacy route:** `GET /management` (`management.dashboard`)
- **Legacy controller:** `Management\DashboardController@index` (stock block lines ~140–215)
- **Legacy views:** `resources/views/management/dashboard.blade.php`
- **New API:** `partial` — `GET /api/v1/management/dashboard` returns only `products.total` and `products.low_stock` (count of non-digital products with `quantity` 1–5). No stock value, total stock, warehouses, warehouse stock, pending transfers or low-stock list. Scoping is store-based, with no warehouse awareness.
- **New SPA:** `partial` — `DashboardView.vue` renders one Products stat card with a "N low stock" hint; no stock value / warehouse / transfer cards and no low-stock list.
- **Missing:** stock value & total-stock metrics, warehouse metrics (count/items), pending-transfer metric, the low-stock product list (name + store + qty), out-of-stock count, and location-scoped stock accounting (legacy counted both store and warehouse `StockLocation`s; the new API ignores warehouses and `StockLocation` entirely).
- **Effort:** M — **Priority:** P1

### 3.2 Low-stock views & thresholds (per-location `min_quantity`, badges) — API `partial` / SPA `partial`
- **What the user could do (legacy):** Low stock is defined **per stock location** via `StockLocation.min_quantity` (`isLowStock()` = `quantity <= min_quantity && min_quantity > 0`); the warehouse detail shows a Low Stock stat card (amber when > 0), product cards colour stock badges (green > 10, amber 1–10, red 0), and the dashboard gives a low-stock list (§3.1). There is no dedicated global low-stock page and no restock action — it is a set of indicators. (Product-level "out of stock" = quantity ≤ 0, shown as a dashboard badge.)
- **Legacy route:** warehouse `show` + `GET /management` dashboard
- **Legacy controller:** `Management\WarehouseController@show`, `Management\DashboardController@index`; `App\Models\StockLocation@isLowStock`
- **Legacy views:** `warehouses/show.blade.php`, `dashboard.blade.php`, `stores/show.blade.php`, `admin/warehouses/show.blade.php`
- **New API:** `partial` — only the dashboard `products.low_stock` count, using a **different rule** (`Product.quantity` between 1 and 5; ignores `min_quantity`, variants, and warehouses), and `StockLocation` is never queried by any management endpoint.
- **New SPA:** `partial` — only the dashboard hint and the red "Stock" column on `ProductsView`.
- **Missing:** the `min_quantity` model (no API writes/reads it), per-warehouse and per-location low stock, low-stock product list with drill-down, out-of-stock surfacing and consistent thresholds.
- **Effort:** M — **Priority:** P1

### 3.3 Stock movement history / ledger read views — API `missing` / SPA `missing`
- **What the user could do (legacy):** On the warehouse detail **Activity tab**, a table of the latest 20 stock movements for that warehouse's stock locations: product, IN/OUT/MOVE badge (`added`/`removed`/anything else), signed quantity, balance before, balance after, performed-by (or "System"), timestamp. Admin-side, `admin/warehouses/show.blade.php` shows the same recent-movements concept for superadmins. There is no global stock-history page, no filters/pagination/export in legacy — it is a fixed last-20 list.
- **Legacy route:** `GET /management/warehouses/{warehouse}` (Activity tab); `GET /management/warehouses/{warehouse}/tab/{tab}` for tab loading
- **Legacy controller:** `Management\WarehouseController@show` (lines 122–126)
- **Legacy views:** `resources/views/management/warehouses/show.blade.php` (Activity tab)
- **New API:** none — no endpoint reads `StockMovement`. The new stack **writes** movements (`StockLedgerService` from POS sales, storefront checkout and order returns) with `movement_code`, type (`added|removed|transferred|adjusted`), `balance_before/after`, reference and `performed_by`, so the data exists but is unreadable.
- **New SPA:** none.
- **Missing:** history endpoint(s) (by warehouse/location/product), movement-type badges, balance columns, performed-by, pagination/filters (legacy only had last 20; the rebuild should add paging), and the Activity tab itself.
- **Effort:** M — **Priority:** P1

---

## 4. Locations (legacy dead module)

### 4.1 Locations CRUD — API `missing` / SPA `missing` (legacy too: unreachable dead code)
- **What the user could do (legacy):** `LocationController` provides a full CRUD for business "sites/branches": list as cards (name, `location_code`, active/inactive badge, address/city/state, warehouse count, per-card View/Edit/Delete), create/edit modal forms (name required, address, Nigeria country, state + city selects, active checkbox) and delete confirm. `GET locations/{location}/show` lists the location's warehouses/sections. **However: there are no routes for any of it** — `LocationController` is referenced nowhere in `routes/`, the views are unreachable, and the model's `location_id` column was dropped from `warehouses` (AGENTS.md: "Location model exists in DB but is NOT used in UI"). **Judgement:** dead legacy code; do not rebuild without a product decision to reintroduce business sites/branches as a grouping concept.
- **Legacy route:** none registered (views/controller orphaned)
- **Legacy controller:** `Management\LocationController` (all methods, unrouted)
- **Legacy views:** `resources/views/management/locations/{index,create,show,edit}.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** nothing required. (Also flagged in the mgmt-stores audit.)
- **Effort:** S (if ever needed) — **Priority:** P2

---

## Gaps worth calling out

1. **The entire domain is unbuilt, not merely thin.** Legacy had ~25 routed inventory actions across 3 screens + 4 modal/confirm flows; the new API/SPA have zero inventory routes and zero inventory views. The sidebar group "Inventory → Stock Adjustment" and "Warehouses" simply do not exist in `AppLayout.vue`.
2. **Stock movement data is already being written with no reader.** `ProcessPosSale`, `PlaceStorefrontOrder` and order returns call `StockLedgerService`, producing ledger rows with balances — but there is no endpoint or screen that surfaces them. Building §3.3 read APIs is cheap relative to its value (auditability) and should ride along with the warehouse Activity tab.
3. **The product↔warehouse link is broken.** Legacy required `warehouse_id` for non-digital products and auto-derived it from sections; the new product API accepts but never validates/echoes it and the SPA form only offers a store. Until §1.7 is fixed, even building warehouse screens would show empty warehouses — new products can never enter stock.
4. **Low-stock definitions diverged.** Legacy: `StockLocation.quantity <= min_quantity` per location (warehouses and stores). New: `Product.quantity` between 1 and 5, store-scoped. Same label, different meaning — reconcile in the API before building the UI or the numbers will contradict themselves.
5. **Permissions are already seeded** (`warehouses view|create|edit|delete`, `transfers view|create|approve|dispatch|receive`, plus role mappings incl. Warehouse Manager / Inventory Clerk) — no RBAC design work is needed, only route middleware and UI gating matching legacy (`transfers.approve` gates per-line approved quantities; `transfers.dispatch`/`transfers.receive` gate the confirm buttons).
6. **Two legacy surfaces are deliberately obsolete — don't rebuild them literally:** the standalone warehouse Send/Receive pages + JSON endpoint (§2.8, superseded by pre-seeded transfer create), the `move-products` bulk route with no UI (§2.9), and the Locations module (§4.1, dead code). Keep the capabilities, drop the screens.
7. **No exports/prints exist for this domain in legacy** — nothing to port there. Notifications in legacy were log-only (`transfer.*` log entries), so email/SMS parity is not a blocker, but activity/audit logging should be preserved.
8. **Admin-audience inventory is also missing** (legacy `Admin/WarehouseController` + `Admin/StockTransferController` with approve/reject/dispatch/receive, `admin/warehouses/**` + `admin/transfers/**` views; `admin.warehouses` permission) — no routes in `routes/api/v1/admin.php` and no views in `storify-admin`. Out of scope for this business-audience audit; flagged so the admin-side sweep covers it.

### Suggested build order

1. **Warehouse CRUD + list + detail shell** (§1.1–1.5) and **product↔warehouse assignment** (§1.7) — the data foundation.
2. **Transfer list + create + detail + dispatch/receive** (§2.1–2.3, 2.5, 2.6) — the daily stock-adjustment loop (approve/reject §2.4 immediately after, since the create flow branches on it).
3. **Stock history / Activity tab** (§3.3) and **low-stock + dashboard metrics** (§3.1, §3.2) — visibility once movement exists.
4. **Submit/cancel (§2.7), send/receive deep links (§2.8), staff assignment UI (§1.9), sections tab (§1.8)** — polish.
5. Skip §2.9 and §4.1 unless product asks.
