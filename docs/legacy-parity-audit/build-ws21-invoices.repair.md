# WS-21 — Invoices Module — repair pass

**Scope repaired:** the files reported by the WS-21 implementation — API
`InvoiceController`, route module, PDF blade, and feature test; SPA api module,
router module, nav module, the three invoice views and the status badge.

**Verification tools run:** `php -l`, Pint (`--test`), `php artisan route:list`
(path + app-wide duplicate-name scan), a reflection pass over the controller,
router URI matching probes, a JSON-encoding probe on the runtime PHP,
`@vue/compiler-sfc` compile of every SFC, `ts.transpileModule` on the TS
modules, and a scripted import-resolution walk. `php artisan test` /
`npm run typecheck` were **not** run (the fleet shares one test database and one
toolchain — the orchestrator runs them serially).

A second (continuation) pass re-ran every check against the working tree; the
results below are the merged outcome of both passes.

## Verification results

| Check | Result |
| --- | --- |
| Route module methods exist with matching signatures | PASS — all 11 `api/v1/management/invoices*` routes resolve to `InvoiceController@{index, formOptions, pdf, show, store, update, send, markPaid, recordPayment, void, destroy}`, all public, verified by reflection and in `route:list` |
| Middleware wiring | PASS — every route inherits `auth:sanctum, token.audience:management, team.context` from the parent group and carries the right `permission:invoices view|create|edit|delete` gate |
| House envelope + tenant scoping | PASS — every JSON method returns `$this->ok(...)` / `$this->error(...)`; `index`/`formOptions` scope by `business_id` + `accessibleStores()`; `store_id`/`customer_id` re-checked against `accessibleStores()` / business before write; `authorizeInvoice()` checks business + restricted-staff store on every bound route; mutations run in `DB::transaction` |
| SPA calls ↔ routes ↔ api module | PASS — the 11 wrappers in `src/api/modules/ws21-invoices.ts` map one-to-one onto registered routes and every view call goes through `invoicesApi` |
| Route module wrapper / duplicate names | PASS — no prefix/name/auth wrapper (only the required `permission:` groups); app-wide `route:list --json` duplicate-name scan: 1048 named routes, **0 duplicates** (the legacy `management.invoices.*` names are a separate group and do not collide) |
| Route binding / static route precedence | PASS — router match probes: `invoices/form-options` → `api.management.invoices.form-options`; `invoices/INV-…` → `.show`; `invoices/INV-…/pdf` → `.pdf` (static route declared first plus a negative-lookahead constraint) |
| Payment-link route dependency | PASS — `route('invoice.pay.show', …)` resolves against the existing storefront route; the URL is generated at `https://…/pay/invoice/{token}` |
| Router/nav module shapes | PASS — router default-exports child `RouteRecordRaw[]` (relative paths, `meta.title`, static `create` before the binding); nav default-exports `NavGroup[]` matching `@/nav/types` and is auto-merged by AppLayout's `../nav/modules/*.ts` glob |
| Imports resolve | PASS — scripted walk: every `@/...` and relative import in the four SFCs / three TS modules resolves to an existing file; every PHP import resolves (support classes, `LedgerPostingService::{safe,postInvoice,postPaymentReceived}`, `InvoiceMail(Invoice, ?string)`, `Store::creditBalance(int kobo)`, enums, models all match their call sites) |
| PHP syntax | PASS — `php -l` on the controller, route module, test and PDF blade |
| Pint | PASS — `vendor/bin/pint --test` on the touched PHP files |
| Vue SFC compile | PASS — parse + `compileScript` + `compileTemplate` on all four SFCs |
| TS syntax | PASS — `ts.transpileModule` on the three route/api/nav modules |
| Permission strings seeded | PASS — `invoices view|create|edit|delete|send` exist in `SpatiePermissionSeeder` and `SyncPermissions`; roles map as the routes expect |

## Fixes applied

**1. Twelve whole-naira API test assertions compared floats against JSON ints
(13 assertions).** PHP's `json_encode` drops the zero fraction — `json_encode(205.0)`
is `"205"` — and neither Laravel's `JsonResponse` (encoding options `0`) nor Symfony's
default (`15`, hex-only flags) sets `JSON_PRESERVE_ZERO_FRACTION`. `assertJsonPath`
compares with `PHPUnit::assertSame` (`AssertableJsonString::assertPath`), so
`->assertJsonPath('data.invoice.total', 205.0)` can never match the decoded integer
`205` and every such assertion would fail. Fixed in
`tests/Feature/Api/ws21invoicesTest.php` by asserting the integers the API actually
emits (values unchanged), with a header comment explaining the rule: lines 83
(`stats.revenue`), 134–137 (`subtotal`, `tax_amount`, `total`, item `amount`),
152–153 (`total`, `discount_amount`), 163 (clamped `total`), 314 (updated `total`),
370 (`remaining`), 422–423 (`amount_paid`, `remaining`). This matches the existing
convention in `tests/Feature/Api/StorefrontApiTest.php`, which asserts money as ints
(`data.order.total`, 5000).

The encoding premise was re-confirmed on the runtime PHP this pass:
PHP 8.4.25, `serialize_precision=-1`, `json_encode(205.0) === "205"` — so the
int-form assertions are the ones that pass, and the fix is correct and still in
place.

## Verified clean — deliberately not changed

- `GET invoices/{invoice}` binds by `invoice_number` (the model's route key); the
  static `form-options` route is declared first and additionally excluded by the
  `where` pattern, so it can never be swallowed by the binding (probe-verified).
- `pdf()` returns the DomPDF artefact instead of the JSON envelope — by design for a
  download route (its consumer streams a blob); the route is specified as "DomPDF
  artefact".
- Permission mapping follows the legacy group exactly except `POST invoices`, gated
  by the seeded `invoices create` ability where legacy put store under `invoices
  view`. The stricter gate matches the house group-action convention and the SPA's
  own `auth.can('invoices create')` gating; `invoices send` remains unrouted exactly
  as legacy left it.
- Legacy asymmetries kept per the verify pass: "Save & Send" mails without ledger
  posting while the explicit `send` endpoint posts; `overdue` is never auto-assigned
  (filter only); `void` refuses a fully paid invoice (the one guard legacy lacked);
  the record-payment password gate is kept as a deliberate security feature.
- Money paths stay integer-kobo: totals are computed in kobo and converted once at
  the decimal persistence boundary; `computeInvoiceTotals` clamp semantics are
  preserved (total can never go negative).
- Restricted (store-assigned) staff only see/settle invoices on their stores; a
  store-less invoice is not reachable for them (secure default, matching
  `accessibleStores()` semantics used across the fleet).
- `creditStoreBalance()` mirrors the house idiom in
  `TransactionController::confirm` (lock + `Store::creditBalance()` + before/after
  audit fields), so it was left as-is.

## Hand-off — outside this workstream's file ownership

- The same whole-float `assertJsonPath(..., x.0)` pattern exists in eight other
  workstream test files (`ws04`, `ws07`, `ws14`, `ws25`, `ws28`, `ws30`, `ad12`,
  `ad15`) — 45 occurrences repo-wide. Each file is owned by its own workstream, so
  they were not touched here; where the corresponding controller emits a PHP float
  for a whole amount, those assertions have the identical type mismatch and will fail
  the serial run. Worth a fleet-wide sweep of `/management` money assertions.
- The nav module carries a top-of-file MOUNT NOTE for the orchestrator: to match the
  legacy Finance grouping it can be folded beside Transactions; the glob merge
  already renders it, so this is cosmetic only.

## Verdict

WS-21 is sound: routes resolve to existing methods with the right signatures and
permission gates, every JSON response uses the house envelope, all reads/writes are
tenant-scoped, the SPA contract matches the registered routes one-to-one, the
router/nav modules have the correct shapes, every import resolves, and all syntax
checks pass. One defect was found and repaired in the earlier pass — the test
suite's whole-naira float assertions, which could never pass against the
framework's JSON encoding — and this continuation pass re-confirmed the fix in
place. No new defects were found; nothing is blocked on file ownership.
