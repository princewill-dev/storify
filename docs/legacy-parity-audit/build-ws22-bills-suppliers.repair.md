# WS-22 repair — Accounting: Bills & Suppliers

**Verdict:** repaired — the first repair pass's two fixes are confirmed present in the working
tree; this recharge pass re-ran all seven verification checks against the current files and
found no further defects. Nothing unfixable.

This pass did not edit any file: independent re-verification of the exact current state showed
the workstream clean. The two fixes listed below were applied by the earlier pass and are
re-confirmed here by reading the actual files (not the earlier report alone).

## Files verified

| Piece | Path |
| --- | --- |
| API controllers | `app/Http/Controllers/Api/V1/Management/Accounting/SupplierController.php`, `.../BillController.php` |
| Route module | `routes/api/v1/management/ws22-bills-suppliers.php` |
| API test | `tests/Feature/Api/ws22billssuppliersTest.php` (11 tests) |
| SPA views | `storify-management/src/views/accounting/{BillsView,SuppliersView,BillFormView,BillDetailView,SupplierDetailView}.vue` |
| SPA pill | `storify-management/src/components/BillStatusPill.vue` |
| SPA API module | `storify-management/src/api/modules/ws22-bills-suppliers.ts` |
| SPA router module | `storify-management/src/router/modules/ws22-bills-suppliers.ts` |
| SPA nav module | `storify-management/src/nav/modules/ws22-bills-suppliers.ts` |

## Fixes in place (applied by the first repair pass, re-verified now)

1. **Un-scoped reversal lookup on the bill detail payload.**
   `BillController::detail()` resolves the reversing entry with
   `JournalEntry::query()->where('business_id', $bill->business_id)->where('reversal_of_id', $entry->id)`
   (BillController.php:569-574). The `business_id` scope is present, matching the scoped lookup
   in `void()`. Without it, a corrupt cross-business row could surface as this bill's reversal.
2. **SPA payload type accepted only `string` for `reference` while the view sends `null`.**
   `BillPaymentPayload.reference?: string | null` (ws22-bills-suppliers.ts:234-241) matches
   `BillDetailView.vue`'s `reference: payForm.value.reference.trim() || null`, so the fleet
   `tsc`/vue-tsc run cannot fail with TS2322 on that call.

## Checks re-run in this pass

1. **Routes → controller methods.** All 11 routes resolve via `php artisan route:list --json` to
   methods that exist with the right signatures (also confirmed by an autoload + `ReflectionClass`
   pass):
   `index(Request)`, `show(Request, Supplier|Bill)`, `store(Request)`, `update(Request, Supplier)`,
   `destroy(Request, Supplier)`; `index(Request)`, `options(Request)`, `store(Request, LedgerPostingService)`,
   `show(Request, Bill)`, `storePayment(Request, Bill, LedgerPostingService)`, `void(Request, Bill, LedgerPostingService)`.
   `{supplier}`/`{bill}` bind implicitly by id (no `getRouteKeyName` overrides).
2. **Envelope + tenant scoping.** Every public method returns `$this->ok(...)` / `$this->error(...)`
   (plus Laravel's validation/abort paths). All queries take `business_id` from
   `$this->user($request)`; `show/update/destroy/storePayment/void` call
   `authorizeSupplier()`/`authorizeBill()` (403 on a foreign business, matching the reference
   `WarehouseController`); every request-supplied id (`supplier_id`, `items.*.product_id`,
   `items.*.expense_account_id`, `payment_account_id`) carries a scoped
   `Rule::exists(...)->where('business_id', …)`, and the product is re-fetched scoped before
   costing. Mutations sit in `DB::transaction` — bill store, payment under `lockForUpdate`,
   void's reversal + status flip — and the supplier writes likewise.
3. **SPA calls ↔ routes ↔ api module.** 1:1 across the five views: suppliers
   `list/show/create/update/remove` → GET collection, GET/PUT/DELETE `…/{id}`, POST collection;
   bills `list/options/show/create/pay/void` → GET `/management/accounting/bills`,
   `/management/accounting/bill-options`, GET/POST `…/bills/{id}`-shaped URIs,
   POST `…/{id}/payments`, POST `…/{id}/void`. Response typings match the payloads the
   controllers actually return, and each view unwraps its own envelope correctly:
   suppliers list `{ data: { suppliers }, meta }`; bills list `{ data: { data, stats, suppliers, meta } }`.
4. **Route module shape / duplicate names.** `ws22-bills-suppliers.php` declares no management
   prefix, no auth/audience/team wrapper (inherited from
   `routes/api/v1/management.php`), only route-level `permission:accounting view` /
   `permission:accounting suppliers` / `permission:accounting bills` gates. The module loads
   after the shared file's `AccountingController` routes, so the two re-registered GETs replace
   those URIs (same technique as WS-16/WS-23; `route:list` shows one route per URI pointing at
   these controllers). A scan of all 1107 named routes in `route:list --json` found **zero
   duplicate names**.
5. **Router / nav modules.** `router/modules/ws22-bills-suppliers.ts` default-exports
   `RouteRecordRaw[]` with three child routes (`accounting/suppliers/:id`,
   `accounting/bills/create`, `accounting/bills/:id`), no leading slashes, each with
   `meta.title` (+ a real permission string); names are unique against
   `router/index.ts` and every other module. `nav/modules/ws22-bills-suppliers.ts`
   default-exports `NavGroup[]` (empty, documented) — AppLayout already renders the
   Suppliers/Bills entries under its Accounting group.
6. **Imports resolve.** PHP: classes, constants and methods used all exist (`Bill::STATUS_*`,
   `remainingBalanceKobo()/isFullyPaid()`, `BillPayment::paymentAccount()`,
   `Supplier::bills()/billPayments()`, `LedgerAccount::scopeActive/scopeOfType`,
   `JournalEntry::STATUS_POSTED/reversal_of_id`, `LedgerPostingService::postBill/postBillPayment/reverseEntry`,
   `InventoryCostingService::recordReceipt`, `LedgerSetupService::ensureForBusiness`). SPA: an
   import-resolution pass over the six SFCs and three TS modules confirms every `@/…` and
   relative import points at an existing file with the named exports; shared components used
   (`StatCard`, `AppModal`, `PaginationBar`, `BillStatusPill`, `@/stores/auth`,
   `@/stores/ui`, `@/nav/types`) expose compatible props/methods, and all `RouterLink`
   targets exist as routes.
7. **Syntax / toolchain (allowed subset).** `php -l` clean on both controllers, the route
   module and the test file; `php artisan route:list` boots with the expected URIs, names and
   middleware chains. Vue SFC `parse` + `compileScript` + `compileTemplate` clean on the six
   SFCs; TypeScript `transpileModule` clean on the three TS modules. `php artisan test` /
   `npm run typecheck` were deliberately not run (shared fleet DB/toolchain).

## Deliberate, not defects (unchanged from the first pass)

- The two GET list URIs are re-registered rather than added — forced by file ownership and the
  established WS-16/WS-23 pattern; verified replacement, not duplication: no test, SPA view or
  other app code references `api.management.accounting.bills`/`suppliers` route names, and the
  legacy Blade stack uses the separate `management.*` names.
- Bills list nests `meta` inside `data` while the suppliers list passes it top-level: each
  mirrors the sibling it was built from, and each SPA module unwraps its own shape.
- `accounting/bill-options` is a separate read endpoint rather than a re-registration of
  `accounting/accounts` (which would shadow WS-23's endpoint).
- No from/to date filter on the bills list, per `mgmt-accounting.verify.md` correction 4.
- `void()` uses `LedgerPostingService::reverseEntry`, which (per the verify doc's material
  correction) both posts the contra and flips the original entry to `void`.
- On `store`/`storePayment`, a ledger-posting failure is a `posting_warning` in the 201
  response rather than a rollback — legacy parity.

## Unfixable within this workstream's ownership

None.

## Test review (not executed)

The 11 tests were cross-checked statically against the controllers, services and seeded data:
kobo-exact totals (2.5×₦10.00 = 2500; 1.005×₦2.00 = 201; VAT 1050 → 3751) match
`toKobo`/`quantityToMilli`/`intdiv(+500)`; the AP posting lines match `LedgerPostingService::postBill`;
the per-business unique bill number matches `unique(['business_id','bill_number'])`; the
weighted-average 4×2500 + 4×3000 over 8 → 2750 matches `recordReceipt`; payment guards,
void refusal, status transitions and the reversal trace match the controller; `ws22*` helper
names are unique across the suite; the Owner (Super Admin) passes the gates while Store Manager
(no accounting permissions) 403s and Accountant (view+suppliers+bills) 200s, matching
`SpatiePermissionSeeder`.
