# AD-09 — Platform customer console (WS9) — repair report

**Verdict:** verified — the implementation matches the roadmap (`roadmap-admin.md`
§12) and the audit (`admin-users-admins.md` D.19–D.23, corrections applied) on
every functional point. All seven repair checks pass; **no defects were found
and no code changes were required**. One cosmetic nav-consolidation item that
needs a shared file is recorded below for the orchestrator.

## Files inspected (reported implementation)

API
- `app/Http/Controllers/Api/V1/Admin/CustomerController.php`
- `routes/api/v1/admin/ad09-customer-console.php`
- `tests/Feature/Api/ad09customerconsoleTest.php`

SPA (storify-admin)
- `src/api/modules/ad09-customer-console.ts`
- `src/router/modules/ad09-customer-console.ts`
- `src/nav/modules/ad09-customer-console.ts`
- `src/views/CustomersView.vue`, `src/views/CustomerDetailView.vue`
- `src/components/CustomerEditModal.vue`, `src/components/CustomerSuspendModal.vue`

Shared files read for contract checks (not edited): `routes/api/v1/admin.php`,
`routes/api.php`, `ApiController`, `ActivityRecorder`, `AdminApiActivityLogger`,
`SetPermissionsTeamId`, the `Customer`/`Order`/`Transaction`/`ActivityLog`/
`DeliveryAddress`/`DeliveryRoute` models, the `CustomerStatus`/`OrderStatus`/
`TransactionStatus` enums, `SpatiePermissionSeeder`, both mailables, the
customer-email-scoping migration, the legacy `Admin\CustomerController`, the
admin SPA router/layout/api client and every shared component the views use.

## Checks run

| Check | Result |
| --- | --- |
| Routes → controller methods exist with matching signatures | PASS — reflection confirms all six actions (`index`, `countries`, `show`, `update`, `suspend`, `activate`) public, `(Request[, Customer])` → `JsonResponse`, parent `ApiController`. `route:list --json` resolves every action string to `Api\V1\Admin\CustomerController@…`. |
| Envelope + platform scoping | PASS — every method returns `$this->ok(...)`/`$this->error(...)` (list meta via `paginationMeta` + `stats`); every method calls `authorizePlatformAccess()` (the established `isAdmin()` `abort_unless` 403 idiom, identical to `BusinessLifecycleController`), on top of `permission:admin.customers`. Platform-wide reads are the documented, intended scope for this console (legacy `/office/customers`), guarded against the in-business "Super Admin" permission-bundle escalation. |
| SPA calls ↔ routes ↔ api module | PASS — `list`, `countries`, `show`, `update`, `suspend`, `activate` all exist in `src/api/modules/ad09-customer-console.ts` and map 1:1 to the registered URIs; no view bypasses the module with raw `api` calls. |
| Route module wrapper / duplicate names | PASS — no `prefix()`/`name()` wrapper (inherited from `admin.php`); the only grouping is the house `permission:admin.customers` + `AdminApiActivityLogger` middleware group, matching sibling `ad08`. Global `route:list --json` scan: **1107 routes, zero duplicate names**; the six `api.admin.customers.*` names appear once each and collide with nothing (legacy `/office` names use the separate `admin.` prefix). |
| Router / nav module shapes | PASS — router default-exports a child array (`customers`, `customers/:accountId`), no leading slash, `meta.title` on both, names `customers` / `customers.show` collide with nothing in `router/index.ts` or the other modules. Nav default-exports `{ label, nodes }` matching `AdminLayout`'s `NavNode` (`icon` present, as siblings require); nodes are permission-filtered by the layout. |
| Imports resolve | PASS — every `@/…`/relative import in the 3 TS modules and 4 SFCs resolves to an existing file/export (checked with a resolver script); bare `vue`/`vue-router` are installed. The 4 SFCs compile cleanly with the installed `@vue/compiler-sfc` (parse + script + template); the 3 TS modules transpile cleanly with the installed `typescript` (isolated syntax check). |
| PHP syntax / style | PASS — `php -l` clean on all three files; `vendor/bin/pint --test` clean. |

Additional contract checks (read, not executed):

- Route binding by `account_id` (`Customer::getRouteKeyName`) with the
  `cus_[A-Za-z0-9]+` constraint — needed so `customers/countries` is not
  swallowed; account ids are always `cus_` + 8 upper-alnum (model boot). Both
  the constraint and the registration order are correct.
- Middleware chain per route (from `route:list --json`): `api →
  auth:sanctum → throttle:api → token.audience:admin → team.context →
  permission:admin.customers → AdminApiActivityLogger`. `admin.customers` is
  seeded and carried by the Platform Admin role; superadmins bypass via
  `Gate::before`; the seeded business "Super Admin" role does hold
  `admin.customers`, which is exactly why the controller's platform-role check
  exists (and the test proves the 403).
- Status casing: `CustomerStatus` values are UPPERCASE in the schema, payloads
  are lowercased, inputs normalised — the list filter, edit status semantics
  ("ACTIVE" verifies, any other status clears `email_verified_at`), and the
  `snapshot()` audit old/new values all round-trip correctly.
- Legacy parity, controller vs `Admin\CustomerController`: identical stat
  formulas (including `total_spent` = orders with a confirmed transaction,
  summed server-side — no PHP float arithmetic), last-10 orders/transactions,
  last-20 customer-subject activity with actor, search over the four legacy
  fields plus the composed full name, per-business e-mail uniqueness (the
  schema's own `customers_business_email_unique`, so a same-address customer in
  another business is legitimately allowed), required suspend reason ≤500,
  already-suspended/already-active 422 guards, queued suspension/activation
  mails with failure logging that never rolls back the mutation, and
  `customer_updated`/`customer_suspended`/`customer_activated` audit rows.
- Roadmap "improve on legacy" items are all present: whitelisted sort (legacy
  passed `sort_by`/`sort_order` straight to `orderBy`), `this_month` dropped,
  cached country derivation that also includes the customer address columns the
  legacy address card rendered.
- Test suite: helper names are `ad09`-prefixed and unique across the suite (no
  cross-file Pest function collisions); fixtures mirror the proven WS19/AD05
  helpers; `assertJsonPath` is strict (`assertSame`) and every numeric
  assertion matches the controller's int casts (`(float)` money values encode
  to integer JSON here, matching the WS19 suite's assertions).

## Fixes applied

None — no defect was found in any of the seven checks. Files are byte-identical
to the implementation report.

## Deliberately not changed

- **Route-level `AdminApiActivityLogger`** (with the permission group) mirrors
  `ad01`/`ad08`; the middleware de-dupes per request, so the orchestrator's
  future group-level wiring writes exactly one row.
- **Per-business e-mail uniqueness on `PUT`** deviates from the legacy
  global-unique rule deliberately: the schema was migrated to
  `unique(business_id, email)`, so the legacy rule would 500 on a legitimate
  cross-business duplicate.
- **Suspension mail queued after commit** rather than inside the transaction
  (legacy sent inside and swallowed failures); same observable behaviour, no
  row lock held during mail transport — consistent with the other admin
  modules.
- **Deleted rows offer "Suspend" in the listing** (`status !== 'suspended'`),
  where legacy's kebab offered it only for active rows; the endpoint accepts
  the transition from any non-suspended state, so this is a presentation
  choice, not a parity break.

## Unfixable here (needs a file this workstream does not own)

1. **Nav consolidation.** The Customers node ships as its own module-appended
   `Navigation` section (the house pattern for cross-cutting nav), so the
   sidebar renders a second "Navigation" header until the orchestrator folds
   the modules into the hardcoded section in
   `storify-admin/src/layouts/AdminLayout.vue` (shared file, not owned by this
   workstream). The module's top-of-file mounting note spells this out. The
   link, route, permission filter and active state all work as shipped.

## Not verified here (by instruction)

`php artisan test` and `npm run typecheck` were not run — the fleet shares one
test database and one toolchain and the orchestrator runs them serially. In
their place: `php -l` + Pint on the PHP files, `route:list --json` (route,
name, middleware and duplicate-name proof), reflection on the controller, SFC
compilation and TS transpilation with the installed compilers, and a full
import-resolution scan.
