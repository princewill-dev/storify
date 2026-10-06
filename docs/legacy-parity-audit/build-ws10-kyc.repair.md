# WS-10 — KYC Verification & Activation Gate — repair pass

Verified against the actual files (not the completion report). Workstream files:

- API: `app/Http/Controllers/Api/V1/Management/KycController.php`,
  `routes/api/v1/management/ws10-kyc.php`,
  `tests/Feature/Api/ws10kycTest.php`
- SPA (management): `src/views/KycView.vue`,
  `src/api/modules/ws10-kyc.ts`,
  `src/router/modules/ws10-kyc.ts`,
  `src/nav/modules/ws10-kyc.ts`,
  `src/components/kyc/KycStatusPill.vue`,
  `src/components/kyc/KycNavPill.vue`,
  `src/components/kyc/KycDashboardBanner.vue`

## Verdict

Passes all seven checklist items; **no in-scope repair was required**. Every route resolves
to a real method, both public controller methods return the house envelope on a
user_id/business_id-scoped query, every SPA call maps to a registered route and an exported
module symbol, the route module carries no prefix/name wrapper and its two names are unique
across all 1048 named routes in the app, the router/nav modules have the right shapes, every
import resolves (SFCs compile, TS modules transpile), and `php -l` is clean on all three PHP
files. The verdict is "clean" rather than "repaired"; three cross-cutting items are outside
this workstream's file ownership and are recorded under Unfixable with the exact wiring
required.

Two of the completion report's three "not done" caveats have since been resolved by other
workstreams: admin WS3's review endpoints now exist (so the reject → resubmit loop can be
exercised end to end), and `KycDashboardBanner` is mounted in `DashboardView.vue`. The third —
the sidebar status pill — is still unwired and is the first Unfixable item below.

**Re-verification (continuation run).** The whole checklist was re-run against the current
workspace before this file was finalised: `php -l` on all three PHP files, `route:list
--path=api/v1/management/kyc` plain and `-v` (both routes present, full inherited middleware
stack shown), a full-app `route:list --json` duplicate-name scan (1048 named routes, zero
duplicates), `@vue/compiler-sfc` parse + `compileScript` + `compileTemplate` on the four SFCs,
and `tsc.transpileModule` plus an on-disk resolution pass over every `@/` and relative import
in the three TS modules. Editor language-server diagnostics for all seven SPA files are empty.
Nothing changed since the first pass; no in-scope defect was found in either run.

## Fixed

Nothing. No defect was found in the seven checklist categories, and the repair brief forbids
touching other workstreams' files, so no edits were made. The verification evidence for each
item is in the next section.

## Unfixable (file owned by another workstream)

1. **Sidebar KYC status pill is not mounted.** Roadmap WS-10 asks for the sidebar entry
   "with status pill (Pending/Verified/Rejected/Required)". The nav entry ships
   (`src/nav/modules/ws10-kyc.ts`), but the pill component must be rendered inside
   `src/layouts/AppLayout.vue`'s nav-item markup — a do-not-edit shared file. WS-10 ships
   `components/kyc/KycNavPill.vue` with a top-of-file mount note, and WS-34's preferred
   `components/ShellKycPill.vue` (same job, fed by `GET /management/shell/counts`, reusing
   WS-10's `KycStatusPill`) is also unwired. The orchestrator/shell workstream must mount one
   of the two.

2. **Owner-only nav visibility is not expressible from a nav module.**
   `NavItem` (`src/nav/types.ts`) carries only `permission`; `AppLayout.vue`'s filter calls
   `auth.can(item.permission)`. Legacy wrapped the KYC entry in `@if($user->isBusinessOwner())`.
   Consequence: staff roles the seeder grants `settings view` (Managing Director, CFO, Manager)
   see the KYC entry and get the API's 403 refusal screen. The nav module documents this as a
   deliberate tradeoff; a real fix needs an `ownerOnly`-style flag plus an `AppLayout` filter
   change, both owned by the shell workstream.

3. **Admin review payload's `history` omits document URLs**
   (`Api\V1\Admin\KycApplicationController@history`, admin WS3). WS-10's resubmission
   semantics are deliberate and test-documented: only a *replaced* upload is cleared from the
   superseded row and deleted from disk; an unreplaced upload stays on the history row. So
   after a rejection → resubmission that re-uploads nothing, the new latest row genuinely has
   no file references, and the reviewer's history panel has no link to the earlier uploads.
   Legacy had the same gap (its new row never carried paths either) and the roadmap's
   "improve on legacy" item covers orphaned-file cleanup only, which is correctly implemented.
   Closing the loop fully is either an admin-payload change (owned by admin WS3: expose the
   earlier rows' document URLs) or a deliberate change to WS-10's tested semantics — not made
   here, because the current behaviour is the contract the workstream shipped and tests assert.

## Verified clean (no change needed)

- **Routes → controller methods.** `GET/POST api/v1/management/kyc` →
  `Api\V1\Management\KycController@show` / `@store`; both exist with the right signatures
  (reflection: `show(Request): JsonResponse`, `store(SubmitKycRequest): JsonResponse`, and
  those are the only two public methods on the class). `php artisan route:list -v` shows the
  full inherited stack — `auth:sanctum`, `token.audience:management`, `team.context` —
  plus the module's own `permission:settings view`.
- **Envelope + tenancy.** Both methods return `$this->ok(...)` / `$this->error(...)` only
  (no raw `response()->json`). Reads are scoped `where('user_id', $user->id)` AND
  `(business_id IS NULL OR business_id = $user->business_id)` — the null arm is deliberate
  and documented (the column post-dates the table); the user_id is the real tenant boundary
  for an owner's own identity documents. The cross-business test asserts a second owner sees
  `application: null` / `history: []`. Writes go through `DB::transaction`; the owner gate
  (`isBusinessOwner()`) is enforced in the controller even for callers holding the route
  permission, and uploads land under `kyc/documents` + `kyc/selfies` on the public disk.
- **SPA ↔ API contract.** `kycApi.show` → `GET /management/kyc`, `kycApi.submit` →
  `POST /management/kyc` (multipart, boundary left to the browser); both routes registered.
  `src/api/modules/ws10-kyc.ts` exports every symbol the three consumers import
  (`kycApi`, `KycState`, `KycGate`, `KycApplication`, `KycPayload`); `KycStatusPill` is also
  consumed by WS-34's `ShellKycPill.vue` with a matching prop shape.
- **Route module shape.** `ws10-kyc.php` contains only `Route::` lines inside a single
  `permission:settings view` group — no `prefix()`/`name()`/auth wrapper of its own (the
  house-rule permission gate, matching every peer module). No duplicate route names: a
  full-app `route:list --json` scan found 1048 named routes and **zero** duplicates;
  `api.management.kyc.show/submit` do not collide with the legacy web routes
  (`management.kyc.*`) or admin WS3's `api.admin.kyc-applications.*`.
- **Router/nav module shapes.** `src/router/modules/ws10-kyc.ts` default-exports one
  `RouteRecordRaw` child of `/` (`path: 'kyc'`, no leading slash, `meta.title`); the name
  `kyc` and the path `/kyc` are unique across every module file and `router/index.ts`.
  `src/nav/modules/ws10-kyc.ts` default-exports `NavGroup[]` in exactly the shape
  `AppLayout`'s `../nav/modules/*.ts` glob consumes (`label`, `icon`, `items[{label, to,
  icon, permission}]`); group label "Compliance" appears in no other module or layout group.
- **Imports resolve.** All four SFCs compile with `@vue/compiler-sfc`
  (parse + `compileScript` + `compileTemplate`, zero errors); the three TS modules
  transpile clean via `tsc.transpileModule`; every `@/...` target exists (`@/api/client`,
  `@/api/modules/ws10-kyc`, `@/lib/money` → `formatDate(value, withTime)`,
  `@/stores/auth` → `isOwner`/`user.phone`, `@/stores/ui` → `success`, `@/components/AppModal.vue`
  → `modelValue`/`title`/`maxWidth`, `@/components/kyc/KycStatusPill.vue`, `@/nav/types`,
  `@/views/KycView.vue`).
- **PHP syntax.** `php -l` clean on the controller, the route module and the test file.
- **WS-09 subscription-gate exemption.** `SubscriptionGate::isExempt` strips the
  `api.management.` prefix before matching, and `kyc.show`/`kyc.submit` are in
  `EXEMPT_ROUTES` with `kyc.` in `EXEMPT_PREFIXES` — the route module's comment requirement
  holds in the shipped WS-09 code.
- **Activation gate.** `StoreLifecycleController@activate` (WS-04, not edited) resolves the
  *owner's* application via `User::kycApplication()` = `hasOne(...)->latestOfMany()`, so the
  new-row-per-submission model reads the right row after a rejection/resubmission cycle; the
  test asserts the 422 message and a 200 after approval.
- **Admin WS3 loop now exists.** `api.admin.kyc-applications.*` routes + controller +
  `KycApprovalService` write `status`, `approved_at`/`rejected_at`, `review_notes` and
  cascade the owner's `status` — exactly the fields WS-10's `show()` reads. The completion
  report's "loop cannot be exercised end to end" note is stale.
- **Tests.** `ws10kycTest.php` holds 20 tests (the completion report said 18), all helper
  functions uniquely `ws10`-prefixed (no collisions across the suite), every referenced
  model/mailable/helper exists (`createBusinessOwner` from `tests/Pest.php`), and each
  expectation was traced by hand against the controller and the workspace seeder — including
  the four-state pill payload, the inactive-document-type refusal, the superseded-file
  cleanup, the mail-failure isolation and the four-state gate banner transitions. Authored
  but not executed here, per fleet rules.

Commands run: `php -l` (controller, route module, test), `php artisan route:list`
(`--path=api/v1/management/kyc` plain and `-v`, `--path=api/v1/management`, and a full-app
`--json` duplicate-name scan), PHP `ReflectionClass` checks on `KycController`, a grep sweep
for duplicate route names/SPA paths/nav labels, `@vue/compiler-sfc` compilation of the four
SFCs and `tsc.transpileModule` of the three TS modules. `php artisan test` and
`npm run typecheck` were **not** run, per instructions.

### Notes (no action taken)

- Resubmission without re-uploading files leaves the *latest* application row without file
  references (previous uploads stay on the history row) — see Unfixable #3 for the full
  reasoning and why the behaviour was left as shipped.
- The completion report's test count (18) understates the file's 20 tests; harmless.
