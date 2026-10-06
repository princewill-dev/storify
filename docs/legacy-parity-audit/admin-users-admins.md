# Legacy gap audit — admin-users-admins (audience: admin)

Scope: platform user directory (business owners + staff), user detail, moderation (suspend / activate /
verify / unverify / delete / restore), admin-generated password resets, impersonation, admin account CRUD,
admin invitations, platform-wide customer management, and the audit trail.

Legacy source of truth: `storify-api` Blade app — `routes/v1/admin_dashboard.php`, `routes/v1/admin_auth.php`,
`app/Http/Controllers/Admin/{UserController,AdminsController,AdminInvitationController,CustomerController,ActivityLogController}.php`,
views under `resources/views/admin/{users,admins,customers,activity_logs,auth}`.

New stack: `routes/api/v1/admin.php`, `routes/api/v1/auth.php`,
`app/Http/Controllers/Api/V1/Admin/UserController.php`, `Api/V1/Auth/{ImpersonationController,InvitationController}.php`,
and the admin SPA `storify-admin` (`src/router/index.ts`, `src/views/UsersView.vue`, `src/api/endpoints.ts`).

Status vocabulary: **exists** = fully usable in the new stack; **partial** = endpoint or screen present but
missing actions/fields/validations from legacy; **missing** = absent from the new stack.

Key context: the legacy Blade admin (`/office/*`) is still mounted in the same repo (`routes/web.php` requires
`routes/v1/*`), so these screens remain reachable from the old app while the new admin SPA only exposes
Dashboard, Businesses, Stores, Users, Transactions and Coupons. There is **no admin API or SPA screen for
admins, customers, activity logs, or the user password/delete/restore/impersonation-completion actions.**

---

## A. Platform users (business owners + staff)

### 1. Platform user directory (filters, stats, search, pagination)
- **What the admin could do (legacy):** open `GET /office/users` and see every user with `role IN
  (business_owner, staff)`, defaulting to owners. Filters: role (Owners / Staff / All roles), status
  (active / suspended / deleted), verified (yes / no), **has_business** (yes / no),
  **subscription** (active plan / on trial / no plan), and free-text `q` matching name, email, phone and
  `account_code`. Stat pills above the table: owners, staff, suspended, unverified. Columns: User (name,
  email, account code), Business (name + business_code, or "No business"), Role, Status, Verified, **Plan**
  (active plan name / Trial / None), Last Login, Actions. Paginated 20 per page with query string preserved;
  empty state; "Clear" link when any filter is set. Row kebab: View, Edit, Activate (when suspended),
  Suspend, Login as user (permission-gated), Restore (when deleted), Delete.
- **Legacy:** `GET /office/users` — `Admin\UserController@index` — `resources/views/admin/users/index.blade.php`
- **New:** API `GET /api/v1/admin/users` (`Api\V1\Admin\UserController@index`) → SPA `/users`
  (`storify-admin/src/views/UsersView.vue`)
- **Status:** API **partial**, SPA **partial**
- **Missing:** `has_business` filter; `subscription`/plan filter; the per-row **Plan** column; the stat block
  (owners / staff / suspended / unverified counts); Business code under the business name; account code in the
  row (it only appears in the drawer subtitle); `deleted` is not selectable in the SPA status filter even
  though the API would accept it. The new stack **adds** server-side sorting (name/email/role/status/
  last_login_at/created_at), column selection, group select + bulk actions and a per-page control, which
  legacy did not have.
- **Effort:** S — **Priority:** P1

### 2. User detail console
- **What the admin could do (legacy):** `GET /office/users/{user}` rendered a full console: header with role
  / status / verified badges, email and account code; action bar (Edit, Verify/Unverify, Reset Password,
  Activate/Suspend, Restore, Login as user, Back);
  - **Account card** — email, phone, location, last login, **last IP**, joined date, "Change required" badge
    when `force_password_change`;
  - **Business card** — business name, code, stores / warehouses / team counts, order count, and the list of
    the user's stores with per-store status badges (or "has not set up a business yet");
  - **Subscription card** — active plan name + expiry, or trial with end date, plus the **last 10 payments**
    (reference, amount, status, date);
  - **Recent Activity** — last 25 ActivityLog rows (description, action, IP, relative time).
- **Legacy:** `GET /office/users/{user}` — `Admin\UserController@show` — `resources/views/admin/users/show.blade.php`
- **New:** API `GET /api/v1/admin/users/{account_code}` (`show`) → user fields + `stores` (accessible stores)
  only, surfaced in a `DetailDrawer` inside `UsersView.vue`
- **Status:** API **partial**, SPA **partial**
- **Missing:** location; last login IP; joined date; `force_password_change` indicator; the whole business
  block (business code, stores/warehouses/team/orders counts and per-store status); subscription plan +
  trial state; the payments table; the activity feed. There is also no dedicated, linkable detail route —
  everything lives in a drawer, so admins cannot share or bookmark a user.
- **Effort:** M — **Priority:** P1

### 3. Edit user (name / email / phone)
- **What the admin could do (legacy):** edit name (required), email (required, unique against `users`) and
  phone (nullable) from the list kebab modal or the detail modal; every change wrote a `user_updated`
  ActivityLog row with old and new values.
- **Legacy:** `PUT /office/users/{user}` — `Admin\UserController@update` —
  `admin/users/index.blade.php`, `admin/users/show.blade.php`
- **New:** API `PUT /api/v1/admin/users/{account_code}` → edit form in the `UsersView.vue` drawer
- **Status:** API **exists**, SPA **exists**
- **Missing:** the audit-log entry (see feature 11). The API validates name/email as `sometimes` where legacy
  required them — a client sending only `phone` will succeed silently. No field-level error surfacing in the
  drawer (errors surface as a toast).
- **Effort:** S — **Priority:** P2

### 4. Suspend user (with reason + notification)
- **What the admin could do (legacy):** suspend a user from the list or detail; the reason was **required**
  (max 2000 chars) and was emailed to the user via `BusinessSuspended`; the action was refused when the user
  owns the platform main store (`Setting::value('main_store_id')`); suspending an already-suspended user
  returned a warning instead of acting; an ActivityLog row (`user_suspended`) captured old/new status plus
  reason.
- **Legacy:** `POST /office/users/{user}/suspend` — `Admin\UserController@suspend` —
  `admin/users/index.blade.php` (modal), `admin/users/show.blade.php` (modal)
- **New:** API `POST /api/v1/admin/users/{account_code}/suspend` → SPA single suspend from the drawer footer
  and **bulk** suspend via `Promise.all` over selected rows, with an optional reason
- **Status:** API **partial**, SPA **partial**
- **Missing:** required reason (the new modal literally says "A reason is optional"); main-store-owner
  protection; the suspension email; the "already suspended" guard; the ActivityLog entry. The new bulk action
  is an addition over legacy but fires N parallel calls with no per-row result reporting.
- **Effort:** M — **Priority:** P1

### 5. Activate user (with reason, KYC auto-approval, notification)
- **What the admin could do (legacy):** activate a suspended/deleted user with a required reason; activation
  **auto-approved a pending KYC application** (status `approved`, `reviewed_by` = admin, `reviewed_at`,
  reviewer note "Auto-approved during user activation: {reason}"); queued `BusinessReactivated` to the user;
  wrote a `user_activated` ActivityLog with old/new status and reason.
- **Legacy:** `POST /office/users/{user}/activate` — `Admin\UserController@activate` —
  `admin/users/index.blade.php`, `admin/users/show.blade.php`
- **New:** API `POST /api/v1/admin/users/{account_code}/activate` → SPA drawer "Activate" + bulk activate
- **Status:** API **partial**, SPA **partial**
- **Missing:** reason capture, KYC auto-approval side effect, reactivation email, audit log entry. New SPA
  hardcodes no reason at all.
- **Effort:** S/M — **Priority:** P1

### 6. Verify / unverify user
- **What the admin could do (legacy):** mark a user verified (sets `is_verified = true` and
  `email_verified_at = now()`) or remove verification (both back to false/null) from the detail page.
- **Legacy:** `POST /office/users/{user}/verify`, `POST /office/users/{user}/unverify` —
  `Admin\UserController@verify/unverify` — `admin/users/show.blade.php`
- **New:** API `POST /api/v1/admin/users/{account_code}/verify` and `/unverify` → SPA drawer buttons plus
  bulk verify/unverify
- **Status:** API **exists**, SPA **exists**
- **Missing:** only the audit-log entry (feature 11); behaviour otherwise matches legacy, and the new SPA
  adds bulk.
- **Effort:** S — **Priority:** P2

### 7. Admin-generated password reset (temporary password by email)
- **What the admin could do (legacy):** click "Reset Password" and have the system generate a temporary
  password (`XXXX-xxxx-NNNN`), set it on the user with `force_password_change = true`, and queue
  `UserPasswordResetMail` with that temp password. If mail queuing failed, the temp password was flashed back
  into the admin UI so the admin could still hand it over. Wrote a `user_password_reset` ActivityLog.
- **Legacy:** `POST /office/users/{user}/reset-password` — `Admin\UserController@resetPassword` —
  `admin/users/show.blade.php` (reset modal)
- **New:** none — no API route, no SPA action
- **Status:** API **missing**, SPA **missing**
- **Missing:** everything. There is currently **no way for an admin to help a locked-out user** in the new
  stack (users can self-serve via the app's own forgot-password, but support-driven resets are gone).
- **Effort:** S — **Priority:** P1

### 8. Delete user (guarded soft delete)
- **What the admin could do (legacy):** delete a user (sets `status = 'deleted'`, the account is disabled but
  data retained). Deletion was refused when the user owns the main store, and refused when any of the user's
  stores has orders not in `completed` status or transactions not in `confirmed` status — with a specific
  error message naming the reason. Wrote a `user_deleted` ActivityLog and redirected to the list.
- **Legacy:** `DELETE /office/users/{user}` — `Admin\UserController@destroy` —
  `admin/users/index.blade.php`, `admin/users/show.blade.php`
- **New:** none — no API route, no SPA action
- **Status:** API **missing**, SPA **missing**
- **Missing:** everything, including the main-store and open-orders/open-transactions guards (deleting these
  guards silently when re-implementing would let an admin disable a business mid-fulfilment).
- **Effort:** S/M — **Priority:** P1

### 9. Restore deleted user
- **What the admin could do (legacy):** restore a `deleted` user back to `active` from the list kebab or the
  detail page; wrote a `user_restored` ActivityLog.
- **Legacy:** `POST /office/users/{user}/restore` — `Admin\UserController@restore` —
  `admin/users/index.blade.php`, `admin/users/show.blade.php`
- **New:** none
- **Status:** API **missing**, SPA **missing**
- **Missing:** everything. Delete (when built) would be a one-way door without it.
- **Effort:** S — **Priority:** P2

### 10. Impersonation — "Login as user" and "Return to admin"
- **What the admin could do (legacy):** with permission `admin.users.impersonate`, click "Login as user" to be
  logged into the user's browser session (`Auth::login`, session regenerated, `impersonator_id` /
  `impersonated_user_id` stored) and dropped on the management dashboard with "You are now viewing as X".
  Guards: cannot impersonate yourself, admin accounts, or deleted users. Every start wrote a
  `user_impersonated` log; `POST /office/impersonate/stop` (route `admin.impersonate.stop`) restored the admin
  session (or logged out if the impersonator was gone), logged `user_impersonation_stopped`, and returned the
  admin to the user's detail page.
- **Legacy:** `POST /office/users/{user}/impersonate` — `Admin\UserController@impersonate`;
  `POST /office/impersonate/stop` — `Admin\UserController@stopImpersonate` — `admin/users/index.blade.php`,
  `admin/users/show.blade.php`
- **New:** API `POST /api/v1/admin/users/{account_code}/impersonate` (`Api\V1\Auth\ImpersonationController`)
  creates an `Impersonation` record and issues a **management** token pair; `POST
  /api/v1/management/auth/stop-impersonation` ends it and issues an admin pair. The admin SPA calls
  impersonate and only shows the returned message — it never stores the returned tokens or switches apps —
  and the management SPA declares `stopImpersonation` in `endpoints.ts` but nothing calls it (no banner, no
  "Return to admin" control).
- **Status:** API **partial**, SPA **partial** (end-to-end flow is effectively broken)
- **Missing:** the token hand-off / app switch in the admin SPA (or a deep link into the management app
  carrying the impersonation tokens); an impersonation banner and exit control in the management SPA; the
  same guard for `admin` accounts that legacy had (the API 404s non owner/staff, which covers it); ActivityLog
  entries on both start and stop (only `Log::info` today).
- **Effort:** M — **Priority:** P1

### 11. Admin audit trail for user actions
- **What the admin could do (legacy):** every user action wrote an `ActivityLog` row with actor, business,
  action name, subject type/id, human description, `old_values` / `new_values`, IP and user agent:
  `user_updated`, `user_suspended`, `user_activated`, `user_verified`, `user_unverified`,
  `user_password_reset`, `user_deleted`, `user_restored`, `user_impersonated`,
  `user_impersonation_stopped`. These rows power the user detail activity feed and the platform activity log
  browser.
- **Legacy:** `Admin\UserController::log()` + `ActivityLogger` service used across `Admin\*`
- **New:** none — no `ActivityLog` writes anywhere under `app/Http/Controllers/Api/V1/**`, and no equivalent
  of the legacy `AdminRouteActivityLogger` middleware (only `Log::info` lines that are not queryable in the
  product).
- **Status:** API **missing**, SPA **missing**
- **Missing:** everything. This is the root cause of the "missing audit" note that repeats across features
  2–10 and 19–23 — building the writes once fixes them all.
- **Effort:** M — **Priority:** P1

---

## B. Staff accounts

### 12. Staff accounts inside the platform user directory
- **What the admin could do (legacy):** filter the user list to `role = staff`, see a staff member's business
  and last login, open their detail, and suspend / activate / verify / impersonate them exactly like an
  owner. (Creation and role assignment for staff is a *business* action — `Management\StaffController`,
  audited separately in `mgmt-staff-roles.md` — not an admin one.)
- **Legacy:** `Admin\UserController@index/show` with `role=staff` — `admin/users/index.blade.php`,
  `admin/users/show.blade.php`
- **New:** API `GET /api/v1/admin/users?role=staff` and `GET /api/v1/admin/users/{account_code}` include staff;
  the SPA role filter offers Staff and the drawer shows a "Staff" chip and accessible-store chips
- **Status:** API **partial**, SPA **partial**
- **Missing:** everything already listed for features 2, 4, 5, 7, 8, 9 and 10 as they apply to a staff row —
  notably no reset-password, delete or restore for staff accounts, and no warehouse assignment context in the
  detail (legacy loaded `assignedStores`; the API exposes `accessibleStores` only).
- **Effort:** S (on top of A) — **Priority:** P1

---

## C. Admin (platform staff) accounts and invitations

### 13. Admin account list
- **What the admin (superadmin) could do (legacy):** `GET /office/admins` (permission `admin.admins`) listed
  every user with `role IN (superadmin, admin)` ordered newest first, with their Spatie roles. Columns: Admin
  (initial avatar, name or "Pending setup", email), Role (Super Admin badge, or the assigned platform role
  name), Status (Pending `invited` / Active), Invited date, Actions (Resend when pending; role select +
  Remove for non-superadmins). "Invite Admin" button opens the invite modal. Empty state "No admins yet".
  The role dropdown is populated with platform roles only (`business_id` null, guard `web`, excluding
  Super Admin).
- **Legacy:** `GET /office/admins` — `Admin\AdminsController@index` — `resources/views/admin/admins/index.blade.php`
- **New:** none — `api/v1/admin.php` has no admins route and the admin SPA router has no admins route/view
- **Status:** API **missing**, SPA **missing**
- **Missing:** everything; the `admin.admins` permission is seeded but grants nothing in the new stack.
- **Effort:** M — **Priority:** P1

### 14. Invite an admin
- **What the admin could do (legacy):** from the modal, enter an email (unique across `users` **and**
  `customers`) and pick a platform role, then send. The system created a `role = admin` user with
  `status = invited`, a 64-char `invitation_token`, `invited_at`, a random unusable password and
  `force_password_change`, assigned the Spatie role under team id `null`, queued `AdminInvitationMail`, and
  wrote an `admin.invited` log entry. Invalid roles and mail failures were handled without breaking the flow.
- **Legacy:** `POST /office/admins` — `Admin\AdminsController@store` — `admin/admins/index.blade.php`
- **New:** none for creation. The **accept** half exists: `GET/POST /api/v1/admin/invitations/{token}`
  (`Api\V1\Auth\InvitationController@showAdmin/acceptAdmin`).
- **Status:** API **missing**, SPA **missing**
- **Missing:** create + email dispatch + role assignment + the `unique:customers,email` cross-check. Without
  it the seeded permission and the already-built accept endpoint are unreachable in-product.
- **Effort:** M — **Priority:** P1

### 15. Resend an admin invitation
- **What the admin could do (legacy):** resend from the row when `status === 'invited'` — rotates the token,
  refreshes `invited_at`, re-queues the invitation mail; an already-accepted admin returns a warning.
- **Legacy:** `POST /office/admins/{admin}/resend` — `Admin\AdminsController@resend` — `admin/admins/index.blade.php`
- **New:** none
- **Status:** API **missing**, SPA **missing**
- **Effort:** S — **Priority:** P1

### 16. Change an admin's role
- **What the admin could do (legacy):** change a platform admin's Spatie role inline from the row select.
  Guarded: cannot change your own role, cannot change the superadmin. Validates the role exists as a platform
  role, then `syncRoles`. Wrote an `admin.role_changed` log entry.
- **Legacy:** `PUT /office/admins/{admin}` — `Admin\AdminsController@update` — `admin/admins/index.blade.php`
- **New:** none
- **Status:** API **missing**, SPA **missing**
- **Effort:** S — **Priority:** P1

### 17. Remove an admin
- **What the admin could do (legacy):** remove a platform admin (hard delete of the user row) from the row
  action, guarded against removing yourself or the superadmin; wrote an `admin.removed` log entry.
- **Legacy:** `DELETE /office/admins/{admin}` — `Admin\AdminsController@destroy` — `admin/admins/index.blade.php`
- **New:** none
- **Status:** API **missing**, SPA **missing**
- **Effort:** S — **Priority:** P1

### 18. Accept an admin invitation (public page)
- **What the admin could do (legacy):** open the emailed `/office/invite/{token}` link; invalid/expired tokens
  and already-accepted invitations redirect to the admin login with an explanatory flash; a valid token
  renders `admin/auth/accept-invitation.blade.php` where the invitee sets name + password (min 8, confirmed),
  which activates the account (`status = active`, `is_verified = true`, token cleared, `accepted_at`), logs
  them in and lands them on the admin dashboard.
- **Legacy:** `GET/POST /office/invite/{token}` — `Admin\AdminInvitationController@showAccept/accept` —
  `resources/views/admin/auth/accept-invitation.blade.php`
- **New:** API `GET/POST /api/v1/admin/invitations/{token}` (`showAdmin` returns only `{email}`; `acceptAdmin`
  sets the same fields plus issues a token pair) — **no SPA route or view consumes it** (`storify-admin`
  router has only setup / login / verify-otp / forgot-password / reset-password)
- **Status:** API **exists**, SPA **missing**
- **Missing:** the accept-invitation screen and route in the admin SPA, plus the "already accepted" branch,
  which the API collapses into the same 404 as an invalid token (`showAdmin`/`acceptAdmin` return
  `invalid or has expired` when `status !== 'invited'`, where legacy distinguished the two).
- **Effort:** S/M — **Priority:** P1

---

## D. Platform-wide customer management

### 19. Customer directory (stats, search, status + country filters, sorting, pagination)
- **What the admin could do (legacy):** `GET /office/customers` showed 4 stat cards (Total Customers, Active,
  Suspended, Total Orders — a `this_month` count is computed but unused by the view), a filter modal with
  Search (first/last name, email, phone, `account_id`), Status (ACTIVE / SUSPENDED / DELETED) and **Country**
  (distinct countries derived from customers' delivery addresses joined to delivery routes), plus
  `sort_by` / `sort_order` and 20-per-page pagination with a "Filters Active" chip and "Clear Filters". Rows:
  initials avatar + full name + company + account id, email, phone, location, orders count, status badge,
  joined date, and a kebab with View / Edit / Suspend (when active) / Activate (when suspended).
- **Legacy:** `GET /office/customers` — `Admin\CustomerController@index` —
  `resources/views/admin/customers/index.blade.php`
- **New:** none on the admin side. (`GET /api/v1/management/customers` + `storify-management` CustomersView is
  **business-scoped** — `where('business_id', ...)` — and is audited in `mgmt-customers.md`; it cannot serve as
  the platform console.)
- **Status:** API **missing**, SPA **missing**
- **Missing:** everything, including the country filter and country derivation.
- **Effort:** M — **Priority:** P1

### 20. Customer detail (orders, transactions, activity, addresses)
- **What the admin could do (legacy):** `GET /office/customers/{customer}` showed 4 stat cards (Total Orders,
  Completed, Total Spent over confirmed transactions, Pending), Customer Information (name, status, email,
  phone, company, member since), Address Information (street, apartment, city, state, zip, country),
  Recent Orders (order number linking to the admin order screen, store, item count, total, status, date — last
  10) and Recent Transactions (reference, payment method, amount, status, date — last 10), plus an Activity Log
  feed of the last 20 customer-subject log rows with the acting user.
- **Legacy:** `GET /office/customers/{customer}` — `Admin\CustomerController@show` —
  `resources/views/admin/customers/show.blade.php`
- **New:** none
- **Status:** API **missing**, SPA **missing**
- **Effort:** M — **Priority:** P1

### 21. Edit a customer
- **What the admin could do (legacy):** `GET /office/customers/{customer}/edit` + `PUT` to change first name,
  last name, email (unique), phone, location and status (ACTIVE / SUSPENDED / DELETED). Setting ACTIVE marked
  the email verified; any other status **cleared `email_verified_at`** (i.e. status changes double as account
  enable/disable). Redirected to the customer detail with a success flash. The edit page also showed the
  account summary (total orders, verified email, last updated) and last login.
- **Legacy:** `GET /office/customers/{customer}/edit`, `PUT /office/customers/{customer}` —
  `Admin\CustomerController@edit/update` — `resources/views/admin/customers/edit.blade.php`
- **New:** none
- **Status:** API **missing**, SPA **missing**
- **Effort:** M — **Priority:** P1

### 22. Suspend a customer
- **What the admin could do (legacy):** suspend with a **required reason** (max 500) from the list or the
  detail page; already-suspended customers returned a warning; inside a DB transaction the system cleared
  `email_verified_at`, set status SUSPENDED, wrote a `customer_suspended` ActivityLog, logged the admin action
  and sent `CustomerAccountSuspendedMail` with the reason (mail failures logged, not fatal).
- **Legacy:** `POST /office/customers/{customer}/suspend` — `Admin\CustomerController@suspend` —
  `admin/customers/index.blade.php`, `admin/customers/show.blade.php`
- **New:** none (the management-scoped `/management/customers/{accountId}/suspend` is per-business only)
- **Status:** API **missing**, SPA **missing**
- **Effort:** S — **Priority:** P1

### 23. Activate a customer
- **What the admin could do (legacy):** activate a suspended customer — sets status ACTIVE, marks the email
  verified if not already, writes a `customer_activated` ActivityLog, logs the action and sends
  `CustomerAccountActivatedMail`; already-active returns a warning.
- **Legacy:** `POST /office/customers/{customer}/activate` — `Admin\CustomerController@activate` —
  `admin/customers/index.blade.php`, `admin/customers/show.blade.php`
- **New:** none
- **Status:** API **missing**, SPA **missing**
- **Effort:** S — **Priority:** P1

---

## E. Audit

### 24. Activity log explorer
- **What the admin (superadmin) could do (legacy):** browse the platform audit trail at
  `GET /office/activity-logs` (hard-gated to `role === 'superadmin'`, 403 otherwise). Filters via a modal:
  user (select of all users), action (distinct action values), free-text `q` across action / description / IP /
  user agent, and a `from` / `to` date range, with Reset and Apply. Table columns: When, User, Action,
  Description (truncated with title tooltip), IP, User Agent; 50 per page with the query string appended.
- **Legacy:** `GET /office/activity-logs` — `Admin\ActivityLogController@index` —
  `resources/views/admin/activity_logs/index.blade.php`
- **New:** none — no API route under `routes/api/v1/**`, no view in `storify-admin`
- **Status:** API **missing**, SPA **missing**
- **Missing:** everything. Combined with feature 11 (no writes either), the platform currently has **no audit
  capability at all** in the new stack.
- **Effort:** M — **Priority:** P1

### 25. Admin screen-access logging
- **What the admin could do (legacy):** implicitly — the `AdminRouteActivityLogger` middleware wrote an
  `admin_route_accessed` ActivityLog row (route name, URL, method, response status, role, IP, user agent,
  referer, acting user) for every request inside the `/office` route group, giving a full "who looked at what"
  trail.
- **Legacy:** `app/Http/Middleware/AdminRouteActivityLogger.php` applied to the `/office` group in
  `routes/v1/admin_dashboard.php`
- **New:** none — no equivalent middleware on `routes/api/v1/admin.php`
- **Status:** API **missing**, SPA **missing**
- **Effort:** S — **Priority:** P2 (a11y/compliance-flavoured; wire it at the same time as feature 11)

---

## Gaps worth calling out

1. **Three of the four admin consoles in this domain do not exist in the new stack at all.** There is no
   Admins screen, no platform Customers screen, and no Activity Logs screen — not in `routes/api/v1/admin.php`
   and not in `storify-admin/src/router/index.ts`. The admin sidebar (legacy) had all three; the new SPA nav
   has only Users. That is the single largest chunk of "the management/admin looks scanty" feedback.
2. **No audit trail anywhere.** The legacy wrote `ActivityLog` rows for every user action *and* a row for every
   admin route visit. The new API writes none — not even `Log`-level rows are queryable in-product. Every
   moderation action in the new stack is currently unlogged.
3. **The user console lost its moderation actions.** Reset password, delete and restore have no API and no UI;
   suspend/activate dropped the required reason, the user email notifications, the main-store protection and
   the KYC auto-approval on activation. A support admin cannot today help a locked-out or compromised user.
4. **Impersonation is half-wired.** The API issues a management token pair for the impersonated user, but the
   admin SPA throws it away and only toasts the message; the management SPA has `stopImpersonation` in its
   endpoint map but no UI calls it. End-to-end, "Login as user" does nothing useful except create an
   `Impersonation` row.
5. **The user detail screen is materially thinner.** Legacy showed business metrics, subscription + payments
   and a per-user activity feed in one place; the new drawer shows the name/phone/email form and store chips.
6. **Both organisation-wide deletes are unguarded.** Legacy refused to delete a user (and refused to suspend
   one) who owned the platform main store, or whose stores had open orders/transactions. Any re-implementation
   must carry those guards across, or an admin can disable a business mid-fulfilment.
7. **The admin-invitation accept endpoint already exists but is unreachable** (`GET/POST
   /api/v1/admin/invitations/{token}`) — the invite creation screen, the resend action and the accept page are
   the only missing pieces, which makes admin-invitations one of the cheapest wins here.
8. **Small migration trap:** the API route-keys users by `account_code`, so every new screen must pass the
   account code, not the numeric id (see `User::getRouteKeyName()`); legacy customer screens keyed by
   `account_id`. Keep that in mind when the admins/customers endpoints are built so the SPA contract is
   consistent.
