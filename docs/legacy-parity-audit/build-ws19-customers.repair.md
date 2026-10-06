# WS-19 — Customers Module Parity — repair pass

**Scope repaired:** the files the WS-19 implementation reported — API
`CustomerParityController`, `CustomerSearchController`, `StoreCustomerController`,
`routes/api/v1/management/ws19-customers.php`; SPA `ws19-customers.ts` (api/router/nav),
`CustomersView.vue`, `CustomerDetailView.vue`, `CustomerEditModal.vue`,
`CustomerStatusModal.vue`; test `tests/Feature/Api/ws19customersTest.php`.

`php -l` (5 files), Pint, `@vue/compiler-sfc` parse+script+template compile (4 SFCs) and
`php artisan route:list` / live route-matching were run; `php artisan test` and
`npm run typecheck` were **not** (fleet rule — the orchestrator runs them serially).

## Verification results

| Check | Result |
| --- | --- |
| Routes → controller methods exist with matching signatures | PASS — all 8 source routes resolve. Booted-app dispatch: `customers`→`index`, `customers/countries`→`countries`, `customers/cus_…`→`show`, PUT→`update`, `/suspend`→`suspend`, `/activate`→`activate`, `stores/{store}/customers`→`StoreCustomerController@index`. `search` resolves to WS-34's `GlobalSearchController` (ws34 loads after ws19 — the hand-off WS-19's docblock describes); `CustomerSearchController` stays as the documented fallback. |
| `customers/countries` beats the `{customer}` binding | PASS — the constrained module route (`cus_[A-Za-z0-9]+`) overwrites the base entry for the same method+URI; the final collection holds exactly **one** GET `api/v1/management/customers/{customer}` and `/customers/countries` matches `countries`, not `show`. Account ids are always `cus_` + 8 alnum (model boot + backfill migration), so the constraint cannot orphan a customer. |
| House envelope + tenant scoping | PASS — every method returns `$this->ok(...)` / `$this->error(...)` (`CustomerSearchController` re-emits `SearchController`'s `ok()` envelope verbatim). `scopedQuery` pins `business_id` and restricted staff to assigned-store orders; `authorizeCustomer`, `authorizeStoreId` and the trait's `authorizeStore` re-check business + access; `countries` scopes both sources by accessible stores; transactions are reached only through the authorized customer's orders. |
| SPA calls ↔ routes ↔ api module | PASS — `list`, `countries`, `detail`, `update`, `suspend`, `activate`, `storeCustomers` all map to registered URIs and to exports in `src/api/modules/ws19-customers.ts`; no view bypasses the module with raw `api` calls. WS-27's `ws27-store-tabs.ts` consumes `storeCustomers` and its `StoreCustomersTab.vue` reads `data.customers_count` / `meta` exactly as served. |
| Route module wrapper / duplicate names | PASS — no prefix, no name wrapper, no auth/middleware duplication (only the house per-route `permission:` groups, as in the parent file). The `customers.*` + `search` names are deliberately re-registered on identical method+URI to replace the thin base entries; the resolved route table has one entry per name (checked by a name-duplication scan and `getByName`: every `api.management.customers.*` / `stores.customers.index` name resolves to the WS-19 controller). |
| Router / nav module shapes | PASS — router default-exports `RouteRecordRaw[]` (child path `customers/:accountId`, no leading slash, `meta.title`); nav default-exports `NavGroup[]` (empty by design — `AppLayout` already renders Sales → Customers, `/customers`, `customers view`). |
| Imports resolve | PASS — every `@/…` import in the three modules and five SFCs resolves to an existing file/export; SFCs compile cleanly. |
| PHP syntax / style | PASS — `php -l` on all five PHP files; `vendor/bin/pint --test` clean. |

## Fixes applied

1. **`CustomerParityController::show()` — detail payload now carries `orders_count`.**
   `CustomerEditModal`'s account summary renders `customer.orders_count ?? 0`, but the
   detail payload's customer object was built without a count (no `withCount` on that
   path), so opening Edit from `/customers/:accountId` showed **0 orders** while the same
   modal opened from the list showed the real number. It now reuses the aggregate already
   computed by `show()` (same store-scoping as `stats.total_orders` and the list's
   `withCount`), so the two entry points and the two blocks agree — no extra query.

2. **`CustomersView.vue` — removed the leftover `useUiStore` import and `const ui`.**
   Dead code carried over from the pre-WS-19 version: the rewrite moved success toasts
   into the two modals, so the store was unused in the view.

## Deliberately not changed

- **Base-route overrides.** `customers.index/show/update/suspend/activate` and `search`
  are registered twice in source (base file + module) on purpose; Laravel keys the
  collection by method+URI, so the module entry wins for matching *and* for name lookup
  (verified live). Renaming them would break the SPA's URIs for no gain.
- **`CustomerSearchController` is dead code by design.** WS-34's `GlobalSearchController`
  wins the same method+URI and already absorbs WS-19's customer semantics
  (`account_id` + full-name CONCAT matching, `customers view` gate, restricted-staff
  scoping, `/customers/{account_id}` URLs). Left in place as the documented fallback.
- **`stores/{store}/customers` is gated `customers view`, where legacy gated the tab
  `stores view`.** This is the stricter, better choice and it is consistent with the
  consumer: WS-27's `StoreTabController::summary` marks the Customers tab
  `permission: customers view`, so no one sees a tab that then 403s. (Accountant, the one
  role with `stores view` but not `customers view`, legitimately has no customer read.)
- **Business list still includes `DELETED` rows** (legacy did; only the *filter* is
  limited to `active|suspended`), and `update` still accepts `deleted` per the legacy edit
  form. Status casing (`strtoupper` against the UPPER-case `CustomerStatus` enum,
  lowercased payloads) matches the base controller and the seeder.
- **`total_spent` = completed orders, labelled `spend_basis`** — the audit's explicit
  "pick one definition and label it" decision item; the SPA tile says
  "Total spent (completed)".
- **Money is emitted as float decimals** exactly like the base `CustomerController`
  (module convention); no arithmetic is done on it. Kobo-integer rule applies to the
  newer accounting/POS domains.

## Unfixable (owned by other workstreams — exact reasons)

1. **`StoreController@show` `customers_count` is still `null`** (roadmap 1.9).
   `Api\V1\Management\StoreController` is an existing shared controller this workstream
   does not own, and it still does `loadCount(['products','orders'])` while emitting
   `customers_count` (line 69). Impact is already absorbed elsewhere: WS-19's
   `GET /management/stores/{store}/customers` serves the correct number and WS-27's
   `StoreCustomersTab.vue` consumes it; WS-03's `stores/{store}/dashboard` computes the
   same unique-buyer count and is what `StoreDetailView.vue` actually renders. Only the
   raw store-show payload keeps null.
2. **The global-search per-result deep link is not wired in the UI** (roadmap 1.8).
   `src/components/SearchModal.vue` is an existing shared component owned by WS-34 and
   its customer rows still call `go('/customers')`. WS-34 already ships
   `GlobalSearchController` items with `url: /customers/{account_id}` and a
   `GlobalSearchModal.vue` that renders them, but swapping the modal in `AppLayout.vue`
   (also WS-34's file) is that workstream's remaining step. The WS-19 route
   (`/customers/:accountId`) and the list rows' deep links exist and work.

## Test-file review (written but not executed — fleet rule)

The 17 Pest tests were reviewed statically against models, migrations, enums, seeder
permissions and the route table: helpers mirror the WS-18 pattern; `Store Associate`
holds `customers view`; `2026_09_01_000004_scope_customer_emails_to_business` backs the
per-business email-uniqueness case; `delivery_routes.store_id/country`,
`delivery_addresses.delivery_route_id` and `DeliveryAddress::deliveryRoute` all exist;
`Order`/`Transaction`/`ActivityLog` fillables and casts cover every fixture field (money
values decode to ints under this PHP's `serialize_precision=-1`, matching the integer
assertions); restricted-staff order counts and the WS-34 search hand-off match the
implementations. No blockers found.

## Continuation pass — independent re-verification

The repair was re-run from scratch against the current tree (no statement above taken on
trust; every check redone). Result: **no further defects found, no further edits needed.**
The two fixes listed above are present in the files (`orders_count` on the show payload,
no dead `useUiStore` import in `CustomersView.vue`).

Re-verified live in this pass:

- `php -l` on all five PHP files — clean. `vendor/bin/pint --test` on the same five —
  `{"tool":"pint","result":"passed"}`.
- Booted-app route match (not just `route:list`): `/customers/countries` dispatches to
  `CustomerParityController@countries`, `/customers/cus_ABC12345` to `@show`,
  `/customers` to `@index`; `getByName('api.management.customers.index')` and
  `getByName('api.management.stores.customers.index')` both resolve to the WS-19
  controllers. `route:list --json` confirms the exact action + middleware per route
  (`customers view` for reads, `customers edit` for PUT, `customers suspend` for
  suspend/activate) and exactly one route per name.
- Name-collision scan across `routes/api/v1/**` and the parent files: `customers.*` and
  `search` are only duplicated by the base file in the same group (deliberate same
  URI+name override, later registration wins) and by admin/ad09 under a different
  `api.admin.` prefix. `stores.customers.index` is unique. `search` is also re-registered
  by ws34, which loads later and therefore owns the URI (its `GlobalSearchController`
  hand-off was re-read and carries the account_id + CONCAT matching, permission gate,
  restricted-staff scoping and `/customers/{account_id}` result URLs).
- Migration/schema cross-checks behind the payloads and the tests: `CustomerStatus`
  values are UPPERCASE (`strtoupper` comparison in `update()` is correct); account ids
  are `cus_` + 8 uppercase alnum by model boot and the backfill, so the
  `cus_[A-Za-z0-9]+` constraint cannot orphan a customer; `apartment`/`zip_code` really
  were dropped by `2025_11_06_000001_restructure_customers_table` and not re-added by
  `2026_08_05_085535_add_address_fields_to_customers_table` (only street/city/state/
  country came back), so the address block is right to omit them; customers email
  uniqueness is `(business_id, email)` per `2026_09_01_000004`, matching the scoped
  `Rule::unique`; `delivery_routes.store_id` exists via
  `2026_01_02_153903_add_store_id_to_delivery_routes_table`; `Store` binds by `store_id`.
- SPA contract checks: `auth.stores` comes from `accessibleStores()` (assigned stores for
  restricted staff), so the store filter cannot offer a store the API will 403;
  WS-27 imports `customerParityApi` from the WS-19 module and reads
  `data.customers_count ?? data.meta.total`; the Customers tab in WS-27's summary is
  gated `customers view`, the same gate as the WS-19 endpoint.
- Tooling checks without running the forbidden commands: `@vue/compiler-sfc`
  parse+script+template compile clean on the four SFCs; esbuild parse clean on the three
  TS modules; VS Code's TypeScript diagnostics empty for `CustomersView.vue`,
  `CustomerDetailView.vue`, both customer modals and the api module.
- Test-file static review re-done: all 17 tests' fixtures (helpers, roles, enums,
  fillables, migrations, token audience) verified against the live sources; money
  assertions hold because `json_encode(4000.0)` yields int `4000` under this PHP.

Changed in this pass: none beyond correcting the test count and adding this section.
Unfixable items above are unchanged (shared-file ownership).
