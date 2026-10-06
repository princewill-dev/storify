# WS-37 — Accounting Settings, Closing & Reconciliation — repair pass

**Status:** verified; three small in-place repairs applied to workstream-owned
files. Routing, tenant scoping, envelopes and the SPA wiring are all correct
as reported.

**Scope:** the files reported by the WS-37 implementation — API
`Api\V1\Management\Accounting\{ReconciliationController,AccountingSettingsController}`
and `routes/api/v1/management/ws37-accounting-settings.php`; the feature test
`tests/Feature/Api/ws37accountingsettingsTest.php`; SPA
`src/api/modules/ws37-accounting-settings.ts`,
`src/router/modules/ws37-accounting-settings.ts`,
`src/nav/modules/ws37-accounting-settings.ts`,
`src/views/accounting/{ReconciliationView,ReconciliationDetailView,AccountingSettingsView}.vue`.
Read for dependency verification: `App\Http\Controllers\Api\V1\ApiController`,
`Management\Concerns\ResolvesManagementContext`, `App\Models\{BankReconciliation,BankStatementImport,BankStatementLine,StoreBank,FiscalPeriod,FiscalYear,JournalEntry,JournalLine,LedgerAccount,LedgerMapping}`,
the `ledger_core_tables` / `bank_reconciliation_tables` migrations,
`App\Services\Accounting\{LedgerSetupService,LedgerPostingService,LedgerClosingService,LedgerAccountTemplate}`,
`SpatiePermissionSeeder` (permission strings + role map), the shared route
loader `routes/api/v1/management.php`, every sibling
`routes/api/v1/{management,admin}/*.php`, and the SPA shells
(`router/index.ts`, `AppLayout.vue`, `@/nav/types`, `@/api/client`,
`@/components/*` used by the views).

`php artisan test` and `npm run typecheck` were **not** run (the fleet shares
one test database and one toolchain — the orchestrator runs them serially).
Executed instead: `php -l` on all four PHP files, `vendor/bin/pint --test`,
`php artisan route:list` (filtered, plus a scripted route-action reflection),
an app-wide duplicate route-name scan, an app-wide controller-method
existence check, scripted resolution of every `use` statement, `php -l` +
`@vue/compiler-sfc` parse/`compileScript`/`compileTemplate` on all three SFCs,
`ts.transpileModule` plus a scoped `ts.createProgram` on the three TS modules,
scripted resolution of every `@/` SPA import, and a read-only scan of the
shared files for WS-37 references.

## Verification results

| Check | Result |
| --- | --- |
| Route → controller method exists, right signature | PASS — all 14 routes register under `api/v1/management/accounting/...` (`route:list --path=api/v1/management/accounting`), each action resolves via reflection to an existing public method: `ReconciliationController::{index,store,show,autoMatch,complete,match,unmatch,ignore}` and `AccountingSettingsController::{index,updateMappings,storeOpeningBalances,closePeriod,reopenPeriod,closeYear}`. Route names match the reported list exactly (`api.management.accounting.reconciliation.*`, `api.management.accounting.settings.*`); `{import}`/`{line}`/`{period}` bind `BankStatementImport`/`BankStatementLine`/`FiscalPeriod`, `{year}` is an int. |
| House envelope + tenant scoping | PASS — every method returns `$this->ok($data, $message, $status, $meta)` / `$this->error(...)`; the only non-envelope exits are `$request->validate()` (standard 422) and `abort(403/422)` in the guard helpers. Every read filters `business_id`; `store`/`updateMappings`/`storeOpeningBalances` validate foreign ids with `Rule::exists(...)->where('business_id', $businessId)`; `show`/`autoMatch`/`complete` re-check `import->business_id`, `match`/`unmatch`/`ignore` re-check `line->business_id` **and** the journal line's `journal_entries.business_id`, `closePeriod`/`reopenPeriod` re-check `period->business_id`, `closeYear` looks the year up by `business_id`. Multi-row mutations (`store`, `autoMatch`, `complete`, `updateMappings`, opening-balance posting via `LedgerPostingService::post`) sit in `DB::transaction`. Money is integer kobo end to end — no float arithmetic anywhere in the API (`parseKobo` does string maths). `permission:accounting view\|settings\|close\|reconcile` all exist in `SpatiePermissionSeeder`, and the Accountant/Cashier/owner role map backs the permission tests. |
| SPA calls ↔ routes ↔ api module | PASS — the three views call 14 client functions, all exported by `src/api/modules/ws37-accounting-settings.ts`, all matching a registered URI 1:1 (`/management/accounting/settings[/mappings|/opening-balances|/periods/{id}/close|/reopen|/years/{name}/close]`, `/management/accounting/reconciliation[/{id}|/auto-match|/complete|/lines/{id}/match|unmatch|ignore]`). Response shapes line up with the `AccountingSettingsPayload` / `ReconciliationIndexPayload` / `ReconciliationDetailPayload` types (all kobo fields on both sides). The multipart import sends `FormData` with the exact field names the controller validates. |
| Route module wrapper / duplicate names | PASS — the module adds only its four `Route::middleware('permission:...')->prefix(...)->name(...)->group(...)` blocks; no `auth:sanctum` / `token.audience` / `team.context` / `management` wrapper (all inherited from `routes/api/v1/management.php`'s glob loader). App-wide scripted scan of every named route: **0 duplicate names**; no sibling management or admin module registers these URIs or names (admin's `ad13` uses the `api.admin.` prefix; legacy `routes/v1/*` is not loaded by `bootstrap/app.php`). Route order inside the reconciliation group is not shadowed (`POST lines/{line}/...` is three segments; `POST {import}/complete` is two). |
| Router/nav module shapes | PASS — router default-exports `RouteRecordRaw[]` with three child routes (`accounting/reconciliation`, `accounting/reconciliation/:id`, `accounting/settings` — no leading slash), unique names across all `src/router/modules/*.ts` and the shared `router/index.ts`, each with `meta.title` (+ `meta.permission`). Nav default-exports `NavGroup[]` matching `@/nav/types` (`{label, icon, items[]}`); `to` paths point at the two new SPA routes and the permission strings are the seeded legacy gates. AppLayout's `../nav/modules/*.ts` glob merges it; the top-of-file MOUNT NOTE correctly asks the orchestrator to coalesce the new items into the existing inline "Accounting" group (the same cross-cutting idiom used by WS-15/21/26 etc.). |
| Imports resolve | PASS — scripted resolution of every `@/` import in the five SPA files lands on an existing file; every `use` statement in the four PHP files resolves (`class_exists`/`interface_exists`/`trait_exists`); no `vendor`-named identifiers anywhere. |
| PHP syntax / Pint / SFC / TS | PASS — `php -l` clean on all four PHP files; `vendor/bin/pint --test` passed after the edits; all three SFCs parse and compile script+template; the three TS modules transpile clean and pass a scoped `ts.createProgram` (only artifacts: the three `.vue` module resolutions that `vue-tsc` — not raw `tsc` — handles). |
| File ownership | PASS — no shared file carries a WS-37 reference (scanned `routes/api.php`, `routes/api/v1/{management,admin}.php`, SPA `router/index.ts`, `AppLayout.vue`, `api/endpoints.ts`, `api/client.ts`); all wiring is via the established globs. |
| Test file | PASS (reviewed, deliberately unexecuted) — 23 Pest tests cover mappings validation, one-time opening balances, closed-period refusal, year-end close math, permission walls, all four parser shapes (named headers, naira/comma amounts, debit/credit columns, headerless), tenant guards on imports/lines/periods, auto-match window + one-to-one claiming, and completion freezing. Assertions match the controllers' status codes and messages; helper names are unique across `tests/` (no redeclaration with other test files). |

## Fixes applied

1. `ReconciliationController::clean()` now takes a `$max` length and the
   statement parser clamps `reference` to **100** characters — the
   `bank_statement_lines.reference` column is `varchar(100)` while the helper
   truncated everything at 255, so one long bank narration (common on
   Nigerian export formats) would have aborted the whole import on insert
   with "Data too long". Description still truncates at the column's 255.
2. `ReconciliationController::parseKobo()` — the replacement list's second
   whitespace entry was a literal duplicate of a plain space (the intended
   U+00A0 had not survived into the file). Replaced with `"\u{A0}"`, so
   Excel/bank exports that group thousands with a non-breaking space parse
   instead of having the row silently dropped.
3. `routes/api/v1/management/ws37-accounting-settings.php` — the year-close
   route gained `->whereNumber('year')`. Without it a request to
   `/years/abc/close` reached `closeYear(Request, int $year)` and threw an
   uncaught TypeError (500); it now 404s like the other bad-id cases.

## Unfixable / notes for the orchestrator

- None. Nothing required touching a file this workstream does not own.
- Not a defect, wiring left to the orchestrator by design: the nav module's
  two entries must be coalesced into AppLayout's existing inline "Accounting"
  group (the module's header comment spells this out). Until then the glob
  merge would render a second "Accounting" header.
- `php artisan test` / `npm run typecheck` intentionally not run (fleet
  rule); the report above states exactly what was executed instead.
