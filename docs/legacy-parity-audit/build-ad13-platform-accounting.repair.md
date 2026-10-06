# AD-13 — Platform accounting (WS13) — repair pass

**Verdict:** verified clean; no repairs required. Every route, controller
method, service call, SPA call, route name, import and envelope checks out
against the plan (`roadmap-admin.md` §16) and the legacy audit
(`admin-orders-finance.md` §5.1–5.6, `.verify.md` corrections). No file was
edited. One cosmetic observation is recorded for the orchestrator below; it is
not a defect.

## Files inspected (reported implementation)

API
- `app/Http/Controllers/Api/V1/Admin/AccountingController.php`
- `routes/api/v1/admin/ad13-platform-accounting.php`
- `tests/Feature/Api/ad13platformaccountingTest.php`

SPA (storify-admin)
- `src/api/modules/ad13-platform-accounting.ts`
- `src/router/modules/ad13-platform-accounting.ts`
- `src/nav/modules/ad13-platform-accounting.ts`
- `src/views/accounting/{AccountingDashboardView,AccountingAccountsView,AccountingJournalView,AccountingJournalEntryView,AccountingReportsView,AccountingSettingsView}.vue`

Read for dependency verification (read-only): `ApiController`,
`Admin/Concerns/EnsuresPlatformAdmin`, `App\Models\{JournalEntry,JournalLine,LedgerAccount,LedgerMapping,FiscalPeriod,FiscalYear,User}`,
`App\Services\Accounting\{LedgerSetupService,LedgerReportService,LedgerClosingService,LedgerPostingService,LedgerAccountTemplate}`,
`App\Services\ActivityLogger`, `SpatiePermissionSeeder`,
`app/Providers/AppServiceProvider` (superadmin `Gate::before`), the shared
route loaders `routes/api/v1/{admin,management}.php`, every sibling
`routes/api/v1/admin/*.php`, the SPA shells `src/router/index.ts`,
`src/layouts/AdminLayout.vue`, `src/api/client.ts`, `src/lib/runtimeConfig.ts`,
`src/lib/format.ts`, `src/stores/ui.ts` and the components each view imports
(`StatCard`, `EmptyState`, `TableSkeleton`, `TableFooter`, `ConfirmDialog`),
plus sibling test helpers (`ad12`, `ad07`) for idiom comparison.

## Checks run

- `php -l` on the controller, route module and test — clean.
- `php artisan route:list --path=api/v1/admin/accounting` — exactly the 11
  reported routes, names `api.admin.accounting.*`, each resolving to
  `Api\V1\Admin\AccountingController@{index,accounts,journal,journalExport,journalShow,reports,settings,updateMappings,closePeriod,reopenPeriod,closeYear}`.
  Verbose chain: `api → auth:sanctum → throttle:api → EnsureTokenAudience:admin
  → SetPermissionsTeamId → permission:admin.accounting`.
- Reflection over the controller: all 11 actions public, arity matches the
  route (`Request`; `{entry}`/`{period}` model-hinted, `{year}` string).
- App-wide `route:list --json` scan: **0 duplicate route names** and
  **0 duplicate method+domain+URI** pairs over all 1107 routes — the module's
  `api.admin.accounting.*` names do not collide with the management
  `api.management.accounting.*` names or the legacy web routes.
- Scripted resolution of every `use` statement in the three PHP files
  (`class_exists`/`trait_exists`): 33/33 resolve.
- SPA: scripted resolution of all 41 `@/` and relative imports across the 3 TS
  modules and 6 views — all land on existing files. `@vue/compiler-sfc`
  parse + `compileScript` + `compileTemplate` on all 6 SFCs, and
  `ts.transpileModule` on the 3 TS modules — all clean. App-wide scan of
  `src/router/modules/*.ts` + `router/index.ts` names: no duplicate of any
  `accounting*` name; no other module registers an `accounting*` path.
- Not run, per fleet rules: `php artisan test`, `npm run typecheck`.

## Fixes applied

None. Nothing found needed an in-place change.

## Verified as correct (no change needed)

**1. Routes → controller methods.** All 11 routes point at existing public
methods with matching signatures. `journal/export` is registered **before**
`journal/{entry}` so the CSV is never captured by the model binding. Implicit
bindings use the models' default `id` route key (no `getRouteKeyName`
override on `JournalEntry`/`FiscalPeriod`), and the SPA links `/accounting/journal/${entry.id}`.

**2. Envelope + platform scoping.** Every JSON method returns
`$this->ok($data, $message, $status, $meta)` or `$this->error(...)`
(`journalExport` returns a streamed CSV — the same non-envelope convention as
`Admin\ActivityLogController` and `Management\TransactionParityController`).
Every read/write is platform-scoped (`business_id` **null**): dashboard/chart/
journal queries carry `whereNull('business_id')`, `balancesByAccount` joins
`journal_entries` with `whereNull`, `journalShow` 404s any entry whose
`business_id !== null`, `closePeriod`/`reopenPeriod` 404 tenant periods via
`authorizePlatformPeriod`, `closeYear` looks the year up with
`whereNull('business_id')`, and `updateMappings` validates targets with
`Rule::exists('ledger_accounts','id')->whereNull('business_id')` and writes
`['business_id' => null, 'key' => …]`. The trait guard
(`EnsuresPlatformAdmin`) runs first in every method, so a business-scoped
"Super Admin" holding the leaked `admin.accounting` permission string is
refused with 403 (test covers this). Money is integer kobo throughout; the CSV
`naira()` helper uses `intdiv`/`%` (no float). Mapping writes run inside
`DB::transaction`, and the key set is validated against the template's 20
keys before any row is written.

**3. SPA calls ↔ routes ↔ module exports.** Each of the 11 client functions
(`dashboard`, `accounts`, `journal`, `journalEntry`, `downloadJournal`,
`reports`, `settings`, `updateMappings`, `closePeriod`, `reopenPeriod`,
`closeYear`) maps 1:1 onto a registered URI with the right verb, and every view
call site uses a function the module exports. Response shapes line up with the
module's types (`data.data`, `data.meta.totals`; kobo fields on both sides).
The client is a raw axios instance, so views correctly unwrap
`const { data } = await …; data.data` — the same idiom as
`AdminLayout.vue`/`SettingsVatView.vue`. The CSV rides the authenticated
client as a blob and is saved as `platform-journal.csv`, matching the
controller's stream name.

**4. Route module.** The file contains only `Route::` lines and one controller
import; its single group wrapper is the mandated `permission:admin.accounting`
gate (identical shape to `ad05-orders-oversight.php` and to the verified
management `ws37-accounting-settings.php`), not a prefix/auth/audience
wrapper — the `admin` prefix, `api.admin.` name and auth/audience/team
middleware are all inherited from `routes/api/v1/admin.php`'s glob loader.
`admin.accounting` exists in `SpatiePermissionSeeder`. No duplicate route
names anywhere in the app.

**5. Router / nav modules.** Router default-exports `RouteRecordRaw[]` of six
child paths with no leading slash, each with `meta.title`; names are unique.
Nav default-exports `Array<{ label, nodes }>` where each node satisfies
`AdminLayout.vue`'s `NavNode` shape (icon + permission + children leaves),
so the layout's `../nav/modules/*.ts` glob renders it filtered by
`auth.can('admin.accounting')`. No other nav module claims the "Finance"
label. The mount note in the header comment explains the intended merge.

**6. Imports.** All resolve (scripted, above). No `vendor`-named identifier
anywhere in the new code. No direct `fetch`/`axios` calls in the views — every
call goes through the module.

**7. PHP syntax.** `php -l` clean on all three files.

**Legacy parity spot-check.** Dashboard: five totals, last-10 recent entries,
cash & clearing panel, quick links, first-visit `ensureForBusiness(null)`
bootstrap. Chart: grouped by type, count per group, code/name/subtype/active,
bootstrap on read. Journal: 20/page, newest first, search over
entry/reference/memo, Entry/Date/Memo/Lines/Status, subscription empty-state
copy. Entry detail: lines + totals footer, Details card (date, status,
reference, fiscal period, posted by), non-platform 404. Reports: P&L defaults
year-to-date, Balance Sheet as-of with a "Current Period Earnings" row,
Trial Balance with totals — all via `LedgerReportService` scoped to
`business_id = null`. Settings: 20 mappings with inactive accounts still
selectable, fiscal years with close, 24 most recent periods with close/reopen
and confirm dialogs; year close delegates to `LedgerClosingService` (retained
earnings posting + period locking). The year-close route parses `{year}`
through `ctype_digit` → 404, so a non-numeric year cannot TypeError (the
defect class the ws37 repair found in the management sibling).

## For the orchestrator (non-defects, no action required)

- Unlike most admin modules, `ad13-platform-accounting.php` does not attach
  `AdminApiActivityLogger` to its route group; mutations audit themselves via
  `ActivityLogger::log` inside the controller and reads are logged through
  `Log::info`. `ad05` and `ad07` omit it the same way. If the orchestrator
  later wires the logger at the parent-group level, this module inherits it
  with no edit.
- The nav module ships its own "Finance" section, per its header comment; if a
  later workstream adds finance screens, merge the nodes rather than adding a
  second "Finance" header.
- `php artisan test` / `npm run typecheck` were intentionally not run (fleet
  rule — the orchestrator runs them serially). The test file
  `tests/Feature/Api/ad13platformaccountingTest.php` was reviewed, not
  executed: helper names are unique across `tests/`, the assertions match the
  controllers' status codes/payload paths/messages (including the exact year
  close message), and it covers scoping, refusals, validation and money maths.
