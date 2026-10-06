# WS-24 repair — Accounting Report Exports & Polish

**Verdict:** re-verified end to end. The route/controller/view spine the implementation reported is
real and passes every checklist item; three small defects were found and fixed in place this pass
(PDF money grouping, a string/number mismatch on the general-ledger account select, and the
integrity export filename). Nothing is blocked on another agent's files.

## Files verified (read in full this pass)

| Piece | Path |
| --- | --- |
| API controller | `app/Http/Controllers/Api/V1/Management/Accounting/ReportsController.php` |
| Route module | `routes/api/v1/management/ws24-accounting-reports.php` |
| PDF templates | `resources/views/pdf/accounting/{trial-balance,profit-and-loss,balance-sheet}.blade.php` |
| API test | `tests/Feature/Api/ws24accountingreportsTest.php` (8 tests) |
| Reference (read-only) | `app/Http/Controllers/Management/Accounting/AccountingReportController.php` (legacy), `app/Services/Accounting/LedgerReportService.php`, `AccountingController.php`, `routes/api/v1/management.php`, `ws23-coa-journal.php`, `ChartOfAccountsController.php`, `SpatiePermissionSeeder.php`, models `Invoice`/`Bill`/`Store`/`Expense`/`LedgerAccount` |
| SPA view | `storify-management/src/views/accounting/ReportsView.vue` |
| SPA API module | `storify-management/src/api/modules/ws24-accounting-reports.ts` |
| SPA router/nav modules | `storify-management/src/router/modules/ws24-accounting-reports.ts`, `src/nav/modules/ws24-accounting-reports.ts` |

## Repairs made this pass

1. **PDF money grouping restored to legacy parity** (`ReportsController.php`). The legacy PDFs
   printed `number_format($kobo / 100, 2)`, i.e. `1,234.56`; the port used the separator-free CSV
   helper (`1234.56`). Added `amountGrouped()` — thousands grouping by string maths only, kobo never
   touch a float — and pointed the three PDF branches (`profit-and-loss`, `balance-sheet`,
   `trial-balance`) at it. The CSV branches still use `amount()` with the explicit
   `fputcsv(..., ',', '"', '')` path, so accountants' templates keep opening unchanged.
   Output checked against `number_format`: `123456 → 1,234.56`, `-123456 → -1,234.56`,
   `123456789 → 1,234,567.89`, `0 → 0.00`, `5 → 0.05`.
2. **General-ledger account select no longer stringifies the id**
   (`ReportsView.vue`). `params.accountId = String(data.data.accounts[0]?.id ?? '')` never matched
   the numeric `<option :value="account.id">` bindings, so the select rendered blank while the
   report used account 1. The model is kept numeric (`... ?? ''`); the reactive type already allowed
   `string | number`.
3. **Integrity CSV download keeps its legacy filename**
   (`ws24-accounting-reports.ts`). The server streams `ledger-integrity-<date>.csv` (legacy name) but
   the client's `link.download` overrode it with `integrity-<date>.csv`. The client now maps
   `integrity → ledger-integrity`, matching the module's documented "filenames match the legacy
   downloads" contract.

## Checklist

1. **Routes → controller methods.** All nine routes point at an existing public method with the
   right signature — `profitAndLoss`, `balanceSheet`, `trialBalance`, `generalLedger(Request,
   LedgerAccount)`, `arAging`, `apAging`, `vatSummary`, `expenseSummary`, `integrity`.
   `php artisan route:list --path=api/v1/management/accounting/reports --json` returns exactly
   **9 routes / 9 unique names**, every action
   `App\Http\Controllers\Api\V1\Management\Accounting\ReportsController@*`, each carrying
   `auth:sanctum`, `token.audience:management`, `team.context` once, and `permission:accounting
   reports` once. The shared route file's same-URI registrations against the old
   `Management\AccountingController` are replaced (feature modules load after it, Laravel keys by
   method+URI), so no orphaned action or stale name survives.
2. **Envelope + tenant scoping.** Every JSON branch returns `$this->ok($report)` or the mapped
   array — identical field-for-field to the previous `AccountingController` payloads (verified by
   comparing both files). The CSV/PDF branches return the file responses the deliverables require.
   Tenancy: every branch derives `business_id` from `$this->user($request)`; `general-ledger/{account}`
   re-checks `$account->business_id` and `abort(403)`s; every service query filters on the business
   (`journal_entries.business_id` for TB/P&L/BS/GL/VAT/integrity, `invoices`/`bills`/`expenses`/`stores`/
   `transactions` likewise). `dateParam()` turns malformed dates into 422 instead of the legacy 500 —
   pinned by the test.
3. **SPA calls ↔ routes ↔ api module.** The view imports only
   `@/api/modules/ws24-accounting-reports`, whose exported members cover every call the view makes
   (`accounts`, the nine report getters, `download`). URIs: nine
   `/management/accounting/reports/*` routes + `/management/accounting/accounts` (re-registered by
   WS-23, returns `{accounts:[{id,code,name,type,is_active,balance_kobo}]}` — the picked fields exist).
   `download()` adds `?export=csv|pdf` to the same URIs; PDF is offered only for the three reports
   that support it (`PDF_REPORTS`), and `general-ledger` requires an account before JSON or export.
4. **Route module shape / duplicate names.** The file contains only `Route::` lines: no `management`
   prefix, no auth/audience wrapper — just the route-level `permission:accounting reports` gate
   sub-grouped under `prefix('accounting/reports')->name('accounting.reports.')`, exactly the
   pattern WS-23/WS-16 use. A full-application `route:list --json` scan found **0 duplicate names
   across 1107 routes**. The nine names intentionally repeat the shared file's names on the same
   method+URI (that is what makes the override work); each survives once in the collection. Grep of
   every other management/admin module found no other registration of these URIs or names.
5. **Router / nav module exports.** `router/modules/ws24-accounting-reports.ts` default-exports
   `RouteRecordRaw[]` and `nav/modules/ws24-accounting-reports.ts` default-exports `NavGroup[]`
   (importing the type from `@/nav/types`); both are empty by design because the shared router
   already carries `/accounting/reports` → this view (`router/index.ts:86`) and AppLayout already
   renders the Reports item gated on `accounting reports` (`AppLayout.vue:92`). The globs
   (`./modules/*.ts` eager, `../nav/modules/*.ts` eager) accept the empty defaults. Adding records
   would duplicate the shared entries.
6. **Imports resolve.** PHP: every import exists (`ApiController`, `ResolvesManagementContext`,
   `LedgerAccount`, `LedgerReportService`, `Barryvdh\DomPDF\Facade\Pdf` — `class_exists()` true —
   `JsonResponse`, `Response`, `StreamedResponse`, `Carbon`), and DomPDF's `download()` was read in
   vendor: it returns `Illuminate\Http\Response` with `%PDF` content and `application/pdf`, which is
   what the test asserts. The three blades compile via `BladeCompiler::compileString` and are
   byte-identical to the legacy templates apart from the amount helper (diff-normalised). SPA:
   `@/api/client`, `@/stores/ui`, the ws24 module and `@/nav/types` all resolve; the view compiles
   clean with `@vue/compiler-sfc` (script + template + styles) and the TS modules transpile clean.
7. **PHP syntax.** `php -l` passes on the controller, route module, all three blades and the test.

## Also verified (test viability, since the orchestrator runs the suite)

- Test helpers used (`createBusinessOwner`, `setPermissionsTeamId`, `ws24Token`'s three-arg
  `createToken`) match the patterns of the sibling `ws04`/`ws18`/`ws25` tests; the file ends
  `...Test.php` so PHPUnit's default suffix picks it up; function names are `ws24`-prefixed.
- Ledger template codes the tests reference exist with the exact names asserted (`1010 Cash on
  Hand`, `4010 Sales Revenue`, `5200 Rent`, `1100`, `2100`); `LedgerPostingService::post` accepts
  `account_id`/`tax_kobo`/`date`; `Expense` groups under `Rent` via `ledgerAccount` when no category.
- The expected CSV bytes were reproduced against the real helper: header quoting, en-dash bucket
  labels, `"Days Past Due"` quoting, `1234.56`/`0.00` amounts, `40`/`10` day counts.
- `Auditor` holds `accounting reports`; `Warehouse Manager` does not (403 assertions valid);
  `Barryvdh\DomPDF` is on the composer classpath; `Invoice` auto-numbers on `creating`, and the
  `Invoice`/`Bill`/`Store`/`Expense` fillables cover every field the test passes.

## Notes / not defects

- Legacy "print styling" (audit 8.11) stays out of scope: the WS-24 roadmap deliverables are 9 CSV
  + 3 PDF exports, and the implementation's note about it is accurate.
- `?export=pdf` on the other six reports deliberately falls through to JSON (roadmap: PDF for TB,
  P&L, BS only).
- The shared SPA route record for `/accounting/reports` has no `meta.title`/permission, but the
  router guard does not consume `meta.permission` anywhere in this app (nav gating + server-side 403
  are the enforcement), and the file is owned by another agent — not a WS-24 gap.

## Verification commands run

```
php -l <controller / route module / 3 blades / test>            all clean
php artisan route:list --path=api/v1/management/accounting/reports --json
    -> 9 routes, 9 unique names, all ReportsController@*
php artisan route:list --json | duplicate-name scan             -> 0 duplicate names / 1107 routes
BladeCompiler::compileString on the three PDF templates         -> OK
viewer: @vue/compiler-sfc parse+compile ReportsView.vue         -> OK
TypeScript transpile of the three ws24 TS modules               -> OK
```

`php artisan test` and `npm run typecheck` were **not** run, per the fleet rule; the orchestrator
runs them serially.
