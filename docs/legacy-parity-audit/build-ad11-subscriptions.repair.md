# AD-11 — Subscriptions, plans & early access (WS11) — repair pass

Verified against the actual files (not the completion report). Workstream files:

- API: `app/Http/Controllers/Api/V1/Admin/SubscriptionController.php`,
  `app/Http/Controllers/Api/V1/Admin/SubscriptionPlanController.php`,
  `app/Http/Controllers/Api/V1/Admin/EarlyPassController.php`,
  `app/Http/Controllers/Api/V1/Admin/Concerns/InteractsWithPlans.php`,
  `routes/api/v1/admin/ad11-subscriptions.php`,
  `tests/Feature/Api/ad11subscriptionsTest.php`
- Admin SPA: `src/views/SubscriptionsView.vue`, `src/views/SubscriptionPlansView.vue`,
  `src/views/EarlyAccessView.vue`, `src/views/EarlyAccessDetailView.vue`,
  `src/api/modules/ad11-subscriptions.ts`, `src/router/modules/ad11-subscriptions.ts`,
  `src/nav/modules/ad11-subscriptions.ts`
- Management SPA: no AD-11 surface (this is an admin-only workstream).

## Verdict

**Repaired in place — three defects fixed.** The headline defect is functional: the revived `trial`
facet overlapped the five status buckets, so the `Active` pill returned rows its count did not
advertise (and the workstream's own test could not pass). The second is an SPA dead control; the
third is a transaction-idiom drift inside the pass controller. Every route resolves to an existing
public method, every method returns the house envelope behind the platform-role guard, every SPA
call matches a registered route and an exported module function, the route module inherits its
wrappers and registers zero duplicate names (1107 routes / 1048 named, app-wide scan), both SPA
module shapes match their consumers, imports resolve, and every PHP file passes `php -l` +
`pint --test` while all four SFCs parse/compile and all three TS modules transpile.

## Fixed

1. **The `trial` facet overlapped the status buckets — pill counts did not match the rows their
   filters returned, and the workstream's own test failed on it.**
   `applyStatusFilter()` filtered the five row statuses with a plain `where('status', $status)`,
   while `statusCounts()` counted plain statuses and `trial` as an extra bucket. A subscription on
   a trial-plan template (or with `metadata.trial = true`) therefore sat in **both** `Active` and
   `Trial`: with the test fixture (two trial rows that are also `status = active`), `status=active`
   returned **3** rows while the pill advertised **1**, and the six pills did not partition `all`.
   The facets are now exclusive, so every pill returns exactly the number of rows printed on it:
   `active/pending/suspended/expired/cancelled` exclude the trial facet in the filter **and** in
   `status_counts`, `trial` is counted once, and `all` is the sum of the six facets. This is the
   same filter/badge-drift class the sibling admin lists (KYC queue, orders oversight) guard.

   - One SQL trap fixed while implementing it: the obvious `whereNot(trialConstraint)` compiles to
     `and not (exists(…) or json_extract(metadata, '$.trial') = true)`, which is **NULL — and so
     drops the row — whenever `metadata` is NULL**. A read-only MySQL probe confirmed
     `negated = NULL`, `complement = 1`. `nonTrialConstraint()` is therefore written positively
     (`whereDoesntHave('subscriptionPlan', is_trial)` plus
     `metadata->trial IS NULL OR NOT (json_extract(…) = true)`), so every ordinary row (metadata
     null) stays in its bucket and its status filter still returns it.
   - The test was strengthened: it now asserts `suspended`, and that the six buckets sum to `all`
     (the invariant that pins the partition).
2. **`EarlyAccessDetailView.vue`'s per-page selector was a dead control.** `TableFooter` always
   renders a per-page `<select>` and emits `update:perPage`; the detail view hard-coded
   `per_page: 20` and did not listen, so choosing 50/100 did nothing even though the API's `show`
   reads `per_page`. Added a `perPage` ref, wired it into the request, the watcher and the footer.
   It was the only view in the SPA using `TableFooter` without the listener.
3. **Early-pass `store` / `update` were the only mutations in their own controller not wrapped in
   `DB::transaction`.** The sibling admin controllers (SubscriptionPlan, Vat, Category,
   BankAccount) and this controller's own `toggleStatus`/`destroy` wrap mutation + audit row
   atomically; the two remaining paths did not. Aligned — behaviour on success is unchanged.

## Verified clean (no change needed)

1. **Routes → methods and signatures.** All 11 route targets exist as public methods with the
   expected shape (`SubscriptionController@index`; `SubscriptionPlanController@{index,store,update,destroy}`;
   `EarlyPassController@{index,show,store,update,toggleStatus,destroy}`). `{plan}` binds by
   `SubscriptionPlan::getRouteKeyName()` = `plan_code`; `{earlyPass}` by `EarlyPass::getRouteKeyName()`
   = `code` — exactly what the SPA, the tests and the legacy `/office/early-access/{CODE}` pass.
   `php artisan route:list --path=api/v1/admin` shows all 11 under `api.admin.` with
   `auth:sanctum`, `token.audience:admin`, `team.context`, the expected `permission:` gate and the
   activity logger.
2. **Envelope + platform scoping.** Every action returns `$this->ok(...)` / `$this->error(...)`
   (`abort_unless(...403)` for the platform-role guard, as elsewhere). These are platform-wide
   consoles, so the tenant rule is the platform-role check: `EnsuresPlatformAdmin` blocks a
   business-scoped Super Admin holding a leaked admin-audience token (its in-business role bundles
   every `admin.*` permission), verified against the seeder. Permission mapping matches legacy
   exactly — subscriptions/plans on `admin.subscriptions` (Finance Admin has it), early access on
   `admin.businesses` (Finance Admin does not).
3. **SPA call ↔ route ↔ module export.** All ten module functions used by the views exist
   (`subscriptions`, `plans`, `createPlan`, `updatePlan`, `deletePlan`, `passes`, `pass`,
   `createPass`, `updatePass`, `togglePass`, `deletePass`) and each hits a registered URI with the
   right method and bound key (`plan_code` / uppercase `code`). Response shapes were checked
   against the payloads field-by-field (rows, `meta`, `status_counts`, `data.pass`, `data.usages`,
   `message`).
4. **Route module shape.** `routes/api/v1/admin/ad11-subscriptions.php` has no prefix/name/auth
   wrapper — only the `permission:` groups plus `AdminApiActivityLogger`, the exact idiom of the
   sibling modules — and its names are unique app-wide (0 duplicates across 1048 named routes; the
   only other `subscriptions.index` / `early-access.*` hits are the legacy `routes/v1/` web files,
   which are not mounted on the API).
5. **Router and nav modules.** The router module default-exports child routes with `meta.title`
   and no leading slash; the nav module default-exports the `{ label, nodes }[]` shape the admin
   layout's glob consumes, with nodes gated by the same permissions as the routes.
6. **Imports.** Every SPA import resolves (`@/api/client`, `@/composables/useDataTable`,
   `@/lib/format`, the shared components/stores, `vue-router`); every PHP class reference in the
   controllers has its `use` statement.
7. **Syntax / static checks (actually run).** `php -l` on all six PHP files; `pint --test` on all
   six; `route:list` (above); a duplicate-name scan; `@vue/compiler-sfc` parse + `compileScript` +
   `compileTemplate` on all four views and `ts.transpileModule` on the three TS modules — all
   green. `php artisan test` and `npm run typecheck` were **not** run, per the brief; the test
   expectations were re-derived by hand against the repaired SQL (fixtures re-checked against the
   live `storify_test` schema read-only: fixtures supply every NOT NULL column without a default).

## Not fixed here (file ownership / hand-offs)

1. **Nav consolidation.** The roadmap nests these nodes as Commerce › Subscriptions & Plans and
   Businesses › Early Access; `AdminLayout.vue` hardcodes those sections, so the nav module ships
   its own sections (like WS-1/WS-8/AD-05) with the merge instructions in its header comment.
   Orchestrator wires. (Declared hand-off, not a defect.)
2. **Duplicate section labels.** Several nav modules export `Commerce` / `Businesses` alongside the
   layout's own sections; `AdminLayout.vue` keys sections by `label`, so identical labels produce
   duplicate Vue keys. Pre-existing, fleet-wide and only fixable in the shared layout (e.g.
   `${label}-${index}` keys or real consolidation) — not touched from this workstream.
