# AD-12 — Money configuration (WS12): VAT, payment methods, bank accounts, coupon code edit — repair report

**Verdict:** repaired. Every roadmap §15 deliverable and API route is present and
correct; all checklist items pass on inspection and on the commands that may be
run here. One stale docblock reference was fixed in place. The OF-4.2 coupon
code field remains unwired in the SPA because the owning view is outside this
workstream's file ownership; nav consolidation is an orchestrator wiring item.
The Pest suite was reviewed assertion-by-assertion but not executed (instruction).

## Files inspected

API (`storify-api`)
- `app/Http/Controllers/Api/V1/Admin/VatController.php`
- `app/Http/Controllers/Api/V1/Admin/PaymentMethodController.php`
- `app/Http/Controllers/Api/V1/Admin/BankAccountController.php`
- `app/Http/Controllers/Api/V1/Admin/CouponUpdateController.php`
- `routes/api/v1/admin/ad12-money-config.php`
- `tests/Feature/Api/ad12moneyconfigTest.php`

SPA (`storify-admin`)
- `src/api/modules/ad12-money-config.ts`
- `src/router/modules/ad12-money-config.ts`
- `src/nav/modules/ad12-money-config.ts`
- `src/views/SettingsVatView.vue`
- `src/views/SettingsPaymentMethodsView.vue`
- `src/views/SettingsBankAccountsView.vue`

Read-only cross-checks: `routes/api/v1/admin.php` (and the other
`routes/api/v1/admin/*.php` modules), `ApiController`, `Concerns/EnsuresPlatformAdmin`,
`AdminApiActivityLogger`, `AdminLayout.vue`, `router/index.ts`, `client.ts`,
`composables/useDataTable.ts`, the shared admin components, the `Vat` /
`PaymentMethod` / `BankAccount` / `Coupon` models and their migrations,
`SpatiePermissionSeeder`, `admin-orders-finance.md` + `.verify.md`,
`roadmap-admin.md` §15.

## Checks run

- `php -l` on the six PHP files — clean. `vendor/bin/pint --test` on the same
  six — passed (no reformatting needed).
- `php artisan route:list --path=api/v1/admin -v` — all 13 routes registered
  exactly as reported. Middleware chain on every ad12 route is
  `api → auth:sanctum → throttle:api → EnsureTokenAudience:admin →
  SetPermissionsTeamId → permission:admin.finance|admin.coupons →
  AdminApiActivityLogger`. `PUT api/v1/admin/coupons/{coupon}` resolves once,
  to `CouponUpdateController@update` (the module's later registration replaces
  the shared `admin.php` entry — the route collection keys on method+URI).
- Route-name scan of `route:list --json` across the whole app: 1048 named
  routes, **0 duplicates**; all 13 expected `api.admin.*` names resolve to the
  expected URIs. The only intentional reuse is `api.admin.coupons.update`
  (same name, same URI — the replacement pattern), and it appears exactly once.
- Route → controller cross-check: all 13 actions exist with matching
  signatures (`Request` first, then the route-bound model named after the URI
  parameter: `{vat}`, `{paymentMethod}`, `{bankAccount}`, `{coupon}`). None of
  these models override `getRouteKeyName` (`BelongsToBusiness` adds no global
  scope either), so bindings are by primary key, as the SPA sends.
- Envelope + guard: every method calls `$this->authorizePlatformAdmin()`
  (`EnsuresPlatformAdmin`) and returns `$this->ok(...)` / `$this->error(...)`.
  The rows are platform-global (no `business_id` on `vats`, `payment_methods`,
  `bank_accounts`; coupons are platform coupons), so "tenant-scoped" here means
  the platform-role guard, and it is present on **every** path — including the
  re-registered coupon update, which the shared `CouponController` never had.
  Multi-write mutations (VAT create/update/toggle, bank create/update/delete)
  run inside `DB::transaction`.
- Single-active VAT invariant verified path-by-path against the roadmap's
  "Improve on legacy": create deactivates all then inserts active; update
  activates inside the transaction; toggle inserts the 0% record then
  deactivates every other row; delete refuses the active row. No path can leave
  two active rows or zero rows.
- SPA ↔ API contract (view → module → route):
  - `SettingsVatView` → `vatsApi.list/create/update/disable/remove` →
    `GET|POST /admin/vats`, `PUT|DELETE /admin/vats/{vat}`,
    `POST /admin/vats/{vat}/toggle` — all exist; response shapes
    (`data.vats` + meta, `data.vat`, `message`) match the axios generics.
  - `SettingsPaymentMethodsView` → `paymentMethodsApi.list/toggle` →
    `GET /admin/payment-methods`, `POST /admin/payment-methods/{paymentMethod}/toggle`.
  - `SettingsBankAccountsView` → `bankAccountsApi.list/create/update/toggleActive/remove`
    → `GET|POST /admin/bank-accounts`, `PUT|DELETE /admin/bank-accounts/{bankAccount}`,
    `POST .../toggle-active`. The multipart `_method=PUT` spoof is required
    because the route is a real PUT and PHP does not populate `$_POST` for
    multipart PUT; `Kernel::handle` enables the override and there is no POST
    `{id}` route, so the spoof is the only mechanism — verified against the
    framework source.
- Imports: a resolution pass over every `@/` import in the six SPA files —
  all resolve. Shared components match their prop/emit contracts (`AppModal`
  `v-model/title/maxWidth`; `ConfirmDialog` `v-model/title/message/confirmText/danger/@confirm`;
  `EmptyState` `#action` slot; `StatCard` accents; `TableFooter`
  `meta/perPage/pageSizes` and its `update:perPage` emit, which Vue's
  `update:` listener fallback matches from `@update:per-page`; `TableSkeleton`;
  `useUiStore.success/error`; `formatDate/formatDateTime`).
- SFC compile: `parse` + `compileScript` + `compileTemplate` with the installed
  `@vue/compiler-sfc` — zero errors on all three views. VS Code diagnostics for
  all six SPA files: empty.
- Router module: default-exports `RouteRecordRaw[]` of children of `/` with no
  leading slashes and `meta.title`; names `settings.vat`,
  `settings.payment-methods`, `settings.bank-accounts` are unique across all
  modules, and the static `settings/*` segments outrank ad02's
  `settings/:tab?` (static beats dynamic in Vue Router ranking).
- Nav module: default-exports `{ label, nodes }` sections whose nodes carry the
  `icon`/`to`/`permission` shape `AdminLayout.vue` globs and filters via
  `auth.can()`; `admin.finance` is seeded (`SpatiePermissionSeeder`).
- Tests: 27 tests; helpers are `ad12`-prefixed and collision-free; every
  assertion was cross-checked against the controller's status codes, exact
  messages ("0% VAT created.", "Bank Transfer disabled successfully.", the
  exact VAT switch-off message) and payload shapes. The payment methods the
  tests `firstOrFail()` are inserted by migration
  `2026_07_12_150444_add_type_to_payment_methods.php`, so they exist under
  `RefreshDatabase` without a seeder. The audience-refusal test relies on the
  seeded in-business "Super Admin" role bundling `admin.*` — confirmed in the
  seeder (`permissions => 'all'`), and `Gate::before` bypasses for
  `role = superadmin`, so the finance-admin/superadmin happy paths hold.

## Fixed (1)

1. **Stale docblock reference** in `src/api/modules/ad12-money-config.ts`
   (line 15): the bank-account route comment pointed at `updateBankAccount`,
   which does not exist in the file; the FormData builder is `bankAccountForm`.
   Corrected.

## Unfixable here (needs a file this workstream does not own)

1. **OF-4.2 coupon code field is still disabled.** The API half is complete and
   verified: `PUT /api/v1/admin/coupons/{coupon}` now resolves to
   `CouponUpdateController@update`, accepts `code` (string, ≤50, unique
   ignoring self), uppercases it, and keeps every other legacy rule. The SPA
   half cannot be finished from this workstream: `src/views/CouponsView.vue` is
   a pre-existing view this prompt does not name as ours (and the build rules
   forbid editing existing views owned by other agents). Exact wiring for the
   owner of that file, verified against the current source:
   - line 298: drop `:disabled="!!editing"` from the code input;
   - `save()` (~line 106): add `code: form.code` to the payload when editing
     (or call `couponEditingApi.update` from
     `src/api/modules/ad12-money-config.ts`, already exported for this).
   No server or `src/api/endpoints.ts` change is needed —
   `adminApi.updateCoupon` forwards arbitrary payload keys.
2. **Nav consolidation.** The ad12 nav module renders its own SETTINGS group
   until the orchestrator merges its three nodes into AdminLayout's existing
   Settings section and deletes the wrapper; documented at the top of
   `src/nav/modules/ad12-money-config.ts` as the house rules require. Needs
   `src/layouts/AdminLayout.vue`, which is on the must-not-edit list.
3. **Group-level `AdminApiActivityLogger` wiring** on `routes/api/v1/admin.php`
   (WS1 orchestrator item). Mitigated: both ad12 middleware groups carry the
   logger directly and the per-request attribute de-dupes, so a later
   group-level application still writes exactly one row per request.

## Not verified here (by instruction)

`php artisan test`, `npm run typecheck` and any build were not run — the fleet
shares one test database and one toolchain and the orchestrator runs them
serially. The Pest suite lints and formats clean and its assertions were
statically cross-checked against the controllers; the three SFCs compile clean
and their imports resolve, but the full typecheck remains for the orchestrator.
