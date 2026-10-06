# WS-20 — Staff & Roles Parity — repair report

Status: **re-verified from the files (not from the previous report); one SPA defect
repaired, one cross-cutting blocker recorded.** This run re-read every WS-20 file
end-to-end and re-ran the seven checks against the running application's route
table. It found and fixed a real parity bug the earlier pass missed: the invite
modal collected the legacy "6-digit POS PIN" but never sent it. Everything else
in the workstream checked out; the items the previous report recorded as outside
this workstream's ownership were re-confirmed, and one new cross-cutting defect
(SPA button gating) is recorded for the orchestrator because it cannot be fixed
from any file WS-20 owns.

`php artisan test` and `npm run typecheck` were **not** run (shared test database
and toolchain). The evidence below comes from `php artisan route:list` (full
route table, middleware stacks, duplicate-name scan), `php -l`, an SFC
compile/parse pass with `@vue/compiler-sfc`, a TS transpile + import-resolution
pass with the repo's `typescript`, and a read-only `artisan tinker` probe of the
permission payload.

## Repaired in place

1. **Invite modal dropped the POS PIN (SPA).**
   `storify-management/src/components/staff/StaffFormModal.vue` renders the POS
   PIN field for both create and edit, and the API's `POST /management/staff`
   validates `pin` (`size:6`, digits), but `submit()` only put `pin` into the
   payload inside the edit branch. On invite the value was silently discarded —
   a direct parity regression against audit A2 ("6-digit numeric POS PIN" on the
   legacy create form, `docs/legacy-parity-audit/mgmt-staff-roles.md` line 45).
   Fixed by sending it in the create branch (a non-empty value only, matching the
   edit branch's idiom):
   `if (form.pin) payload.pin = form.pin` with a one-line comment. The edited SFC
   recompiles clean. (The API side was already correct and is covered by the
   `has_pin` assertion in the test file.)

## The seven checks (evidence)

1. **Route → controller method.** All 14 Route lines resolve to existing public
   methods with matching signatures; `php artisan route:list --path=api/v1/management -v`
   shows each `api.management.staff*` / `roles*` URI pointing at
   `StaffParityController` / `RoleParityController` with the intended permission
   middleware (`staff view|create|edit|suspend|delete`). Implicit-binding names
   match route parameters (`{staff}`→`$staff`, `{role}`→`$role`,
   `{document}`→`$document`); `User::getRouteKeyName()` is `account_code`, which
   is what the SPA sends.

2. **Envelope + tenant scope.** Every method returns `$this->ok(...)` /
   `$this->error(...)` (validation failures render as the app's standard 422
   envelope; foreign/not-found refusals use `abort(404)`, the same idiom as
   `CustomerParityController`, `ProductFormController`, etc.). Reads are scoped
   by `business_id`; mutations re-check the row via
   `authorizeMember`/`authorizeStaffMember`/`authorizeRole`; submitted
   store/warehouse ids are intersected with `accessibleStores()` /
   `accessibleWarehouses()`; `destroyDocument` re-checks `document->user_id`;
   multi-step writes sit in `DB::transaction`. Role create/update/delete are
   business-scoped, and the permission matrix uses `exists:permissions,name`.
   The only global checks are the legacy email-uniqueness rules against
   `users`/`customers`, carried over from the thin controller being replaced.

3. **SPA calls ↔ routes ↔ module exports.** Every call in the five views and the
   modal (`index`, `show`, `options`, `create`, `update` via multipart
   `POST + _method=PUT`, `resendInvite`, `suspend`, `activate`, `destroy`,
   `destroyDocument`, `roles.*`, `invitations.show/accept`) is exported by
   `src/api/modules/ws20-staff-roles.ts` and maps to a live route, including the
   public `GET/POST /management/invitations/{token}` pair from
   `routes/api/v1/auth.php`. Response fields the views read (`account_code`,
   `is_owner`, `meta.stats`, `meta.store`, `accepted_at`, `has_pin`, `protected`,
   `documents[].formatted_size/extension/url`, invitation `{email,name,business}`
   and `{access_token,refresh_token,user}`) all exist on the controllers'
   payloads; `AuthUser.roles/stores[].pos_enabled` exist for the cashier
   redirect. `posAppUrl` is exported by `ws17-pos-oversight`.

4. **Route-module hygiene + duplicate names.** The module declares no prefix and
   no auth/audience/team wrapper — it inherits them from the parent group and
   only adds `permission:` groups, matching the sibling modules. It re-registers
   the same method+URI as the thin base controller (loaded first in
   `routes/api/v1/management.php`), which replaces it: the parent's inline
   registrations are overwritten by Laravel's method+URI route key because the
   `glob()` loop runs after them. Confirmed against the full table — a scan of
   all **1,107 routes found 0 duplicate names**, and each `api/v1/management/staff*`
   URI resolves to exactly one route. The new names (`staff.options`,
   `staff.resend-invite`, `staff.documents.destroy`) exist nowhere else.
   `staff-options` (hyphen) avoids the `staff/{staff}` wildcard entirely.

5. **Router/nav modules.** `src/router/modules/ws20-staff-roles.ts`
   default-exports `RouteRecordRaw[]` with relative child paths (`staff/:id`,
   `invitations/:token`), `meta.title` on both, and unique names
   (`staff.show`, `staff.invitations.accept`); no path collides with any other
   module. `requiresAuth: false` on the invitation record overrides the
   authenticated shell in vue-router's merged meta, and `AppLayout.vue` has no
   mount-time redirect, so the full-screen view renders for a visitor with no
   session. `src/nav/modules/ws20-staff-roles.ts` default-exports an empty
   `NavGroup[]` — correct, because `AppLayout.vue` already renders the Team →
   Staff/Roles items (verified at lines 74–81); the A14 badge/widget belongs to
   WS-28/WS-34.

6. **Imports resolve.** All PHP `use` statements resolve (route table boots,
   `php -l` clean); every `@/` and relative import in the eight SPA files
   resolves to an existing file (scripted resolve pass), both shared stores and
   all shared components used (`AppModal`, `PasswordInput`, `StatusBadge`,
   `PaginationBar`, `StatCard`) expose the props/events the views pass, and the
   five SFCs compile script+template with `@vue/compiler-sfc`. The three TS
   modules parse clean under `tsc`.

7. **PHP syntax.** `php -l` clean on all five PHP files (two controllers, route
   module, mailable, test).

The test file was re-verified statically against its dependencies: the token
helper matches the repo convention and `EnsureTokenAudience`; `createBusinessOwner`
seeds Cashier/Manager/Auditor/Super Admin/Developer/Store Associate per business;
`Store`/`Warehouse`/`PosSession` generate their codes/stamps on create;
`Request::has` + `ConvertEmptyStringsToNull` give `pin: ''` the clear semantics
the test asserts; `_method` spoofing is enabled by the framework kernel and used
by other suites; no global test-helper name collisions.

## Recorded as unfixable from this workstream (orchestrator action)

1. **BLOCKER (new): every permission-gated SPA control renders hidden for
   role-based users — including the business owner.**
   `App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses.php:59`
   sends `permissions` from `$user->getPermissionNames()` (direct grants only).
   The management SPA gates every action with `auth.can()` reading that array
   (`src/stores/auth.ts:20-23`). Read-only probe of the dev database
   (`artisan tinker`, owner `tony@demo.com`, Super Admin role): direct
   permissions **0**, role permissions **109**, `can('staff create')` **true**.
   So the API enforces correctly while the SPA's buttons — Invite Staff, Edit,
   Resend Invite, Suspend/Activate, Remove, Create/Edit/Delete Role, all in
   WS-20's views — never render. This is fleet-wide (59 SPA files call
   `auth.can(...)`), not WS-20-specific, and the fix belongs in the shared
   auth concern WS-20 must not edit: use
   `$user->getAllPermissions()->pluck('name')` (Spatie,
   `app/Http/Controllers/Api/V1/Auth/Concerns/BuildsAuthResponses.php:59`).
   Until then, WS-20's UI — and the acceptance criterion "promoting a cashier to
   manager works via the UI" — cannot be exercised end-to-end, although the
   underlying API works and is test-covered.

2. **Invitation error states stay collapsed.** `InvitationController@showStaff`/
   `acceptStaff` return the same 404 for "invalid or expired" and "already
   accepted" (verify doc correction note). Differentiating them means editing
   `Api\V1\Auth\InvitationController` and/or `routes/api/v1/auth.php`, neither
   of which this workstream owns. The SPA renders whatever message the API
   returns.

3. **Legacy Blade invites still link at the legacy page.** New-stack
   invite/resend traffic uses `StaffInvitationSpaMail` (`/invitations/{token}`
   on the SPA), but the still-mounted legacy
   `App\Http\Controllers\Management\StaffController` queues the original
   `StaffInvitationMail`, whose link targets `management.staff.invitation.accept`.
   Folding the SPA link into `StaffInvitationMail` means editing a shared
   mailable used by the legacy stack, or retiring the legacy controller — both
   orchestrator calls.

## Non-blocking observations (no action taken)

- `User::create([...])` in `store()` passes `email_verified_at`, which is not in
  `User::$fillable` and is silently discarded (no strict model mode is enabled).
  Pre-existing behaviour shared with the thin controller it replaces; `is_verified`
  is fillable and is what the onboarding middleware checks.
- `suspend()`/`destroy()` flip `status` only; already-issued Sanctum tokens stay
  valid until expiry. Login is blocked (parity with legacy, which also gated at
  login only), and the admin user controller has the same behaviour — noted, not
  changed, to avoid diverging from the fleet idiom.
- `detail()['permissions']` uses `getPermissionNames()` (direct grants only).
  It matches the replaced controller and no WS-20 screen displays it; the
  payload-level fix in unfixable item 1 should be applied consistently if the
  field is ever surfaced.
