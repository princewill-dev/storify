# WS-11 — Payment Configuration (Banks, Paystack, Store Assignment) — repair report

Status: **verified and repaired in place**. This pass re-read every WS-11 file,
checked all seven registration/contract points, fixed one functional SPA defect
and added one API regression test. Three items remain unfixable without
breaching file ownership (listed at the end).

## Files owned and verified

- `app/Http/Controllers/Api/V1/Management/PaymentSettingsController.php`
- `app/Http/Controllers/Api/V1/Management/StorePaymentMethodController.php`
- `routes/api/v1/management/ws11-payment-config.php`
- `tests/Feature/Api/ws11paymentconfigTest.php`
- `storify-management/src/api/modules/ws11-payment-config.ts`
- `storify-management/src/router/modules/ws11-payment-config.ts`
- `storify-management/src/nav/modules/ws11-payment-config.ts`
- `storify-management/src/views/PaymentSettingsView.vue`
- `storify-management/src/views/StorePaymentMethodsView.vue`
- `storify-management/src/components/StorePaymentMethodsPanel.vue`
- `storify-management/src/components/StorePaymentMethodsModal.vue`

## Repairs applied this pass

1. **The bank "Edit" modal could not save anything (SPA bug).**
   `PaymentSettingsView.vue::openBankEdit()` sets `verifyState = 'verified'`,
   and the account-name input was `:readonly="verifyState === 'verified'"` —
   while account_name is the only field `PUT .../bank-accounts/{bank}` accepts.
   Every bank edit therefore opened with all three fields locked (bank and
   account number disabled, account name readonly). The input is now
   `:readonly="verifyState === 'verified' && !editingBank"`, so a rename can be
   typed while the add flow keeps the Paystack-resolved name locked.
2. **New regression test** (suite is now 21 tests):
   `a bank account can be renamed and promoted through the update endpoint` —
   a plain rename succeeds and must not demote the primary account, and
   `is_primary: true` on update moves it and clears the previous holder. This
   covers the happy path of the endpoint the repaired Edit modal calls.

## Repairs already in place from the prior pass (re-verified, still correct)

1. `unassignMethod()` no longer strips `bank_transfer` when other bank accounts
   remain on the store (it detaches the account first and only removes the
   method with the last one).
2. `destroyBankAccount()` clears `bank_transfer` from the stores that were left
   with no account after a non-primary bank delete (the `store_bank` pivot
   cascades; `store_payment_method` does not).
3. `StorePaymentMethodsPanel.vue` enables the mode toggle for
   `auth.isOwner || role === 'superadmin'`, matching the API's
   owner-or-platform-admin guard.

## Verification run (all green)

1. **Routes → methods.** Reflection over both controllers confirms every route
   target exists with the signature the route shape implies (`Request` plus
   `int`/`string` params and implicit `Store`/`StoreBank` binding; `Store`'s
   route key is `store_id`, so the SPA passes `store.store_id` on bound paths
   and the numeric `id` on `whereKey`-validated paths).
2. **Envelope + tenant scope.** Every public action returns
   `$this->ok(...)` / `$this->error(...)`; the only non-envelope exits are
   `abort()` refusals, as elsewhere in the codebase. Scoping is via
   `authorizeBank()` / `authorizeGateway()` / `authorizeStore()` /
   `accessibleStoresQuery()` / `accessibleStoreRule()` /
   `resolveMethod()`. Gateway removal is scoped to the acting business's
   stores (legacy deleted platform-wide).
3. **SPA ↔ routes ↔ api module.** All 19 API calls made by the four SFCs map
   to registered routes and to exported functions in
   `src/api/modules/ws11-payment-config.ts`; no call points at a missing
   route or export.
4. **Route module hygiene.** The module adds only per-group
   `permission:` middleware, exactly like sibling modules (`ws04`, `ws16`,
   `ws23`, …); it adds no management prefix/name/auth wrapper. Global
   `php artisan route:list --json`: 1107 routes, **zero duplicate route
   names**; the 19 WS-11 URIs (`api/v1/management/payment-settings*`,
   `payment-methods/*`, and the three `stores/{store}/…` routes) are unique.
5. **Router/nav modules.** Router module default-exports `RouteRecordRaw[]`
   with relative paths and `meta.title` (`settings.payment-settings`,
   `stores.payment-methods` are unique across all router modules). Nav module
   default-exports `NavGroup[]`; the `/settings/payment-settings` target and
   the "Payments" group label are unique in `src/nav/modules/`.
6. **Imports.** Every `@/…` import in the WS-11 SPA files resolves to an
   existing file (`api/client`, `stores/auth`, `stores/ui`, `AppModal`,
   `nav/types`, the WS-11 api module, and the panel component). No
   "vendor"-named identifier appears anywhere in the WS-11 files.
7. **Syntax.** `php -l` passes on both controllers, the route module and the
   test; `vendor/bin/pint --test` passes on all four PHP files. The four SFCs
   compile with `@vue/compiler-sfc` and the three TS modules parse with the
   TypeScript compiler API after the edit.

## Unfixable within the ownership boundary

1. **`business_payment_method.config` vs checkout `pivot.api_keys` mismatch
   (roadmap acceptance item).** Verified: `store_payment_method` has no
   `api_keys` column (`2026_07_12_150443_create_store_payment_method_table.php`),
   `Store::paymentMethods()` has no `withPivot('api_keys')`, and
   `CheckoutController::useStorePaystackKeys()` (line 322) plus
   `Storefront/InvoicePaymentController.php` (line 73) read
   `$gateway?->pivot?->api_keys`. The controller writes keys canonically to
   `business_payment_method.config` and `mirrorGatewayKeys()` already mirrors
   them onto assigned stores **when the column exists** — it is a documented
   no-op today. Exact follow-up: (a) migration adding a nullable json
   `store_payment_method.api_keys`; (b) `Store::paymentMethods()`
   `->withPivot('api_keys')`; (c) optionally backfill from
   `business_payment_method.config` for already-assigned stores. Migrations,
   the `Store` model and `CheckoutController` are all outside this
   workstream's create/edit set. **Orchestrator must schedule.**
2. **`StorePaymentMethodsModal.vue` is not mounted in
   `StoreSettingsView.vue`** — that view is WS-04-owned (it already links to
   the standalone page at line 844). The component ships with a top-of-file
   MOUNT IN comment; the standalone `/stores/:storeId/payment-methods` page
   works meanwhile.
3. **`store_banks` legacy unique index is now global.** After
   `2026_07_12_142311_drop_store_id_from_store_banks.php` dropped `store_id`,
   MySQL leaves the composite unique as `(account_number, bank_code)`
   platform-wide (no later migration drops it — checked all six migrations
   touching the table). The API guards duplicates per business, but the DB is
   stricter: two businesses cannot register the same beneficiary account, and
   the second insert would surface as a 500. Needs a migration replacing the
   index with `(business_id, account_number, bank_code)`; migrations are
   outside this workstream's file set.

## Verification not run (per fleet rule)

`php artisan test` and `npm run typecheck` were deliberately not executed —
the whole fleet shares one test database and toolchain and the orchestrator
runs them serially. The 21 tests are syntax-checked and reviewer-read but not
runner-verified; everything reproducible in isolation (lint, `route:list`,
reflection, SFC/TS compile with in-memory tooling) was run and is clean.
