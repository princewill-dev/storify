# WS-18 — Transactions Parity & Payment Emails — repair report (recharge pass)

Status: **repaired in place**. Two further defects fixed on this pass; the
earlier `filterStores` scoping fix is still present and re-verified. Routes,
envelopes, tenant scoping, mailables, SPA calls, router/nav shapes, imports and
PHP syntax all re-checked against the files.

## Files owned and verified

- `app/Http/Controllers/Api/V1/Management/TransactionParityController.php`
- `routes/api/v1/management/ws18-transactions.php`
- `tests/Feature/Api/ws18transactionsTest.php`
- `storify-management/src/api/modules/ws18-transactions.ts`
- `storify-management/src/router/modules/ws18-transactions.ts`
- `storify-management/src/nav/modules/ws18-transactions.ts`
- `storify-management/src/views/TransactionsView.vue`
- `storify-management/src/views/TransactionDetailView.vue`
- `storify-management/src/components/PendingTransactionsBadge.vue`

## Repairs applied this pass

1. **Walk-in placeholder emails leaked in the customer block (API bug).**
   `customerContext()` returned `$customer?->email` even for POS walk-in
   accounts (`walkin@pos.local`, `pos-…@walkin.local`). Legacy never rendered
   that address — it fell back to the name/phone captured on the order — and
   the verified WS-13 sibling (`OrderParityController::customer()`) hides it
   the same way. The method now uses the sibling's exact predicate
   (`walkin@pos.local` / `@walkin.local`) and returns `email: null` with
   `is_walk_in: true` for both walk-in branches. The no-customer, no-meta case
   still returns `null` (the list test asserts `customer: null` there), and a
   real customer with no email is still a real block — so the SPA type
   (`email: string | null`) and every existing assertion are unchanged.

2. **Sidebar badge double-render hazard (integration bug).**
   WS-34 ships `ShellCountsBadge` whose mount list already includes
   `metric="transactions_pending"` for `/transactions`, fed by
   `/management/shell/counts` — and `ShellCountsController` computes it with
   "WS-18's definition". WS-18's `PendingTransactionsBadge.vue` targets the
   same nav item, so honouring both mount comments would render two amber
   badges on one row. The badge header and the nav-module WIRING comment now
   state that WS-34's badge is the canonical renderer and the WS-18 component
   must not be mounted beside it; it stays in the tree as the standalone
   fallback for the dedicated `/management/transactions/pending/count`
   endpoint (which remains live and tested).

Still in place from the earlier pass: `filterStores()` scopes the store
filter options through the same `staffStoreIds()` definition as the rows, so a
store-assigned staff member's dropdown can no longer offer stores the list
will never return (regression assertion kept in the staff test).

## Verification evidence

- **Routes / methods:** `php artisan route:list --path=api/v1/management/transactions`
  shows exactly 7 routes, all pointing at `TransactionParityController`
  (`index`, `export`, `pendingCount`, `show`, `confirm`, `refund`, `reject`),
  each public method signature matching its route shape (`Request`, or
  `Request + Transaction`), with inherited auth/audience/team middleware and
  the right `transactions view|export|confirm|reject|refund` gates
  (`export` carries both the view and export permissions).
- **Replacement of the thin base registrations:** router `match()` on the
  built routes resolves `…/transactions/export` → `@export`,
  `…/transactions/pending/count` → `@pendingCount`, `…/TXN-ABC123` → `@show`
  (negative lookahead on the binding keeps the static sibling reachable).
  `route:list --json` over all 1107 routes reports **zero duplicate names**;
  the 7 `api.management.transactions.*` names are unique and no other
  management module registers a `transactions` URI or name.
- **Envelopes + tenant scoping:** every JSON action returns
  `$this->ok(...)` / `$this->error(...)`; `export` streams CSV via
  `response()->streamDownload` (the established house export idiom — 3 other
  API controllers use it). Reads are `where('business_id', …)`;
  `authorizeTransaction()` enforces business + assigned-store access on
  show/confirm/reject/refund before any status check, and the `store_id`
  filter runs inside the scoped query.
- **Mailables:** `PaymentConfirmedMail(Transaction, Order, $recipient, Store)`,
  `PaymentRejectedMail(…, ?string $reason)`, `RefundProcessedMail(…, Customer,
  Store, string $reason)` all match the call sites; confirm/reject queue to
  customer + owner + platform admins (superadmin emails with
  `mail.admin_email` fallback, deduped by address), refund to the customer
  only, invoice rejections short-circuit exactly like legacy. The three
  blade views exist and render a null admin recipient safely
  (`$recipient?->name ?? $recipient->first_name ?? 'Admin'` yields "Admin").
- **Cross-workstream compatibility:** WS-27's `ws27-store-tabs.ts` imports
  `transactionsParityApi`/`TransactionRow` (both exported) and its
  `StoreTransactionsTab.vue` reads `data.data.transactions`/`data.data.statuses`
  — the parity shape. `ws27storetabsTest` (store-filtered list), the
  `ws12orderfulfilmentTest` refund and `ManagementModulesApiTest`
  confirm/refund all exercise the replaced routes and remain compatible
  (shapes `data.transactions` / `data.transaction`, refund reason required,
  returned-order refunds allowed).
- **SPA:** every call in the two views (`index`, `show`, `confirm`, `reject`,
  `refund`, `pendingCount`, `exportCsv`) maps to one of the 7 registered
  routes and to an exported function of `ws18-transactions.ts`; all `@/`
  imports resolve (`api/client` with `api`/`apiErrorMessage`, `stores/auth`
  with `can()`, `stores/ui`, `StatusBadge`, `PaginationBar`, `AppModal` with
  its `maxWidth` prop). Router module default-exports `RouteRecordRaw[]`
  (child path `transactions/:reference`, no leading slash, `meta.title`,
  unique `transactions.show`); nav module default-exports `NavGroup[]`
  (empty by design — the shell's Sales → Transactions entry already exists).
- **Syntax:** `php -l` passes on all three PHP files.
- **Conventions:** no `vendor`-named identifiers in any WS-18 file; walk-in
  detection matches the `@walkin.local`/`walkin@pos.local` convention used by
  POS and checkout.

## Wiring the orchestrator must do (cannot be edited from an owned file)

- `src/layouts/AppLayout.vue` (shell workstream) owns the badge mount. Mount
  **WS-34's** `ShellCountsBadge` (`metric="transactions_pending"`,
  `tone="amber"`, `permission="transactions view"`) on the Sales →
  Transactions item — it already renders this metric from
  `/management/shell/counts`. Do **not** also mount
  `PendingTransactionsBadge.vue` on the same item.
- `src/router/index.ts` (shared) already declares `/transactions` →
  `TransactionsView.vue`, so no edit is required there.

Per fleet instructions, `php artisan test` and `npm run typecheck` were not
run (shared serial execution belongs to the orchestrator).
