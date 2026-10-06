# WS-23 repair — Chart of Accounts & Journal Workflows

**Verdict:** re-verified end to end after the recharge. No new defects were found and no file was
edited this pass. The single fix from the earlier pass — `JournalController@index` returning the
house envelope with the filtered totals in `meta.totals` instead of a hand-rolled `response()->json`
with a top-level sibling — is present in the tree, coherent across the controller, the API module
types, the SPA reader and the two test assertions, and is the same shape the admin journal endpoint
(AD-13) already uses. Everything the repair brief asks for passes; the only outstanding item is the
orchestrator-owned mount of the dashboard panels.

## Files re-verified (read in full this pass)

| Piece | Path |
| --- | --- |
| API controllers | `app/Http/Controllers/Api/V1/Management/Accounting/ChartOfAccountsController.php`, `JournalController.php`, `AccountingDashboardController.php` |
| Route module | `routes/api/v1/management/ws23-coa-journal.php` |
| API test | `tests/Feature/Api/ws23coajournalTest.php` (13 tests) |
| SPA views | `storify-management/src/views/accounting/AccountsView.vue`, `JournalView.vue`, `JournalEntryFormView.vue` |
| SPA component | `storify-management/src/components/accounting/AccountingDashboardPanels.vue` |
| SPA API module | `storify-management/src/api/modules/ws23-coa-journal.ts` |
| SPA router module | `storify-management/src/router/modules/ws23-coa-journal.ts` |
| SPA nav module | `storify-management/src/nav/modules/ws23-coa-journal.ts` |

## Checklist

1. **Routes → controller methods.** All 11 routes resolve to existing public methods with the
   expected signatures, confirmed both by reading the controllers and by
   `php artisan route:list --path=api/v1/management/accounting -v`: the four re-registered URIs
   (`dashboard`, `accounts`, `journal`, `journal/{entry}`) and the seven new ones all point at the
   WS-23 controllers, not the shared `AccountingController`. Implicit binding matches the
   parameters (`{account}` → `LedgerAccount`, `{entry}` → `JournalEntry`); a reflection check of
   every class imported by the route module and controllers resolves (no missing class, all
   methods public). The earlier envelope fix is in place: `index()` returns
   `$this->ok($rows, null, 200, $this->paginationMeta($entries) + ['totals' => ...])`
   (`JournalController.php:92–105`), and the test reads `meta.totals`
   (`ws23coajournalTest.php:253–254,460`) exactly as `JournalView.vue:56` does.
2. **Envelope + tenant scoping.** Every action on the three controllers ends in `$this->ok(...)`
   or `$this->error(...)`. Tenancy: accounts list/create scope on `user->business_id`;
   update/toggle re-check `account->business_id` and `abort(403)`; journal list scopes, and
   show/post/delete/reverse re-check the entry's business with `abort(403)`;
   `normalisedLines()` rejects accounts outside the business; the dashboard scopes every query
   (balances, recent entries, unposted payments) and `reverseEntry` voids the original. Multi-row
   mutations run in transactions (draft create + lines, draft delete; posting/reversal go through
   `LedgerPostingService`, which wraps its own transaction).
3. **SPA calls ↔ routes ↔ api module.** Every call in the four SFCs maps 1:1 to a registered URI
   and an exported member of `coaJournalApi` (dashboard, accounts, create/update/toggleAccount,
   journal, journalShow, create/post/delete/reverseJournal). Payload and response fields match the
   controllers key-by-key (`parent`, `balance_kobo`, `fiscal_period`, `posted_by/at`, `reversal_of`,
   `reversed_by`, `is_balanced`, `cash_balances`, `recent_entries`, `unposted_payments`,
   `meta.totals`). The shared `accountingApi.accounts/journal/dashboard` consumers are unaffected:
   the new payloads are strict supersets (`ws24-accounting-reports.ts` reads
   `data.accounts[].{id,code,name,type,is_active}`; `AccountingDashboardView.vue` reads the same six
   totals keys; `endpoints.ts` types `journal` as `Paginated<any>` over the flat `data` array).
4. **Route module shape / duplicate names.** No `management` prefix or auth wrapper — only the
   route-level `permission:accounting view|accounts|journal` gates, sub-grouped under
   `prefix('accounting')->name('accounting.')` exactly like ws16/ws22. The four same-name
   registrations are deliberate same-URI replacements of the shared routes
   (`routes/api/v1/management.php:162–171`); a `route:list --json` scan of all 1048 named routes
   found **zero duplicate names** and no duplicate method+URI pair involving accounting, and a grep
   of every other management/admin module file found no other registration of these URIs or names.
   Permission strings mirror the legacy route file (`routes/v1/management.php` accounting block)
   verbatim.
5. **Router / nav modules.** The router module default-exports a `RouteRecordRaw[]` with one child
   route (`accounting/journal/create`, no leading slash, `meta: { title, permission }`) whose name
   `accounting.journal.create` is unique across the SPA and whose component exists. The nav module
   default-exports `NavGroup[]` (empty, documented: `AppLayout.vue:86–92` already renders the
   Accounting links this workstream deepens, so adding a group would duplicate them; the layout's
   `flatMap(module.default ?? [])` merge tolerates the empty array).
6. **Imports resolve.** PHP: every `use` in the five files resolves (checked with the autoloader);
   `LedgerPostingService::post/reverseEntry`, `LedgerSetupService::ensureForBusiness/resolveOpenPeriod`,
   `LedgerReportService::balanceSheet/profitAndLoss`, `JournalEntry` constants/relations and
   `TransactionStatus::CONFIRMED/PAID` all exist and the schema columns the writes touch exist in
   `2026_09_12_000003_create_ledger_core_tables.php`. SPA: all 22 import specifiers in the seven
   files resolve to real files (`@/api/client` exports `api` and `apiErrorMessage`; `AppModal`,
   `StatusBadge`, `PaginationBar` exist with the props used; `useAuthStore.can`, `useUiStore`
   success/error exist; `@/nav/types` exists). Every `RouterLink`/`router.push` target
   (`/accounting/journal`, `/accounting/journal/create`, `/accounting/accounts`,
   `/accounting/expenses/create`, `/accounting/bills`, `/accounting/reports`) is a registered route
   or a documented deep-link owned by another workstream.
7. **PHP syntax.** `php -l` on the three controllers, the route module and the test — clean;
   `vendor/bin/pint --test` on the same five — `{"result":"passed"}`.

## Carried-over fix (present in the tree, no re-edit needed)

- **Journal list uses the house envelope.** `JournalController@index` returns
  `$this->ok($rows, null, 200, paginationMeta + ['totals' => ['debits' => ..., 'credits' => ...]])`.
  `data` stays the flat row array the re-registered shared endpoint promised (the shared
  `Paginated<any>` consumer in `endpoints.ts` keeps working), and the filtered totals ride in `meta`
  — the placement AD-13's admin journal endpoint already uses. `ws23-coa-journal.ts:114` types
  `meta: PaginationMeta & { totals }`, `JournalView.vue:56` reads `data.meta?.totals`, and the two
  test assertions read `meta.totals` — all four sites were verified consistent this pass.

## Unfixable within this workstream's ownership

- **`AccountingDashboardPanels.vue` is still not mounted.** The component carries the required
  top-of-file mount note: mount `<AccountingDashboardPanels />` in
  `storify-management/src/views/accounting/AccountingDashboardView.vue` directly below the six stat
  cards (inside `<template v-else-if="stats">`). That view is an existing view this workstream does
  not own (the brief's file list does not name it among WS-23's files), so the wiring is left to the
  orchestrator, matching the `SubscriptionBanner` convention. Until mounted, the cash/bank panel,
  recent entries, unposted-payments warning and quick actions are delivered by the API and the
  component but not rendered.

## Notes (no change made)

- The re-registered URIs and reused route names are the intended technique (ws16/ws22 do the same);
  `route:list` proves exactly one live route per URI and it belongs to WS-23.
- Additive-but-harmless extras versus the audit's "required" list: the `reversed_by` forward link
  on journal detail (the verify said it was not required — it is additive and the SPA uses it), the
  optional `subtype` input in the account modal (verify 7b asked to keep the column, not the input),
  a few extra permission-gated quick actions, and the `void` status filter (verify 1: void rows are
  live because `reverseEntry`/void flows write them).
- Test consistency was re-checked by reading, not running (fleet forbids `php artisan test`):
  Manager's seeded accounting permissions are `view/expenses/reports` so the 403 write assertions
  hold; `LedgerAccountTemplate` has the `1010/1100/4010/5200` codes and `cash/sales_income/`
  `accounts_receivable` keys the tests use; `LedgerSetupService::ensureForBusiness` seeds the current
  fiscal year/period that the closed-period and `fiscal_period` assertions rely on;
  `orders.customer_id` is nullable and `business_id/user_id` are nullable, so the `Order::create`
  fixtures match the ws13 idiom; `reverseEntry` really voids the original.

## Verification performed

- `php -l` on the three controllers, the route module and the test — clean.
- `vendor/bin/pint --test` on the same five files — `{"result":"passed"}`.
- `php artisan route:list --path=api/v1/management/accounting -v` — all 11 WS-23 routes registered
  with the inherited middleware chain (`auth:sanctum → token.audience:management → team.context →
  permission:accounting view|accounts|journal`) and each pointing at the WS-23 controller.
- `php artisan route:list --json` — 1048 named routes, zero duplicate names and zero duplicate
  method+URI pairs involving accounting; the three global method+URI repeats (`/`, `services`,
  `support`) are pre-existing web/staff/api namespace overlaps unrelated to WS-23.
- Autoloader reflection over every imported PHP class and every route-module controller method.
- `@vue/compiler-sfc` parse + `compileScript` + `compileTemplate` on the four SFCs, plus a
  resolution walk of all 22 import specifiers — clean. (`npm run typecheck` and `php artisan test`
  were not run, per the brief.)
