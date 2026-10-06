# AD-08 — User moderation completion (WS8) — repair report

**Verdict:** repaired. The implementation matches the roadmap and the audit's
corrections on every functional point (directory filters + stats + plan column,
detail console, edit audit, suspend/activate parity, guarded delete/restore,
reset-password with mail-failure fallback, start + stop audit rows, staff
parity). Four defects were found and fixed in place; one previously-reported
gap (`user_impersonation_stopped` missing on the management "Return to Admin"
path) turned out to be fixable without touching an unowned file and is now
closed. One cross-cutting wiring item remains for the orchestrator because it
needs shared layouts (recorded below).

## Files inspected (reported implementation)

API
- `app/Http/Controllers/Api/V1/Admin/UserModerationController.php`
- `routes/api/v1/admin/ad08-user-moderation.php`
- `tests/Feature/Api/ad08usermoderationTest.php` (15 Pest cases as reported)

SPA (storify-admin)
- `src/api/modules/ad08-user-moderation.ts`
- `src/lib/impersonation.ts`
- `src/views/UsersView.vue`, `src/views/UserDetailView.vue`
- `src/router/modules/ad08-user-moderation.ts`, `src/nav/modules/ad08-user-moderation.ts`

SPA (storify-management)
- `src/components/ImpersonationHandoff.vue`

## Files created by this repair

- `app/Http/Controllers/Api/V1/Management/ImpersonationStopController.php`
- `routes/api/v1/management/ad08-impersonation-stop.php`

## Checks run

- `php -l` on all six PHP files (three reported + two new + the edited test) —
  clean, re-run after every edit.
- `php artisan route:list --path=api/v1/admin/users -v` — exactly 12 rows, one
  per URI, every one pointing at `UserModerationController` (the shared
  `routes/api/v1/admin.php` registrations and the `auth.php`
  `users/{user}/impersonate` registration are replaced, not duplicated).
  Middleware chain per row: `api → auth:sanctum → throttle:api →
  EnsureTokenAudience:admin → SetPermissionsTeamId →
  permission:admin.users → AdminApiActivityLogger`; impersonate and
  stop-impersonation add `permission:admin.users.impersonate`.
- `php artisan route:list --json` across the whole app — **1107 routes, zero
  duplicate route names**. That is the proof the "re-register the URI from a
  module file" mechanism works: same method+URI+name, later file wins, one
  `route:list` entry. No ad08 route name collides with another module.
- `php artisan route:list --path=stop-impersonation -v` — both stops resolve:
  `api.admin.users.stop-impersonation → UserModerationController@stopImpersonation`
  and `api.management.auth.stop-impersonation → ImpersonationStopController@stop`
  (the new override took effect on the same name/URI/middleware).
- Controller ↔ route contract: all 12 route actions exist with matching
  signatures (`index/show/update/suspend/activate/verify/unverify/resetPassword/
  destroy/restore/impersonate/stopImpersonation`), as does the new
  `stop()`; route-model binding is by `account_code` (`User::getRouteKeyName()`),
  matching the SPA links.
- Envelope: every method returns `$this->ok(...)`/`$this->error(...)`;
  validation failures render Laravel's standard `{message, errors}` 422 — the
  shape `$this->error()` produces, which the SPA reads. The 404 for
  non-managed roles uses `abort_unless(..., 404)`, the established idiom in
  the sibling admin controllers (`AdminController`, `CustomerController`,
  `StoreModerationController`, …).
- Referenced services/models checked against the controller: `KycApprovalService::
  autoApproveOpenApplication(User, ?User, ?string)`; `ActivityRecorder::record`
  named args (old/new/metadata/actor); `ApiTokenService::issuePair(Model,
  string, Request, array)`; `Impersonation` fillable + `ABILITY_PREFIX`;
  `User::ROLE_*`, `force_password_change`/`email_verified_at` columns and the
  `password => hashed` cast (the temp password is hashed before storage);
  `Business::{stores,warehouses,users,activeSubscription}`;
  `Store/Warehouse::STATUS_DELETED`; `OrderStatus::COMPLETED`;
  `TransactionStatus::CONFIRMED`; Payment columns (`amount` is a naira
  `decimal:2`, so `UserDetailView`'s `formatMoney` is correct — no kobo/naira
  mismatch); `settings.main_store_id` migration exists; refresh tokens live in
  `refresh_tokens`, so `access_token_id = latest token id` really is the
  access token.
- Platform-console guard: `EnsuresPlatformAdmin` (role superadmin/admin) on
  every method, on top of `permission:admin.users` — closes the in-business
  "Super Admin"-role escalation path documented by WS-1/WS-5.
- SPA: the three `.vue` files parse and compile with the installed
  `@vue/compiler-sfc` (script + template), and the four `.ts` modules
  transpile cleanly with the installed `typescript` (isolated syntax check,
  **not** `npm run typecheck`). Imports all resolve; component contracts match
  usage (`StatCard/ SortHeader/TableFooter/TableSkeleton/EmptyState/AppModal/
  ConfirmDialog/StatusBadge` props+emits verified against their definitions;
  `useDataTable`'s fetcher/meta/selection API matches; `ui.success/info/error`
  and `auth.can` exist; management `setTokens` exists).
- SPA calls ↔ routes ↔ module exports: every call in `UsersView.vue` /
  `UserDetailView.vue` (`list, get, update, suspend, activate, verify,
  unverify, resetPassword, destroy, restore, impersonate, stopImpersonation`)
  is exported by `src/api/modules/ad08-user-moderation.ts` and backed by a
  route in the module file with the exact URI/method.
- Hand-off contract verified end-to-end on paper: admin `handoff.encoded` is
  base64url of the pair with the fragment key `impersonation`;
  `managementAppUrl()/#impersonation=<encoded>`; the management parser pads and
  decodes the same base64url; the management banner + `stopImpersonation` call
  already exist in the shared `AppLayout.vue`/`store`/`endpoints.ts` and
  `GET /management/auth/me` reports `impersonator` for the banner.
- Router module: default-exports a child array, path `users/:accountCode` (no
  leading slash), `meta.title` — name `users.show` has no collision; the
  shared router keeps `users` (the detail view's back-link target) intact.
  Nav module: `{label, nodes}` shape the layout globs; mounting note present.
- Pre-existing suites that now exercise the new controller by URI
  (`AdminApiTest` user list/verify, `AuthApiTest` impersonate + stop) were
  read line-by-line against the new behaviour: assertions (`data.0.email`,
  verify flips `is_verified`, `data.user.id`, `impersonation.impersonator.name`,
  management `me`, stop → admin pair usable on `admin/auth/me`) all still hold.

## Fixed (4)

1. **`user_impersonation_stopped` was never written on the management app's
   "Return to Admin"** — the primary exit path, and the one legacy
   `Admin\UserController@stopImpersonate` logged. `Api\V1\Auth\ImpersonationController@stop`
   is an existing controller this workstream cannot edit, but the same
   "re-register the URI from a file I own" pattern the workstream already uses
   for `users/*` closes it: new
   `Api\V1\Management\ImpersonationStopController@stop` +
   `routes/api/v1/management/ad08-impersonation-stop.php` re-register
   `POST management/auth/stop-impersonation` (same name, URI and middleware;
   `management.php` loads after `auth.php`). The response contract the SPA
   consumes is unchanged (admin token pair + `user`), and the transaction now
   writes the audit row exactly as legacy did (actor = impersonating admin,
   subject = impersonated user, `business_id` resolved to the user's business).
   One small hardening: the admin lookup happens *before* the session is
   ended, so a missing impersonator no longer silently closes the session and
   then 404s.
2. **Nav module nodes had no `icon`** while `AdminLayout.vue` renders
   `<i :class="node.icon">` and every sibling nav module
   (`ad01/ad03/ad04/ad09`) ships icons — the section rendered three icon-less
   rows once merged. Added `fi fi-rr-users` / `fi fi-rr-ban` /
   `fi fi-rr-trash`.
3. **`ModerationActionResult.user` was typed as always present** although the
   stop-impersonation response returns only `impersonation` — a consumer
   reading `data.data.user` after a stop would crash. Made optional with a
   comment.
4. **Regression test added**: "the management app stop endpoint writes the
   `user_impersonation_stopped` audit row" — impersonates from the admin
   console, stops via the management URI, asserts the row's actor/subject/
   business/metadata and that the handed-off token is revoked (management
   `me` → 401). Test count is now 16; still not executed here (instruction).

## Unfixable here (needs a file this workstream does not own)

1. **`storify-management/src/components/ImpersonationHandoff.vue` is not
   mounted anywhere.** The admin side is complete (issue → deep link → open),
   the management banner/stop are already wired in the shared files, but the
   inbound consumer that exchanges the `#impersonation=<payload>` fragment and
   reloads must be added as the first child of the root element in both
   `src/layouts/AuthLayout.vue` (fresh-load lands on /login) and
   `src/layouts/AppLayout.vue` (already-open session) — the component's
   top-of-file comment carries the exact mount instructions, as the house
   rules require. `AppLayout.vue` is on the must-not-edit list and both are
   shared layouts the orchestrator wires. Until then, "Login as user" opens
   the management app with the token still in the fragment and no session
   exchange happens.
2. **Group-level `AdminApiActivityLogger` on the whole admin group** is a WS-1
   orchestrator item on the shared `routes/api/v1/admin.php` (not
   ad08-specific). Mitigation already in place: ad08 carries the middleware at
   route level and the per-request attribute de-dupes, so the later group-level
   application writes exactly one row.

## Not verified here (by instruction)

`php artisan test` and `npm run typecheck` were not run — the fleet shares one
test database and one toolchain and the orchestrator runs them serially. The
SPA files were compile-checked and the TS modules syntax-checked with the
installed compiler packages instead, as described above. The two pre-existing
suites that touch `/admin/users` (`AdminApiTest`, `AuthApiTest`) were read
against the new controller and their assertions still hold, but they need the
orchestrator's serial run like every other suite.
