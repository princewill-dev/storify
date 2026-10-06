# AD-14 — Warehouses & stock transfers (WS14) — repair pass

Scope: verify the AD-14 implementation in place, fix what is broken, record what
cannot be fixed without touching a file this workstream does not own.

Verification performed: `php -l` on the two controllers, the route module and
the test; `vendor/bin/pint --test` (passed); `php artisan route:list
--path=api/v1/admin` (all 9 URIs register against the expected
`Admin\WarehouseController` / `Admin\StockTransferController` methods); a
route-name uniqueness sweep over the full `route:list --json` output (1048
names, zero collisions) plus a grep of `routes/api/v1/admin/*`,
`routes/api/v1/management/*`, `routes/api/v1/admin.php` and the legacy
`routes/v1/*` files for the names used; a source review of every owned file
against the models/enums it depends on (`Warehouse`, `WarehouseStatus`,
`StockTransfer`, `TransferStatus`, `StockMovement`, `Section`/`SectionStatus`,
`StockLedgerService`, `ActivityLog`, `User::ROLE_*`), and an isolated
TypeScript check of the two payload accesses that were repaired. `php artisan
test` / `npm run typecheck` were not run — the fleet shares one test database
and one toolchain.

---

## Fixed

### 1. `WarehousesView.vue` — `payload.meta?.total` did not exist on the payload type (TS2339)

`src/api/modules/ad14-warehouses-transfers.ts` types the list envelope as
`data: { warehouses, statuses, stats }` with the paginator meta on the outer
`meta`. The view read `payload.meta?.total` as the fallback for the "All" pill
count, which is both dead code and a `vue-tsc` error (`Property 'meta' does not
exist on type '{ warehouses: ...; statuses: ...; stats: ... }'` — confirmed
with a minimal reproduction against the project's own `typescript`).

**Fix, in `/Users/mac/Desktop/my_files/work/storify/storify-admin/src/views/WarehousesView.vue`:**
`total.value = payload.stats?.total ?? response.data.meta?.total ?? 0`.

### 2. `TransfersView.vue` — same `payload.meta?.total` TS2339

**Fix, in `/Users/mac/Desktop/my_files/work/storify/storify-admin/src/views/TransfersView.vue`:**
`total.value = payload.stats?.total ?? response.data.meta?.total ?? 0`.

### 3. `ad14-warehouses-transfers.ts` — the exported list payload types described the wrong envelope

`WarehouseListPayload` / `TransferListPayload` were declared as flat
`{ data: Row[]; meta }`, but the controllers return `{ data: { warehouses |
transfers, statuses, stats }, meta }`. The types are exported for consumers, so
a future caller would have coded against a shape that never occurs.

**Fix, in `/Users/mac/Desktop/my_files/work/storify/storify-admin/src/api/modules/ad14-warehouses-transfers.ts`:**
both types now describe the real envelope
(`data: { warehouses|transfers, statuses, stats }; meta: ListMeta`). The
`warehouseApi` / `transferApi` function generics were already correct and were
not touched.

---

## Checked and clean

- **Routes → methods.** All 9 URIs resolve to existing methods with the right
  signatures: `index(Request)`, `show(Warehouse $warehouse)` /
  `show(Request, StockTransfer $transfer)`, and the five
  `(Request, StockTransfer $transfer)` transitions. `{warehouse}` binds by
  `Warehouse::getRouteKeyName()` (`warehouse_code`) and `{transfer}` by
  `StockTransfer::getRouteKeyName()` (`transfer_code`) — both are the public ids
  the SPA and the WS-7 dashboard deep link (`/transfers/{transfer_code}`) use.
  Static transition segments outscore `{transfer}` in the matcher.
- **Envelope + guard.** Every controller method returns `$this->ok(...)` (with
  `paginationMeta` on both lists) or delegates to a management method that
  returns `ok`/`error`; failures are `abort(403)` per the house pattern. Both
  controllers carry `EnsuresPlatformAdmin` on every action, so a business
  account holding a leaked admin-audience token is refused even though its
  in-business "Super Admin" role carries the `admin.*` permission strings (the
  test exercises this and the exact 403 message).
- **Tenancy / single writer.** The admin audience is the platform, so the two
  reads are deliberately platform-wide. Every mutation delegates to
  `Management\StockTransferController` under a business-scoped clone of the
  admin identity (keeps the admin's id for `approved_by` / `dispatched_by` /
  `received_by`, movement `performed_by` and the activity-log timeline; only
  supplies the transfer's `business_id` as tenant context), the management
  path is business-scoped and transactional, and the admin cancel is a real
  admin route (audit bug #13). The clone exists for one controller call, is
  never persisted, and the original user resolver is restored in `finally` so
  `AdminApiActivityLogger` still audits the admin.
- **SPA ↔ API 1:1.** `warehouseApi.list/show` → the two warehouse routes;
  `transferApi.list/show/approve/reject/dispatch/receive/cancel` → the nine
  transfer routes. Every call is exported by
  `src/api/modules/ad14-warehouses-transfers.ts`; every imported component,
  composable and helper exists (`useDataTable`, `StatusBadge`, `TableFooter`,
  `TableSkeleton`, `EmptyState`, `StatCard`, `AppModal`, `ConfirmDialog`,
  `useUiStore`, `formatDate`, `formatDateTime`). The view fetchers return the
  `{ data: { data, meta } }` shape `useDataTable` expects.
- **Route module hygiene.** No prefix/name/auth/audience wrapper (inherited
  from `routes/api/v1/admin.php`), no imports beyond the two controllers and
  the activity logger. The `permission:admin.warehouses` + `AdminApiActivityLogger`
  group mirrors the older AD-01/AD-17 modules. No route-name collision: the
  full 1048-name sweep is clean, and the legacy `routes/v1/*`,
  `routes/v1/admin_dashboard.php` and the management `api.management.transfers.*`
  names live under different prefixes.
- **Router/nav modules.** `router/modules/ad14-warehouses-transfers.ts`
  default-exports four child routes with `meta.title` and no leading slash;
  `nav/modules/ad14-warehouses-transfers.ts` exports the `{ label, nodes }`
  section shape `AdminLayout.vue` globs, with every leaf gated on
  `admin.warehouses` (seeded on `Platform Admin`, absent from `Finance Admin`).
- **PHP syntax / style.** `php -l` clean on all four PHP files; Pint passes.
- **Test review.** `tests/Feature/Api/ad14warehousestransfersTest.php` matches
  the controller contracts it asserts: default-hides-deleted with a reachable
  Deleted filter, 15/page, per-status counts, all eight `TransferStatus` cases,
  `q` over code/business/location names, clamped `approved_quantities`
  (over-adjusted lines route through awaiting-acknowledgment), required
  `rejection_reason`, 409s on terminal states, `performed_by_id`/`*_by` stamps
  naming the acting admin, the admin-scoped cancel route name, and the
  audience boundaries (permission refusal, platform-role refusal, management
  token refused, guests 401).

## Noted, deliberately left

- `stats.total` on `GET /admin/warehouses` sums `by_status` including deleted
  rows, and the "All" pill uses it while the default list hides deleted rows.
  This is the controller's stated platform-total reading (the deleted count has
  its own pill); it is cosmetic, has no other consumer, and changing it was a
  product decision rather than a defect, so it was left as implemented.

## Cannot fix from this workstream (orchestrator wiring)

### 1. Duplicate "Commerce" sidebar heading

`nav/modules/ad14-warehouses-transfers.ts` exports its two leaves under a
"Commerce" section, matching the roadmap's nesting (Warehouses and Stock
Transfers sit alongside Orders, Stores, Products, Categories, Transactions,
Subscriptions & Plans). `AdminLayout.vue` (shared, not editable) already renders
its own hardcoded "Commerce" group and merges every module section verbatim, so
this renders as a second "Commerce" heading until the groups are coalesced —
the same situation ad05 (Orders) and ad11 (Subscriptions) document. The fix is
to merge these two nodes into the existing Commerce group in the shared layout;
the module's top-of-file mount note says so. Cosmetic only; both entries render
and link correctly.

---

**Verdict:** wiring verified end to end — routes, envelope, guard, delegation,
SPA 1:1 mapping, module shapes and imports all check out. Three SPA defects
fixed in place (two `vue-tsc` errors, two mis-declared payload types); one
cosmetic duplicate nav heading left to the orchestrator because the target file
is shared.
