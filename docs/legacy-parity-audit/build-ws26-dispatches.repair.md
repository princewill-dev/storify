# WS-26 — Dispatches Board — repair pass

**Scope repaired:** the files reported by the WS-26 implementation — API
`DispatchController`, route module and feature test; SPA api module, router
module, nav module and `DispatchesView.vue`.
`php -l`, `php artisan route:list` (route detail + app-wide duplicate-name scan),
Pint `--test` and `@vue/compiler-sfc` compile were run; `php artisan test` /
`npm run typecheck` were **not** (the fleet shares one test database and one
toolchain — the orchestrator runs them serially).

**Continuation pass (post-recharge):** every file on disk was re-read and the
full verification set re-run independently — `php -l` on the three PHP files,
`php artisan route:list` (the route's action/middleware detail and an app-wide
`--json` duplicate-name scan), Pint `--test`, `@vue/compiler-sfc` parse +
`compileScript` + `compileTemplate` on `DispatchesView.vue`, and
`ts.transpileModule` on the three TS modules. The one repair recorded below is
present in the file (test line 77), the route/controller/SPA contract still
holds, and **no further defect was found**, so no additional file was changed.

## Verification results

| Check | Result |
| --- | --- |
| Route → controller method exists with right signature | PASS — `GET api/v1/management/dispatches` → `Api\V1\Management\DispatchController@index(Request): JsonResponse` (confirmed in `route:list`) |
| House envelope + tenant scoping | PASS — `index` returns `$this->ok($data, null, 200, $this->paginationMeta($paginator))`; list rows and stats go through `accessibleQuery()` (`business_id` + `accessibleStoreIds()`); `store_id` is re-checked against `accessibleStores()` and refused with 403 when foreign; no mutations, so no transaction needed |
| SPA calls ↔ routes ↔ api module | PASS — `DispatchesView.vue` makes one call, `dispatchesApi.list` → `GET /management/dispatches`, which is exactly the registered route; payload keys (`dispatches/stats/stores/statuses` + `meta`) match the api module's `DispatchListPayload`/`DispatchListResponse` one-to-one |
| Route module wrapper / duplicate names | PASS — only the required `permission:orders view` group (same shape as WS-13/WS-25); no prefix/name/auth wrapper. App-wide `route:list --json` duplicate-name scan: **none**; the legacy `management.dispatches.index` name lives in a separate group and cannot collide |
| Router/nav module shapes | PASS — router default-exports child `RouteRecordRaw[]` (relative path, `meta.title`); nav default-exports `NavGroup[]` matching `@/nav/types`, auto-merged by AppLayout's `import.meta.glob` |
| Imports resolve | PASS — every `@/...` import (`@/api/client`, `@/api/modules/ws26-dispatches`, `@/stores/ui`, `@/components/{AppModal,PaginationBar,StatCard}.vue`) exists with the named exports used; PHP imports resolve via the route table |
| PHP syntax | PASS — `php -l` on controller, route module and test |
| Pint | PASS — `vendor/bin/pint --test` on all three PHP files |
| Vue SFC compile | PASS — parse + `compileScript` + `compileTemplate` on `DispatchesView.vue` |
| TS syntax | PASS — `ts.transpileModule` on the three TS modules |
| Test helper collisions | PASS — the four `ws26*` helpers are unique repo-wide; RefreshDatabase is applied by `tests/Pest.php` |

## Fixes applied

**1. The feature test's `DeliveryRoute::create` omitted `delivery_days` — under
strict MySQL the insert throws (1364 "Field 'delivery_days' doesn't have a
default").** `delivery_routes.delivery_days` is declared
`unsignedInteger('delivery_days')` with no default in
`2025_11_02_111600_create_delivery_routes_table.php`, the connection runs
`STRICT_TRANS_TABLES` (`config/database.php` `strict => true`, confirmed via
`SELECT @@session.sql_mode`), and every other test that seeds a route
(`ws05`, `ws19`, `ad09`, `ad17`, `ws04`) passes the column. The first test in
`tests/Feature/Api/ws26dispatchesTest.php` did not, so the whole file would have
errored at setup. Fixed at line 77 by adding `'delivery_days' => 2` (and
labelling the `fee` as kobo, matching the audit).

**Everything else verified clean; nothing else changed.**

## Verified clean — deliberately not changed

- **Legacy read-model parity.** The four metric cards reproduce
  `Management\DispatchesController@index` exactly: pending = `pending+assigned`,
  in transit = `picked_up+in_transit+out_for_delivery`, delivered today measured
  on `actual_delivery_at` (not status-change time), and the computed-but-unused
  failed/returned counter is surfaced as a chip. Cards are deliberately **not**
  narrowed by the active filters (legacy behaviour, asserted by test 4), and
  `open` (`status NOT IN (delivered, failed, returned)`) matches the legacy
  sidebar badge definition WS-34 mirrors.
- **Five filters + search.** status (the same 8 statuses as the column comment
  on `order_deliveries.status`), store, created-date from/to; the legacy
  `date_from`/`date_to` and `search` spellings are accepted as aliases so old
  bookmarks keep working. Search covers driver name, tracking number and order
  number, as legacy did.
- **Scoping improvement over legacy (intended).** Legacy computed its cards
  with raw business-wide `OrderDelivery` queries, leaking other stores' counts
  to restricted staff; both rows and cards are scoped here. The restricted-staff
  test mirrors the WS-13 setup that already passes.
- **Badge palette verbatim** from `management/dispatches/index.blade.php`
  (checked line-by-line), including `ucfirst(str_replace('_',' ',$status))`
  labels.
- **Read-only by design.** Legacy's only dispatch route was `index` and the
  screen never advanced delivery statuses; no write route was invented.
- **`(float) $order->total`** in the row payload is the established house idiom
  for money serialisation (same as `OrderParityController`/`SearchController`);
  no arithmetic happens on it.
- **No duplicate SPA route name** — `dispatches` is unique across
  `src/router/index.ts` and all 36 module files (the pre-existing duplicates
  `categories/dashboard/login/plans/products` are other workstreams').

## Hand-off — outside this workstream's file ownership

- **Nav: a second "Sales" section until the orchestrator coalesces.**
  `src/nav/modules/ws26-dispatches.ts` ships a `Sales` group containing only
  Dispatches because AppLayout's inline Sales group (Orders/Customers/
  Transactions) is owned by the shell workstream and cannot be edited here. The
  file already carries a MOUNT NOTE explaining the two options (fold the item
  into AppLayout's group, or delete that group and let the modules own all four
  entries). This follows the same convention as WS-15/WS-29 (duplicate
  "Inventory") and WS-21 (new group); it is called out in `unfixable` for the
  orchestrator wiring pass.
- **Deleted-store rows vs the WS-34 badge.** `accessibleStoreIds()` (the trait
  method WS-13/WS-26 share) does not exclude stores whose `status` is
  `deleted`, while WS-34's `ShellCountsController::storeIds()` and WS-26's own
  filter-modal store list do. A delivery whose store was soft-deleted therefore
  still appears on the board and in `stats.open`, but is not counted by the
  sidebar badge. This is full legacy parity and matches the WS-13 orders board
  exactly, so it was left unchanged; if the fleet wants the badge and the board
  to agree for deleted stores, the fix belongs in `ResolvesManagementContext` /
  WS-34's definition at once, not in one controller.

## Verdict

WS-26 is sound: route/controller wiring, envelope, tenancy, SPA contract,
module shapes, imports and syntax all pass. One defect was found and repaired —
the feature test seeded a `delivery_routes` row without the NOT NULL
`delivery_days` column, which strict MySQL rejects, so the whole test file
would have failed before exercising a single assertion.
