# WS-15 — Inventory Transfers Workflow — repair pass

Scope: verify the WS-15 implementation in place, fix what is broken, record what
cannot be fixed without touching a file this workstream does not own.

Verification performed: `php -l` on all three PHP files, `php artisan
route:list --path=api/v1/management/transfers -v` (all 13 routes register with
the expected permission middleware), route-name collision grep across
`routes/api/v1/management/*`, `routes/api/v1/admin/*`, `routes/v1/*`, and a
source review of every owned file against the models/services/enums it depends
on (`StockTransfer`, `StockTransferItem`, `StockLocation`, `StockMovement`,
`TransferStatus`, `StockLedgerService`, `ActivityLog`, `User::accessible*`,
`Warehouse`/`Store` constants). `php artisan test` / `npm run typecheck` were
not run — the fleet shares one test database and one toolchain.

---

## Fixed

### 1. The list ignored `?status=`/`?q=`, so the dashboard's deep link landed on "All"

`DashboardView.vue` (WS-28, already merged) links its transfer table's "View
all" and pending-count card to `/inventory/transfers?status=pending` — the same
deep link the legacy dashboard used. `TransfersView.vue` initialised its
`status` ref to `''` and never read the query string, so the user arrived on the
All tab with every status mixed in.

**Fix, in `/Users/mac/Desktop/my_files/work/storify/storify-management/src/views/TransfersView.vue`:**
added a `hydrateFromQuery()` (validates the status against the eight
`TransferStatus` values before applying it, so a hand-edited URL cannot produce
a guaranteed 422 from the API's `Rule::in`) and a `syncQuery()` called after
every successful load, so tab clicks and searches stay shareable — matching the
`OrdersView`/`DispatchesView` idiom.

### 2. Multi-statement inline click handler in the create view

`TransferCreateView.vue` used `@click="fromType = option.value; fromId = null"`
on the From-type toggle — the only multi-statement inline handler in the SPA and
an idiom break. Replaced with a `setFromType()` method symmetric to the existing
`setToType()` (which also clears a now-invalid destination). Behaviour
unchanged.

---

## Checked and clean

- **Routes → methods.** All 13 URIs (`transfers` index/store/show,
  submit/cancel/approve/reject/acknowledge/dispatch/receive, `transfers/locations`,
  `transfers/source-products`, `stock-locations`) resolve to existing controller
  methods with `(Request, StockTransfer $transfer)` signatures; `{transfer}`
  binds by `transfer_code` via the model's `getRouteKeyName()`. The literal
  `transfers/locations` and `transfers/source-products` are declared before
  `transfers/{transfer}` in the module, so they are not swallowed by the
  binding.
- **Envelope + tenancy.** Every method returns `$this->ok(...)` /
  `$this->error(...)`; authorisation failures use `abort(403)` per the house
  pattern. Reads and transitions are business-scoped
  (`authorizeTransfer`, `listQuery`), restricted staff are scoped to assigned
  locations (`authorizeTransferSide`, `validatedItems`), and location/product
  ids from the request are resolved through
  `accessibleWarehouses()`/`accessibleStores()`/`business_id`.
- **SPA wiring.** Every `transfersApi` call maps 1:1 to a registered route and
  is exported by `src/api/modules/ws15-transfers.ts`; all imports
  (`StatusBadge`, `AppModal`, `PaginationBar`, `useUiStore`, `useAuthStore`,
  `@/nav/types`) resolve.
- **Route module hygiene.** No prefix/name/middleware wrapper beyond the
  per-route `permission:` groups the house rules require; no route-name
  collision inside `api.management.` (the only other `transfers.*` names live
  under the legacy `management.` prefix, the admin `api.admin.` prefix and the
  legacy admin dashboard).
- **Router/nav modules.** `router/modules/ws15-transfers.ts` default-exports
  three child routes with `meta.title` and no leading slash (static `create`
  declared before `:code`); `nav/modules/ws15-transfers.ts` exports `NavGroup[]`.
  `StoreDetailView`'s existing `router.hasRoute('transfers.index')` probe now
  resolves.
- **PHP syntax + Pint.** `php -l` clean on the controller, route module and
  test; `vendor/bin/pint --test` passes on all three.

---

## Cannot fix from this workstream (orchestrator wiring)

### 1. Warehouse "Send stock" / "Request stock" buttons are not mounted

`src/components/transfers/WarehouseTransferActions.vue` carries the two deep
links into the pre-seeded create screen, but mounting it means editing
`WarehouseDetailView.vue`, which is a WS-06-owned view. The component's
top-of-file MOUNT note gives the exact call
(`<WarehouseTransferActions :warehouse-code="warehouse.warehouse_code" />`).
Until the orchestrator wires it, the capability is reachable only by URL
(`/inventory/transfers/create?from_warehouse=CODE`), which does work.

### 2. Duplicate "Inventory" sidebar sections

`AppLayout.vue` (shared, not editable) renders its own inline Inventory group
(Warehouses) and merges every `nav/modules/*.ts` group verbatim, so the WS-15
entry renders under a second "Inventory" heading until the layout groups are
coalesced. `ws15-transfers.ts` documents this in its MOUNT NOTE; WS-29 and
WS-36 are in the same position. Cosmetic only.

## Re-verification (continuation pass, 2026-10-06)

The whole checklist was re-run against the current tree after the rest of the
fleet landed its later workstreams; nothing regressed and no new defect
surfaced:

- `php -l` clean on the controller, route module and test; `vendor/bin/pint
  --test` still passes on all three.
- `php artisan route:list` shows all 13 WS-15 URIs bound to the expected
  `StockTransferController` methods with the expected `permission:` middleware
  (`transfers view` for the reads + `stock-locations`, `transfers create` for
  store/submit/cancel/acknowledge, `transfers approve` for approve/reject,
  `transfers dispatch`, `transfers receive`). A name-uniqueness sweep over the
  full `route:list --json` output reports no duplicate route name anywhere,
  and the admin AD-14 module (`api.admin.transfers.*`, delegating to
  `Admin\StockTransferController`) does not collide.
- The two fixes below are still present in the views; `TransfersView`'s
  `?status=` deep link from `DashboardView` and `StoreDetailView`'s
  `router.hasRoute('transfers.index')` probe both still resolve.
- SPA imports, the `transfersApi` ↔ route 1:1 map, the router module (three
  children, `meta.title`, no leading slash, static `create` before `:code`)
  and the nav module (`NavGroup[]`, `transfers view` gate) all match the
  house patterns.
- `WarehouseDetailView.vue` now exists but nothing mounts
  `WarehouseTransferActions` in it; that file belongs to WS-06, so the mount
  remains an orchestrator wiring item (below).

## Known parity limitation (deliberately not changed)

The create grid lists `StockLocation` rows per variant, but selection is keyed
by product (payload sends `product_id`, no `product_variant_id`) and the
controller requires `items.*.product_id` to be `distinct`. A product whose stock
sits only in variant-level `StockLocation` rows is therefore listed but cannot
be dispatched. This mirrors legacy exactly (its grid was also product-level and
its dispatch also looked for the variant-less row), and nothing in the current
stack writes variant-level stock rows (POS checkout, storefront checkout and the
product form are all product-level), so it was left as parity rather than
changed into an unrequested contract change.

---

**Verdict:** wiring verified end to end; two SPA defects fixed in place (dead
deep link, idiom break); two mount points left to the orchestrator because the
target files are owned by other workstreams.
