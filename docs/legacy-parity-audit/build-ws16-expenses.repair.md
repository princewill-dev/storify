# WS-16 repair — Accounting: Expenses

**Verdict:** repaired in place. One real deviation was found and fixed (the list endpoint hand-rolled
its response instead of using the house envelope helper); every other check passes. This supersedes
the earlier pass, which recorded the list response as an intentional deviation on the strength of a
claim about WS-23 that is not true (see the fix below).

## Files

| Piece | Path |
| --- | --- |
| API controller | `/Users/mac/Desktop/my_files/work/storify/storify-api/app/Http/Controllers/Api/V1/Management/Accounting/ExpenseController.php` |
| Route module | `/Users/mac/Desktop/my_files/work/storify/storify-api/routes/api/v1/management/ws16-expenses.php` |
| API test | `/Users/mac/Desktop/my_files/work/storify/storify-api/tests/Feature/Api/ws16expensesTest.php` (7 tests) |
| SPA views | `/Users/mac/Desktop/my_files/work/storify/storify-management/src/views/accounting/ExpensesView.vue`, `ExpenseFormView.vue`, `ExpenseDetailView.vue` |
| SPA API module | `/Users/mac/Desktop/my_files/work/storify/storify-management/src/api/modules/ws16-expenses.ts` |
| SPA router module | `/Users/mac/Desktop/my_files/work/storify/storify-management/src/router/modules/ws16-expenses.ts` |
| SPA nav module | `/Users/mac/Desktop/my_files/work/storify/storify-management/src/nav/modules/ws16-expenses.ts` |

## Fix applied (in place)

**`ExpenseController::index()` now returns the house envelope via `$this->ok()`.** The method used a
raw `response()->json([...])`. The stated justification was that the re-registered shared URI needs a
flat `data` row array and that "`$this->ok($rows, …)` wraps rows one level deeper" — but that is only
true of `$this->ok(['data' => $rows])`. The sibling WS-23 `JournalController::index`, which
re-registers `accounting/journal` in exactly the same way, keeps `data` flat *and* uses the helper:
`$this->ok($rows, null, 200, $paginationMeta + [extras])` — extras ride in `meta` beside the
pagination keys. WS-16 now matches that idiom:

- controller — `$this->ok($rows, null, 200, $this->paginationMeta($expenses) + ['stats' => …, 'categories' => …])`
- `src/api/modules/ws16-expenses.ts` — `ExpenseListResponse.meta` is now
  `PaginationMeta & { stats: ExpenseStats; categories: ExpenseOption[] }`
- `src/views/accounting/ExpensesView.vue` — reads `data.meta.stats` / `data.meta.categories`
- `tests/Feature/Api/ws16expensesTest.php` — list assertions moved from `stats.*` to `meta.stats.*`

The observable contract is unchanged for existing consumers: `data` is still the flat row array and
`meta` still carries `current_page` / `last_page` / `per_page` / `total` (the old shared endpoint's
shape; `endpoints.ts` `Paginated<T>` consumers keep working). Only the two new blocks moved from top
level into `meta`.

## Verified clean (no change needed)

1. **Routes → controller methods.** Reflection over the booted route table: all six WS-16 routes
   resolve to existing methods with the right arity — `index(Request)`, `options(Request)`,
   `show(Request, Expense)`, `store(Request, LedgerPostingService)`, `void(Request, Expense, LedgerPostingService)`,
   `destroy(Request, Expense)`. `{expense}` binds implicitly to `App\Models\Expense`.
2. **Envelope + tenant scoping.** All six methods now return `$this->ok(...)` / `$this->error(...)`.
   `index`/`options` filter on the authenticated user's `business_id`; `store` validates
   `expense_category_id` / `ledger_account_id` / `payment_account_id` / `supplier_id` with
   `Rule::exists(...)->where('business_id', …)` (active accounts only) and writes the row's
   `business_id` from the token; `show`/`void`/`destroy` call `authorizeExpense()`, which aborts 403
   on a cross-business id. `store` and the void reversal+status flip run in `DB::transaction`.
   Money stays integer kobo end to end (`toKobo()` converts once, string-exact for plain decimals).
3. **SPA calls ↔ routes ↔ api module.** All six `expenseApi` calls map 1:1 to registered URIs and to
   exports of `ws16-expenses.ts`: `list` GET `/management/accounting/expenses`, `options` GET
   `/management/accounting/expense-options`, `show` GET `…/expenses/{id}`, `create` POST `…/expenses`
   (FormData incl. receipt), `void` POST `…/expenses/{id}/void`, `remove` DELETE `…/expenses/{id}`.
   Response types match the controller's actual keys (row shape, meta+stats+categories, detail's
   journal entry, reversal entry, receipt).
4. **Route module shape / duplicate names.** `ws16-expenses.php` carries only `Route::` lines and
   `permission:` route groups — no `management` prefix, no auth/audience/team wrapper (inherited);
   same shape as ws22/ws23. The one same-name registration,
   `api.management.accounting.expenses.index`, is the deliberate last-wins replacement of the shared
   route file's URI (feature modules load after it); `route:list -v` confirms that URI resolves to
   `ExpenseController@index`. An app-wide `route:list --json` scan of **1107 routes found zero
   duplicate route names**; the only duplicate method+URI pairs are pre-existing web routes
   (`/`, `services`, `support`).
5. **Router / nav modules.** The router module default-exports `RouteRecordRaw[]` with two child
   routes (`accounting/expenses/create`, `accounting/expenses/:id`), no leading slashes, both with
   `meta.title`; the static `create` route precedes the param route. The list route
   (`accounting.expenses`) stays in the shared router, which points at the rebuilt view. Names
   `accounting.expenses.create/show` are unique across all SPA modules. The nav module default-exports
   an empty `NavGroup[]` (documented): AppLayout already renders the Expenses entry with
   `accounting view`, so a group here would duplicate it — the same decision WS-23 made.
6. **Imports resolve.** All `@/` specifiers in the three views and three modules point at existing
   files; component props match (`StatCard` label/value/hint/icon/accent, `StatusBadge` status,
   `PaginationBar` meta + page emit, `AppModal` modelValue/title/maxWidth + #footer, store `can` /
   `success` / `error`).
7. **PHP syntax.** `php -l` clean on all three PHP files; `vendor/bin/pint --test` also passed.

## Unfixable within this workstream's ownership

- **Journal-entry deep link on the expense detail screen.** Roadmap 4.3 asks for the journal-entry
  lines "with link to the entry". The WS-16 detail view renders the lines (account, Dr/Cr) and the
  reversal link, but the management SPA has no `/accounting/journal/:id` route — WS-23's router module
  only adds `accounting/journal/create` — so a router-link would point at a route that does not
  exist. The API side already exposes `GET /management/accounting/journal/{entry}`. The fix belongs
  to WS-23 (add the detail route + view) or to the orchestrator wiring it.

## Verification performed

- `php -l` on the controller, route module and test file — clean.
- `vendor/bin/pint --test` on the same three files — `{"result":"passed"}`.
- `php artisan route:list --path=api/v1/management/accounting/expenses -v` and
  `--path=api/v1/management` — all six WS-16 routes with the expected URIs, names and middleware
  (`auth:sanctum → token.audience:management → team.context → permission:accounting view|accounting expenses`).
- `php artisan tinker --execute` route-action reflection pass — every expense action resolves to an
  existing class + method with matching parameter count.
- App-wide `route:list --json` duplicate scan — 1107 routes, 0 duplicate names.
- Node single-file checks with the SPA's own toolchain libraries: `@vue/compiler-sfc`
  `parse` + `compileScript` + `compileTemplate` on the three views; `typescript.transpileModule` on
  the three TS modules; import-resolution script over every `@/` specifier — all clean.
  (Fleet `npm run typecheck` and `php artisan test` were **not** run, per the brief.)
- Test assumptions cross-checked against sources without executing the suite: `createBusinessOwner`
  assigns Super Admin (so the owner passes the permission gates), Store Manager holds no `accounting`
  permission (403 assertions hold), `FiscalPeriod` names are `Y-m` (closed-period tests),
  `LedgerAccountTemplate` codes/names (`5200 Rent`, `5300 Utilities`, `5900`, `1020 Bank`,
  `1010 Cash on Hand`, `2100 VAT Payable`), `postExpense` VAT split and `reverseEntry`
  (`reversal_of_id`, `reversal:{id}` idempotency key), and `ws16*` helper-name uniqueness.
