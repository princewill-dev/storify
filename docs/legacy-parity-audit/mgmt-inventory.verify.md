# Verification pass — mgmt-inventory (audience: business)

**Verifier method:** re-read `routes/v1/management.php` lines 438–492 (every warehouse, transfer, send/receive and nested-sections route), the four in-scope legacy controllers in full (`Management\WarehouseController`, `StockTransferController`, `WarehouseTransferController`, `LocationController`), all fourteen in-scope Blade view files (`warehouses/{index,create,edit,show,send,receive,tabs/settings}`, `transfers/{index,create,show}`, `locations/{index,create,show,edit}`), the `Warehouse`/`StockTransfer`/`StockLocation` models, `TransferStatus` enum, `SpatiePermissionSeeder`, the legacy `DashboardController` stock block, `resources/views/management/components/sidebar.blade.php`, the `products/create.blade.php` warehouse-context panel, the new API (`routes/api/v1/management.php`, `routes/api/v1/admin.php`, `Api\V1\Management\{Product,Staff,Dashboard,Search}Controller`), and the SPA (`storify-management`: `router/index.ts`, `src/views/**`, `endpoints.ts`, `AppLayout.vue`; `storify-admin` router/views).

**Coverage verdict:** the feature enumeration is substantively complete. All 14 in-scope view files and all 23 routed warehouse/transfer actions (plus the 7 nested section routes) map to an audit entry; the unrouted `LocationController` and `WarehouseTransferController` are correctly judged dead code. Every `missing` status was re-verified against the new stack — there is no warehouse, transfer, location or stock route in `routes/api/v1/management.php` or `routes/api/v1/admin.php` (the only `warehouse` references are a product `warehouse_id` filter and staff `warehouse_ids` sync), no SPA route/view/nav/endpoint, and no `Api\V1` controller reads or writes `StockMovement`. **No whole feature entry is missing.** Four specific claims are wrong, though, in ways that would misdirect the rebuild; and the dashboard's Stock Transfer Requests table / Warehouses panel omitted from §3.1 are already owned by `mgmt-account-misc.md` §1.5/§1.8 (no duplicate work needed).

---

## Missed features

None. Every in-scope view and route is accounted for, and the inventory surfaces this audit's §3.1 does not enumerate on the dashboard (Stock Transfer Requests table, Warehouses list panel) and in global search (Warehouses result group) are covered by `mgmt-account-misc.md` §1.5, §1.8 and §4.2 respectively — re-adding them here would double-count the same build work.

---

## Corrections

### 1. §2.5 Dispatch — the user never sees the "Insufficient stock…" message

The audit says dispatch "fails the whole transaction with a clear error (`Insufficient stock for "X": N available, M requested.`)". The `RuntimeException` with that text is thrown per line (lines 378–382) but the surrounding `catch (\Throwable $e)` (lines 408–413) rolls back, writes the message only to `Log::error('transfer.dispatch_failed', …)` and flashes the generic **"Failed to dispatch transfer."** The view renders `session('error')`, so the operator sees the generic message — never the availability detail. Rebuild note: surfacing the per-line shortage message is an *improvement*, not legacy parity; don't claim parity for it.

### 2. §1.6 Warehouse Products tab — the grid is `Product`-driven only; the count is `StockLocation`-driven

The audit's "products with `warehouse_id = warehouse` and `quantity > 0` … plus non-zero stock locations" is wrong on the second half: `WarehouseController@show` (lines 111–120) computes `$stockLocationProductIds` from stock locations but **never uses it**; the grid query is only `Product::where('warehouse_id', $warehouse->id)->where('quantity', '>', 0)`. Meanwhile the tab label count (`Products · N`) and the §1.5 "Total Stock" sub-count use `$productCount = stockLocations->where('quantity','>',0)->count()` — stock-location rows, not distinct products, and not the grid size. So the count and the grid can disagree (e.g. stock-location rows whose product has `warehouse_id = null` are counted but not shown; §1.5's "distinct product count" is also this row count, not distinct products). The rebuild should pick one source of truth deliberately.

### 3. §1.4 Delete lives on the index only, and deleted warehouses do **not** drop out of everything

- "Delete a warehouse from index **or detail**" — the show view has no warehouse delete action; the confirm modal and DELETE form exist only in `warehouses/index.blade.php` (delete modal lines 114–130). `edit.blade.php` has none either.
- "deleted warehouses drop out of selection lists (transfer create, move-products destinations)" — only the transfer-create source/destination queries filter `status != 'deleted'`. `WarehouseController@moveProducts` resolves its destination with `Warehouse::where('business_id', …)->findOrFail($id)` (lines 174–176) — **no status filter**, so a deleted warehouse is selectable there. And both the owner's `$user->warehouses()` index query (line 31) and staff's `assignedWarehouses()` (line 31) have no status filter, so a soft-deleted warehouse still appears in the warehouse list and on the dashboard panel (badge "Inactive"), and the index quick-edit can flip it back to `active` — an undelete path. If the rebuild filters deleted rows out of the list (reasonable), that is a behaviour change, not parity.

### 4. §1.1 / §2.1 — there is no address line on warehouse cards, and neither list actually paginates

- "Each card shows: name + `warehouse_code`, status badge, contact person + phone, **address/location line**, count of sections, total items." The card renders `$warehouse->location->name` (index line 37), but `Warehouse` has no `location` relationship (`location_id` was dropped) — the row never renders. There is no address on the card at all (address only appears on the detail page's "Warehouse Info" panel).
- "Browse all accessible warehouses newest-first, **20 per page**" and the §2.1 "paginated (20) list": both controllers call `paginate(20)`/`paginate(20)->withQueryString()`, but neither view renders a pager — `warehouses/index.blade.php` has no `links()` call and `transfers/index.blade.php` uses `<x-management.data-table>` without the `pagination` slot. Users with >20 warehouses/transfers could only ever reach the newest 20. The rebuild's "pagination" item is new work beyond what the legacy screens actually exposed.

### 5. §3.2 Low stock — `StockLocation.min_quantity` is never settable anywhere in legacy

The audit presents `min_quantity` as the operative low-stock model for the warehouse detail card. In legacy, **no UI or controller ever writes `min_quantity > 0`** — every controller insertion hard-codes `min_quantity => 0` (`StockTransferController` line 451, `WarehouseTransferController` line 64, `WarehouseController` line 240); only demo seeders (`ProductAndStockSeeder` lines 262/278/357) populate it. In production the `isLowStock()` filter (`quantity <= min_quantity && min_quantity > 0`) can therefore never fire, so the warehouse "Low Stock" card is effectively always 0 and the real low-stock surfacing is the dashboard's `Product.quantity <= 10` list. If the rebuild is to have a working per-location low-stock indicator it must also introduce a min-level editor — that is new scope, not parity.

### 6. §3.1 — dashboard inventory widgets: add the Stock Transfer Requests table (owned by the misc audit)

The §3.1 "what the user could do" enumerates the stat cards and low-stock list but omits the dashboard's **Stock Transfer Requests table** — latest 10 pending/approved transfers (code link, from, to, item count, status badge, "Requested N ago", header "View all" → `/management/transfers?status=pending`, gated by `transfers view`; `dashboard.blade.php` lines 168–205, `DashboardController` `pending_transfer_list`). It is already a feature entry in `mgmt-account-misc.md` §1.5 (likewise the Warehouses panel §1.8) — list it here only as a cross-reference, do not build it twice.

---

## Smaller notes (no audit text change demanded)

- §2.1's "no search, date range, export or bulk actions" is confirmed, as is §2.9's "route unreferenced by any Blade view" (`moveProducts` appears only in the route file and controller; the `$stores`/`$warehousesList` vars still passed to `warehouses/show` are unused leftovers of a removed modal).
- §2.8 is confirmed: `WarehouseTransferController` has **zero** registered routes — its own views even call non-existent route names (`management.warehouses.send.store` / `.receive.store`) — and the two live `send`/`receive` routes are plain redirects into `transfers.create` with `?from_warehouse=`/`?to_warehouse=`.
- §4.1 is confirmed dead: no route file references `LocationController` or `management.locations.*`; the views' create/edit/delete modals (in `locations/index.blade.php`) plus the standalone create/edit pages are all unreachable.
- §5 permission claim is correct and slightly understated: the seeder also defines `warehouses transfer`, `warehouses receive`, `warehouses approve_transfer` (unused by any route); `transfers view|create|approve|dispatch|receive` and `warehouses view|create|edit|delete` are the ones actually gated.
- §1.2/§1.3 "Assign Staff" is a single-select dropdown (not `multiple`) even though the pivot supports many — one assignee per save through the UI; consistent with §1.9's wording.
- New-API plumbing claims check out: `Product::rules()` accepts `warehouse_id`/`section_id` with no existence/ownership validation and neither key is echoed in the payload; `StaffController@store/@update` intersect `warehouse_ids` with `accessibleWarehouses()` and `@show` returns them; `StockLedgerService` is real and called by `ProcessPosSale`/`PlaceStorefrontOrder`; the new dashboard's `low_stock` is `is_digital = false AND quantity BETWEEN 1 AND 5`.
