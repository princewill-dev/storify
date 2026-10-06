# Verification pass — mgmt-staff-roles (audience: business)

**Verifier method:** re-read every in-scope legacy route (`routes/v1/management.php` staff/roles/store-assignment/profile/dashboard/search blocks, `routes/v1/staff.php`, `routes/v1/admin_dashboard.php` users/admins blocks, `routes/v1/admin_auth.php` invite/stop-impersonation), all in-scope legacy controllers (`Management\{Staff,Role,Profile,PasswordChange,StoreSettings,StoreTab,Warehouse,Dashboard,Search}Controller`, `Staff\{Invitation,Pos}`Controller, `Admin\{User,Admins,AdminInvitation}`Controller), all seven staff/roles Blade views line-by-line, plus `staff/auth/accept-invitation`, `admin/users/{index,show}`, `admin/admins/index`, the store/warehouse staff-assignment views, `SpatiePermissionSeeder`, `CreateStore` action, `RedirectIfOnboardingIncomplete`, `StaffInvitationMail`/`AdminInvitationMail`, the new API (`routes/api/v1/{management,admin,auth}.php`, `Api\V1\Management\{Staff,Role}Controller`, `Api\V1\Admin\UserController`, `Api\V1\Auth\{Invitation,Impersonation,ManagementAuth}Controller`, `BuildsAuthResponses`), and both SPAs (`storify-management` router/endpoints/StaffView/RolesView/ProfileView/auth store/AppLayout; `storify-admin` router/endpoints/UsersView/AdminLayout).

**Coverage verdict:** the audit is substantively complete — all 7 in-scope Blade views, every in-scope staff/role/invitation/password route, and the admin user/admins surface map to feature entries, and the expensive error direction is clean (no "missing" status hides an existing endpoint; no "exists" status covers a purely stubbed new controller). What it gets wrong is a handful of specifics, two of which matter operationally: the SPA is targeting a non-existent `next_step` field for the forced-password-change flow, and impersonation is presented as available when neither SPA wires it. One legacy staff page (the staff landing dashboard) is orphaned across the whole audit set and is flagged below.

---

## Missed feature

### A. Staff landing dashboard (`GET /staff`) — `missing` / `missing`

- **What the user could do (legacy):** staff users landing on `/staff` got a hub page: a pure-`Cashier` with a POS-enabled assigned store was redirected to the POS; everyone else saw today's POS sales total and order count for their assigned stores, a card for their open POS session (store name or "None"), the 8 most recent POS orders with items/payment status, and a grid of permission-gated module tiles (POS, Stores, Products, Orders, Team, Warehouses, Transactions, Customers, Reports, Deliveries, Settings, Coupons, Support) — each tile carrying a warning when no store / no POS-enabled store is assigned.
- **Legacy route:** `GET /staff` (`staff.dashboard`) — `routes/v1/staff.php`
- **Legacy controller:** `Staff\DashboardController@index` (+ `buildModules()` / `module()`)
- **Legacy views:** `resources/views/staff/dashboard.blade.php`
- **Key UI:** 3 stat cards (today's sales, today's orders, active session), recent POS activity list, module tile grid; Cashier redirect shortcut.
- **Side effects:** none (read-only).
- **New API:** none — no endpoint under any audience serves a staff home; `nextStep()` in `BuildsAuthResponses` returns `dashboard` for staff and the management SPA has no staff-specific landing view.
- **New SPA:** none.
- **Effort:** S — **Priority:** P2
- **Caveat:** this page straddles the staff-app/POS boundary and falls outside the audit's declared view list (`management/staff|roles`); it is flagged here because no audit in `.legacy-gap-audit/` covers `Staff\DashboardController` at all (only `Management\DashboardController` is covered, by `mgmt-account-misc.md`).

---

## Corrections

### 1. §22 Forced password change — the API field is `next`, not `next_step`

The audit says the SPA must consume `next_step`. That key exists nowhere in the new stack. `BuildsAuthResponses::nextStep()` (`app/Http/Controllers/Api/V1/Auth/Concerns/BuildsAuthResponses.php:135-159`) returns `change_password` when `force_password_change` is set, and the **login / verify-otp / reset-password responses expose it as `'next'`** (ManagementAuthController lines 93, 159, 227). `storify-management/src/stores/auth.ts` already stores it (`next.value = data.data.next`, lines 35 and 47) but no view, route or guard reads `auth.next` — and there is no `/change-password` route or view. Build against `auth.next`/`'next'`, and note `me` does **not** return it (only `force_password_change`). As written the audit sends the implementer looking for the wrong identifier.

### 2. §27 — impersonation is not usable end-to-end in the new stack

The audit's New cell reads as if impersonation works ("impersonation via `POST /admin/users/{user}/impersonate` (auth.php)") and its Missing list omits the wiring gap. Reality: `ImpersonationController@impersonate` creates the record and returns a **management-audience token pair**, but `storify-admin/src/views/UsersView.vue:87-93` only shows a toast — it never stores the pair or switches apps — and `storify-management` declares `stopImpersonation` in `src/api/endpoints.ts:48` with no caller, no impersonation banner and no "Return to Admin" control (legacy has the banner at `management/layout.blade.php:42-57`). So legacy "Login as user" is a dead end from both consoles. Status should read API **partial** / SPA **partial (broken)** with the hand-off/hand-back listed as missing. (Fully specced in `admin-users-admins.md` §10 — cross-reference rather than re-deriving.)

### 3. §1 "Missing" list contains two items legacy never had

`staff/index.blade.php` has **no role filter** (it has no filter form at all — the only filter is the `?store_id=` query string applied via URL) and **no warehouses-per-row column** (its columns are Name, Email, Role, Status, actions; the "N store(s), M WH(s)" summary lives only in `management/stores/tabs/staff.blade.php`). Remove "role filter" and "warehouses-per-row column" from the parity gap list so nobody builds non-parity work; the genuine gaps in that entry are the store filter, the owner row + Owner badge, the View link and the Resend Invite action.

### 4. §5 "Missing: documents and permission list display" — neither was on the legacy show page

`staff/show.blade.php` (113 lines) renders Status, Staff since, Email, Phone, Invitation Sent, Invitation Accepted, Last Login, Roles chips, Assigned Locations and the photo card — **no documents section** (documents are edit-page only, §8) and **no permission list**. The new API `show` payload already returns `permissions` in the detailed branch. The real gaps for #5 are the SPA profile screen and `accepted_at` in the payload; drop the two invented display items.

### 5. §27 — the business column **is** in the new drawer

The audit lists "plan and business columns in the drawer" as missing. The drawer renders the business name and the accessible-store chips (`UsersView.vue` business block + stores list). What is actually missing versus legacy is the **plan/trial** info (legacy index had a per-row Plan column and the SPA has none anywhere) and the **business_code** under the name.

### 6. §27 legacy description under-states the guards and side effects

`Admin\UserController` as shipped in legacy also: blocks suspend for the user who owns the main store (`ownsMainStore()`, settings `main_store_id`); requires a **reason for activate too** and auto-approves a pending KYC application on activate; queues `BusinessSuspended` / `BusinessReactivated` mails; blocks delete while the user's stores have incomplete orders or non-confirmed transactions; and writes an `ActivityLog` row (`user_suspended|user_activated|user_verified|user_unverified|user_password_reset|user_deleted|user_restored|user_impersonated|user_impersonation_stopped`) on **every** action. The new API does none of the mails/audit writes and its suspend/activate have no owner guard — worth stating in the entry's side effects so the port doesn't silently drop them.

### 7. §20 permission catalog — the counts are wrong

The audit says "22 groups, ~64 permissions". `SpatiePermissionSeeder.php` defines **18 business groups / 89 "ability action" permissions** (lines 14-33) plus **20 `admin.*` permissions** (lines 35-55) = 109 created permissions. Because `admin.*` names contain no space, both stacks render each of them as its own one-item group (legacy `explode(' ', …)` groupBy, new SPA `split(' ')[0]`) — so the matrix the user sees is ~38 groups, not 22. The "filter the admin.* noise" conclusion stands, but it is 20 stray groups per role form, not one.

---

## Smaller notes (no entry rewrite demanded)

- **Store creation has a third staff-assignment surface** the audit's §12 does not enumerate: `management/stores/create.blade.php` lines 117-134 has an optional "Assign Staff" select (`staff_ids[]`), and `CreateStore` syncs `assignedStaff` inside the create transaction (validating each id belongs to the business and has `role=staff`, else `DomainException`). This is already covered by `mgmt-stores.md` §1.3/§1.5 — connect the two when the store-create screen is built so the selector is not forgotten; no new work item needed.
- **Staff invitation error states were collapsed:** legacy distinguishes "invalid or expired" from "already accepted" (warning redirect); the new API returns the same 404 message for both (`InvitationController@showStaff`/`acceptStaff`). Minor parity/UX note for §4.
- **§9 is a net-new capability, not just "nothing material":** the SPA's Activate button shows for any status ≠ `active`, so an `invited` user can be force-activated without accepting (legacy only offered Activate for `suspended`), and the edit modal offers `invited` in its status select (legacy had no status field at all). Also note the SPA's suspend/activate buttons are hidden only by permission, while the legacy kebab conditioned on status.
- **Validation looseness beyond the two gaps the audit names:** new `store_ids.*` / `warehouse_ids.*` are validated as `integer` only (legacy `exists:stores,id` / `exists:warehouses,id`); the controller intersects them with accessible ids first, so bad ids are silently dropped rather than rejected — no 500 risk, but no validation message either.
- **Audit's D-sectional double-count flag acknowledged:** items 25-27 overlap `admin-users-admins.md`; the only field that file's coverage does not already fix in this one is the impersonation status in correction 2 above.
