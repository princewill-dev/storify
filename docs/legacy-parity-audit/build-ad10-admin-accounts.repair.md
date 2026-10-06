# AD-10 (WS10) — Admin accounts & invitations — repair pass

Verified against the actual files (not the completion report). Workstream files:

- API: `app/Http/Controllers/Api/V1/Admin/AdminController.php`,
  `app/Http/Controllers/Api/V1/Admin/AdminInvitationController.php`,
  `app/Mail/AdminInvitationSpaMail.php`,
  `routes/api/v1/admin/ad10-admin-accounts.php`,
  `tests/Feature/Api/ad10adminaccountsTest.php`
- SPA (storify-admin): `src/api/modules/ad10-admin-accounts.ts`,
  `src/router/modules/ad10-admin-accounts.ts`, `src/nav/modules/ad10-admin-accounts.ts`,
  `src/views/AdminsView.vue`, `src/views/AcceptInvitationView.vue`

Context read (not edited): `routes/api.php`, `routes/api/v1/admin.php`,
`routes/api/v1/auth.php`, `app/Http/Controllers/Api/V1/ApiController.php`,
`app/Http/Controllers/Api/V1/Auth/Concerns/BuildsAuthResponses.php`,
`app/Http/Controllers/Api/V1/Auth/InvitationController.php` (reference only),
`app/Http/Controllers/Api/V1/Admin/Concerns/EnsuresPlatformAdmin.php`,
`app/Http/Middleware/AdminApiActivityLogger.php`, `app/Http/Middleware/SetPermissionsTeamId.php`,
`app/Http/Middleware/EnsureTokenAudience.php`, `app/Models/User.php`, `app/Models/Customer.php`,
`app/Mail/AdminInvitationMail.php`, `app/Mail/StaffInvitationSpaMail.php`,
`resources/views/emails/admin/invitation.blade.php`, `bootstrap/app.php`, `config/api.php`,
`config/app.php`, `database/seeders/SpatiePermissionSeeder.php`, `tests/Pest.php`,
`src/router/index.ts`, `src/layouts/AdminLayout.vue`, `src/api/client.ts`, `src/lib/runtimeConfig.ts`,
`src/lib/format.ts`, `src/composables/useDataTable.ts`, `src/stores/{auth,ui}.ts`,
the referenced components, and sibling modules ad08, ad02, ad04, ad15.

## Verdict

Repaired. Two real defects were found and fixed — one SPA runtime defect
(`AdminsView.vue` rendered `<StatusBadge>` without importing it) and one
idiom/shape gap in the nav module (no `NavNode` type annotation and no icon).
Everything else passes the seven checklist items: all eight route targets resolve to
real controller methods with the right signatures, the controllers answer with the house
envelope (one deliberate, documented 409 exception — below) and trust no request id,
every SPA call maps to a registered route and an exported module symbol, the route module
carries no prefix/name/auth wrapper and its names are unique app-wide, the router/nav
modules have the right shapes, all imports resolve (both SFCs compile; all three TS
modules transpile), and `php -l` is clean. One wiring item lies outside this workstream's
file ownership and is recorded under Unfixable.

## Checks run

- `php -l` on all five PHP files — clean.
- `php artisan route:list --path=api/v1/admin` and `--path=api/v1/admin/invitations -v`:
  all eight routes resolve `class@method`; the two public invitation routes show only
  `api` (+ `throttle:auth` on POST) — `withoutMiddleware(['auth:sanctum',
  'token.audience:admin', 'team.context'])` strips exactly the parent group's strings —
  and each invitation method+URI has exactly one entry (the `auth.php` registration is
  replaced, as the module header claims).
- `php artisan route:list --json` cross-checked for duplicate names: zero duplicates
  app-wide; the legacy web names (`admin.admins.*`, `admin.invitation.*`) differ from
  `api.admin.admins.*`.
- `@vue/compiler-sfc` parse + `compileScript`/`compileTemplate` on both SFCs (0 template
  errors); `typescript.transpileModule` syntax check on all three TS modules.
- Manual contract review of every view/component prop and store method used.

## Fixed

1. **`<StatusBadge>` used in the template but never imported** (`src/views/AdminsView.vue`).
   The status column rendered as an unresolved component (blank cell plus a Vue warning);
   every other admin view imports it explicitly. Added
   `import StatusBadge from '@/components/StatusBadge.vue'` beside the sibling component
   imports. Re-verified with `@vue/compiler-sfc`: `StatusBadge` now resolves as a script
   binding and the template compiles with no errors.

2. **Nav module lacked the house `NavNode` annotation and the node's icon**
   (`src/nav/modules/ad10-admin-accounts.ts`). Peers (ad02, ad04, ad15, and ad03 after its
   repair) annotate their sections with a local `NavLeaf`/`NavNode` pair; the layout's
   `NavNode` requires `icon`, and an icon-less node renders an empty icon slot. Added the
   same local types and `Array<{ label: string; nodes: NavNode[] }>` annotation, and
   `icon: 'fi fi-rr-user-shield'` to the Admins leaf (harmless when the orchestrator folds
   the leaf into the existing Users group, correct if the section renders standalone).

## Verified, no change needed

- **Route module hygiene.** Only `Route::` lines plus the two controller imports and the
  `AdminApiActivityLogger` import it uses; no prefix/name/auth wrapper; the
  `permission:admin.admins` + activity-logger group mirrors ad08. Module glob loads it
  inside the shared group, so `api/v1/admin` + `api.admin.` are inherited.
- **Route targets.** `index/roles/store/resend/update/destroy` exist on `AdminController`
  (`Request $request`, implicit `User $admin` on the per-account actions) and
  `show/accept` on `AdminInvitationController` (`string $token`; `Request` spliced first
  by Laravel's dependency resolver). `{admin}` binds by `account_code`
  (`User::getRouteKeyName()`), and `ensureManaged()` 404s anything that is not a platform
  account, so a tenant id from the URL never reaches a mutation. `findByToken()` scopes to
  the platform roles, so a staff invitation token cannot be redeemed here (test-covered).
- **Envelope.** Every method returns through `ApiController::ok()/error()` except the POST
  accept 409 (below). Guards (self/superadmin) return `error()` with the message the SPA
  toasts; `destroy()` detaches roles/tokens inside a `DB::transaction` before the hard
  delete. No money math, no `vendor` naming anywhere in the workstream.
- **SPA ↔ API contract.** `/admin/admins` (list, invite), `/admin/admins/roles`,
  `/admin/admins/{code}/resend`, `/admin/admins/{code}` (PUT/DELETE) and
  `/admin/invitations/{token}` (GET/POST) all match route:list; the base URL ends in
  `/api/v1`; `meta.stats` (total/pending/active) matches the controller's
  `paginationMeta + stats`; `emailed`/`changed`/`warning` fields match the controller's
  payloads; `account_code` (not the numeric id) is used for per-row actions.
- **Router/nav modules.** Child routes default-export `RouteRecordRaw[]` with relative
  paths and `meta.title`; duplicate-name check across all `src/router/modules/*.ts` and the
  shared router finds none for `admins` / `admins.accept-invitation`. The accept route's
  `meta.requiresAuth: false` overrides the shell's `true` through vue-router's meta merge,
  so an invited admin with no session is not bounced to `/login`.
- **Mail loop.** `AdminInvitationSpaMail` renders the existing
  `emails.admin.invitation` view, links `{ADMIN_SPA_URL}/accept-invitation/{token}` — which
  matches the router path and the admin SPA's Vite port (5176) — and reuses
  `User::invitation_token`. `invited_at`/`accepted_at`/`status` casts exist on `User`;
  the accept write uses `forceFill` for the non-fillable `email_verified_at`.
- **Tests.** File matches the fleet naming convention (`*Test.php`, runs under
  phpunit's `tests/Feature` directory); helpers are all `ad10*`-prefixed (no global
  redeclare risk) and `createBusinessOwner()` exists in `tests/Pest.php`; the `Mail`
  facade is explicitly imported; the test's premise that the seeded *Platform Admin* role
  excludes `admin.admins` was verified against `SpatiePermissionSeeder`.

## Deliberate deviations kept (documented in the files)

1. **POST accept returns a raw 409** `{message, already_accepted, email}` instead of
   `$this->error(...)` (`AdminInvitationController::accept`). This is the only way to carry
   the `already_accepted` flag the GET/POST state contract promises; the SPA branches on
   the status alone, and the test asserts the exact body. Converting it would break the
   tested contract, so it stays.
2. **`AdminInvitationSpaMail` reads `env('ADMIN_SPA_URL')` directly**, exactly like WS-20's
   `StaffInvitationSpaMail`. Under `config:cache` it degrades to the derived
   `https://admin.<main domain>` — the correct production URL — so it is left consistent
   with its sibling rather than divergent.

## Unfixable (files owned by another workstream)

1. **The Admins leaf still renders as a second "Users" sidebar section until the
   orchestrator consolidates `AdminLayout.vue`.** The layout both hardcodes its "Users"
   group and globs every `src/nav/modules/*.ts` section verbatim, so ad10's section (like
   ad08's) shows a duplicate "Users" heading. The module carries the mounting note; fixing
   the duplication requires editing `src/layouts/AdminLayout.vue` (a do-not-edit shared
   file).
2. **`Api\V1\Auth\InvitationController::showAdmin/acceptAdmin` and their
   `routes/api/v1/auth.php` registrations are now dead** — ad10's re-registration replaces
   both URIs with the richer state contract. Retiring the shared controller/methods
   requires editing files this workstream does not own; the shadowing is verified harmless
   (route:list shows exactly one entry per method+URI).
