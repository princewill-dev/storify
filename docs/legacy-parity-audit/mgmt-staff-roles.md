# Legacy gap audit — mgmt-staff-roles (audience: business)

Scope: staff CRUD, invitations and the accept-invite flow, role CRUD, the permission matrix and how
permissions are assigned, store/warehouse assignment, deactivation, and password resets.

Legacy source of truth: `storify-api` Blade app (`routes/v1/management.php`, `routes/v1/admin_dashboard.php`,
`routes/v1/staff.php`, `app/Http/Controllers/Management/StaffController.php`, `Management/RoleController.php`,
`Staff/InvitationController.php`, views under `resources/views/management/staff|roles`).

New stack: `routes/api/v1/management.php`, `routes/api/v1/admin.php`, `routes/api/v1/auth.php`,
`app/Http/Controllers/Api/V1/Management/{StaffController,RoleController}.php`,
`Api/V1/Auth/InvitationController.php`, SPAs `storify-management` (`src/views/StaffView.vue`,
`RolesView.vue`) and `storify-admin` (`src/views/UsersView.vue`).

Status vocabulary: **exists** = fully usable in the new stack; **partial** = endpoint or screen present
but missing actions/fields/validations from legacy; **missing** = absent from the new stack.

Key context: the legacy Blade web app is still mounted in the same repo (`routes/web.php` requires
`routes/v1/*`), so some legacy screens are technically still reachable while the new SPAs are not.

---

## A. Staff management (business / management app)

### 1. Staff directory with filters and row actions
- **What the user could do (legacy):** paginated-free list of everyone with `role IN (staff, business_owner)`
  for the business, excluding `status = deleted`. Columns: photo + name (+ "Owner" badge for the owner row),
  email, role chips, status badge, kebab action menu. Filter by store: `?store_id=X` narrows the list to staff
  assigned to that store and changes the page subtitle to "&lt;store&gt; — assigned staff". Row menu: View,
  Edit, Resend Invite (when `invited`), Suspend (when `active`), Activate (when `suspended`), Remove. Owner
  row shows no actions. Empty state with "Invite Staff" CTA.
- **Legacy:** `GET /management/staff` — `Management\StaffController@index` —
  `resources/views/management/staff/index.blade.php`
- **New:** API `GET /management/staff` (`Api\V1\Management\StaffController@index`) → SPA `/staff`
  (`StaffView.vue`)
- **Status:** API **partial**, SPA **partial**
- **Missing:** store filter (`store_id`); the owner row + Owner badge; role filter; "View" link; "Resend Invite"
  row action; warehouses-per-row column. New table adds `last_login_at` and free-text `q` search (legacy had
  neither) so this is not purely a regression.
- **Effort:** S — **Priority:** P1

### 2. Invite staff (create form)
- **What the user could do (legacy):** create a staff user with name, email (unique vs users+customers), phone,
  photo (jpeg/png/jpg/webp, ≤2MB, live preview), **optional password + confirmation** ("Set a password now" →
  `force_password_change`), **6-digit numeric POS PIN**, single role select (team roles), **documents** drag &
  drop upload (up to 10 files, 5MB each, pdf/doc/docx/xls/xlsx/jpg/jpeg/png, per-file tag e.g. KYC/CV/Certificate),
  and role assignment. Store/warehouse ids are accepted by the controller but have no field in the legacy form.
  On save: user created `status=invited` with `invitation_token`, invitation email queued (password included in
  the mail when set), redirect to index with success flash.
- **Legacy:** `GET /management/staff/create`, `POST /management/staff` — `Management\StaffController@create/store`
  — `resources/views/management/staff/create.blade.php`
- **New:** API `POST /management/staff` (`StaffController@store`) → SPA "Invite Staff" modal in `StaffView.vue`
- **Status:** API **partial**, SPA **partial**
- **Missing:** optional password (+confirmation) so the owner can pre-set credentials; document upload + tags
  (API accepts no `documents`/`document_tags`); warehouse assignment field (API accepts `warehouse_ids`, SPA
  never sends it); invitation mail never carries a plain password. Also `role` is validated as a bare string
  (legacy: `exists:roles,name`), so an unknown/foreign team role name throws a 500 instead of a validation error.
- **Effort:** M — **Priority:** P0

### 3. Resend invitation
- **What the user could do (legacy):** from the list kebab or the staff profile, resend an invitation to a staff
  member still in `invited` state: rotates `invitation_token`, refreshes `invited_at`, re-queues the invitation
  email; guarded ("already accepted" warning otherwise). Permission: `staff edit`.
- **Legacy:** `POST /management/staff/{staff}/resend-invite` — `Management\StaffController@resendInvite` —
  `staff/index.blade.php`, `staff/show.blade.php`
- **New:** none — no API route, no SPA action
- **Status:** API **missing**, SPA **missing**
- **Missing:** everything; invites that expire currently cannot be re-sent from the new stack.
- **Effort:** S — **Priority:** P1

### 4. Accept-invitation flow (staff onboarding)
- **What the user could do (legacy):** open the emailed link, see "Welcome {name}, you've been invited to join
  {business}", set password + confirmation, activate the account (`status=active`, `accepted_at`, token
  cleared), get signed in and redirected to the POS if the user has the Cashier role with a POS-enabled
  assigned store, otherwise the dashboard.
- **Legacy:** `GET/POST /management/staff/invitation/{token}` — `Staff\InvitationController@showAccept/accept`
  — `resources/views/staff/auth/accept-invitation.blade.php` (standalone Bootstrap page)
- **New:** API `GET/POST /api/v1/management/invitations/{token}` (`Api\V1\Auth\InvitationController@showStaff/acceptStaff`)
  returns email/name/business and issues a token pair on accept → **no SPA view or route consumes it**
- **Status:** API **exists** (payloads + tokens), SPA **missing**
- **Missing:** the accept-invite screen in the management SPA (route, form, token handling, post-accept redirect
  to POS for cashiers). Today the emailed link is generated by `StaffInvitationMail` pointing at the legacy Blade
  route, so the legacy page is what real users see.
- **Effort:** M — **Priority:** P1

### 5. Staff profile / detail view
- **What the user could do (legacy):** open a staff member's profile: status badge, "Staff since", email, phone,
  **invitation sent timestamp + relative time**, **invitation accepted timestamp**, last login, roles, assigned
  stores and warehouses, profile photo card, Resend Invitation button when pending, Edit button.
- **Legacy:** `GET /management/staff/{staff}` — `Management\StaffController@show` —
  `resources/views/management/staff/show.blade.php`
- **New:** API `GET /management/staff/{staff}` exists (returns stores, warehouses, permissions, last login,
  invited_at) → SPA has **no detail screen**; the only way to see a record is the Edit modal, which discards
  roles/stores beyond prefill.
- **Status:** API **partial**, SPA **missing**
- **Missing:** SPA profile screen; `accepted_at` in the API payload; documents and permission list display.
- **Effort:** S — **Priority:** P1

### 6. Edit staff details (name, phone, photo, PIN)
- **What the user could do (legacy):** edit name, phone (email shown disabled), upload/remove profile photo,
  set/change/clear the 6-digit POS PIN (explicit "leave empty to clear"), save with success flash.
- **Legacy:** `GET /management/staff/{staff}/edit`, `PUT /management/staff/{staff}` —
  `Management\StaffController@edit/update` — `resources/views/management/staff/edit.blade.php`
- **New:** API `PUT /management/staff/{staff}` (name, phone, status, pin, store_ids, warehouse_ids) → SPA edit
  modal in `StaffView.vue` (name, phone, status, PIN, store checkboxes)
- **Status:** API **partial**, SPA **partial**
- **Missing:** photo upload and photo removal on edit (legacy `photo` + `remove_photo`); **clearing** the POS PIN
  (both API and SPA only set it when a value is supplied; legacy explicitly supports clearing); warehouse
  checkboxes; email cannot be changed in either stack (parity).
- **Effort:** S — **Priority:** P1

### 7. Role reassignment for an existing staff member
- **What the user could do (legacy):** open the "Manage Roles" modal in the staff edit page and assign **one or
  more** roles via checkbox cards (each card shows the first 4 permission chips + "+N more"), then Save syncs
  `roles[]` (`syncRoles`). Staff are multi-role by design.
- **Legacy:** `PUT /management/staff/{staff}` with `roles[]` — `Management\StaffController@update`
  (validates `roles.* exists:roles,name`) — `staff/edit.blade.php` (modal)
- **New:** API `PUT /management/staff/{staff}` does **not** accept `role`/`roles` at all; SPA edit modal hides
  the role select when editing and never sends a role
- **Status:** API **missing**, SPA **missing**
- **Missing:** the whole capability — once a staff member is invited there is no way to change their role from
  the new UI at all. This is the single biggest functional hole in this domain (day-one admin task).
- **Effort:** S–M — **Priority:** P0

### 8. Staff documents (KYC/CV/certificates)
- **What the user could do (legacy):** upload up to 10 documents per staff member (pdf/doc/docx/xls/xlsx/jpg/jpeg/png,
  5MB each) on create; on edit see existing docs with original name, size, tag and thumbnail/type icon, mark
  documents for deletion (removed from disk + DB), add new files with free-text tags. Controller validates type,
  size and count.
- **Legacy:** `POST /management/staff`, `PUT /management/staff/{staff}` (`documents[]`, `document_tags[]`,
  `delete_document_ids[]`) — `Management\StaffController@store/update` — `staff/create.blade.php`,
  `staff/edit.blade.php`; model `StaffDocument`
- **New:** none — no API fields, no SPA UI (only the `User::documents()` relation survives)
- **Status:** API **missing**, SPA **missing**
- **Missing:** the entire documents feature.
- **Effort:** M — **Priority:** P2

### 9. Suspend / activate staff (deactivation)
- **What the user could do (legacy):** suspend an active staff member or re-activate a suspended one from the
  list kebab / confirm modal; suspended users cannot log in. Permissions `staff suspend` / (activate behind the
  same middleware group).
- **Legacy:** `PATCH /management/staff/{staff}/suspend`, `PATCH /management/staff/{staff}/activate` —
  `Management\StaffController@suspend/activate` — `staff/index.blade.php`
- **New:** API `POST /management/staff/{staff}/suspend|activate` (`StaffController@suspend/activate`) → SPA
  Suspend/Activate buttons in `StaffView.vue` (login already blocks `suspended`/`deleted`)
- **Status:** API **exists**, SPA **exists**
- **Missing:** nothing material (the SPA additionally allows flipping status from the edit modal, which legacy
  did not).
- **Effort:** — — **Priority:** P2 (kept for parity notes)

### 10. Remove staff
- **What the user could do (legacy):** remove a staff member — implemented as a **soft deactivation**
  (`status = deleted`), so the user row and all history (orders `staff_id`, POS sessions) stay intact and
  the record simply disappears from the staff list. Confirmation modal.
- **Legacy:** `DELETE /management/staff/{staff}` — `Management\StaffController@destroy` — `staff/index.blade.php`
- **New:** API `DELETE /management/staff/{staff}` calls `$staff->delete()` — a **hard delete** (User has no
  SoftDeletes) → SPA Remove button with `confirm()`
- **Status:** API **partial** (behaviour regression), SPA **partial**
- **Missing:** soft-deactivation semantics. Hard delete cascades `pos_sessions.staff_id` (session history is
  destroyed) and nulls `orders.staff_id` (order audit trail loses the operator), and there is no restore path.
- **Effort:** S — **Priority:** P1

### 11. Store assignment from the staff record
- **What the user could do (legacy):** the controller accepted `store_ids[]` on create/update (sync of
  `assignedStores`), though the legacy Blade forms had no visible field for it — assignment was done from the
  store side (see #12).
- **Legacy:** `POST /management/staff`, `PUT /management/staff/{staff}` (`store_ids`) —
  `Management\StaffController@store/update` — (no form field in `staff/create|edit`)
- **New:** API accepts `store_ids[]` on store/update (intersected with accessible stores) → SPA renders a
  **Stores checkbox list** in both create and edit modals (`auth.stores`)
- **Status:** API **exists**, SPA **exists**
- **Missing:** nothing core; note the list only shows stores in the owner's `me.stores`, and there is no
  "assigned to" column in the staff table.
- **Effort:** — — **Priority:** P2

### 12. Per-store staff roster and assign/remove (store side)
- **What the user could do (legacy):**
  (a) **Store → Staff tab** (`GET /management/stores/{store}/tab/staff`): table of staff assigned to the store —
  member (links to profile), role chips, "N store(s), M WH(s)" summary, status badge, Edit link, plus an
  "Add Staff" button that deep-links to the settings tab.
  (b) **Store settings → "Assigned Staff" card**: list of assigned staff with avatar, name, roles, email and a
  remove (detach) form; a select of active, not-yet-assigned staff + "Assign" button to attach.
- **Legacy:** `POST /management/stores/{store}/assign-staff`, `DELETE /management/stores/{store}/remove-staff/{user}`
  — `Management\StoreSettingsController@assignStaff/removeStaff`; `Management\StoreTabController@staff`
  (`GET /management/stores/{store}/tab/{tab}`) — `resources/views/management/stores/settings.blade.php`,
  `stores/tabs/settings.blade.php`, `stores/tabs/staff.blade.php`
- **New:** none — no API endpoint, no SPA store detail/settings screen at all (the SPA `/stores` is a flat list)
- **Status:** API **missing**, SPA **missing**
- **Missing:** both directions of store-side roster management. The only remaining way to move a staff member
  between stores is the staff modal checkbox list.
- **Effort:** M — **Priority:** P1

### 13. Warehouse assignment (warehouse side)
- **What the user could do (legacy):** on warehouse create/edit/settings, a multi-select "Assign Staff" of active
  staff (showing each member's roles), synced to `assignedWarehouses` on save. (Note: the legacy warehouses
  create/edit `staff_ids[]` handling only keeps the last selected value — a multi-select rendering bug — but the
  settings tab syncs properly.)
- **Legacy:** `POST/PUT /management/warehouses...` (`staff_ids[]`) — `Management\WarehouseController@store/update`
  — `resources/views/management/warehouses/create.blade.php`, `edit.blade.php`, `tabs/settings.blade.php`
- **New:** API `StaffController@store/update` accepts `warehouse_ids[]` (partial — only from the staff record);
  SPA has **no warehouse screens anywhere** and never sends `warehouse_ids`
- **Status:** API **partial**, SPA **missing**
- **Missing:** any UI to set warehouse assignments; blocked on the warehouses domain being ported to the SPA.
- **Effort:** M — **Priority:** P2

### 14. Staff metrics on dashboard + sidebar count
- **What the user could do (legacy):** dashboard showed a "Total Staff" metric card ("N active" subtitle) and a
  "Staff" card listing the 5 most recent staff with photo/name/role/status and a "Manage" link, plus an empty
  state inviting the first team member; the sidebar Staff item carried a live count badge of active staff.
- **Legacy:** `Management\DashboardController@index` (`stats['total_staff'|'active_staff'|'invited_staff'|'suspended_staff'|'recent_staff']`)
  — `resources/views/management/dashboard.blade.php`; `resources/views/management/components/sidebar.blade.php`
- **New:** none — new dashboard API/SPA has no staff data; SPA nav has no badge
- **Status:** API **missing**, SPA **missing**
- **Missing:** staff stats + recent-staff widget + nav badge.
- **Effort:** S — **Priority:** P2

### 15. Staff in global search
- **What the user could do (legacy):** typing a name/email/phone in the global management search returned a
  "Staff" result group linking to the staff profile.
- **Legacy:** `Management\SearchController` (Staff group) — command-palette view
- **New:** API `GET /management/search` returns products/orders/customers only; SPA `SearchModal.vue` renders
  those groups
- **Status:** API **partial**, SPA **partial**
- **Missing:** the Staff result group (and its link to the staff record).
- **Effort:** S — **Priority:** P2

---

## B. Roles and permissions

### 16. Role list
- **What the user could do (legacy):** card grid of the business's roles showing role name, description line,
  "Default" badge, up to 6 permission chips (+N more), member count, and an edit/delete kebab with a delete
  confirmation modal that warns members will lose permissions. "Create Role" CTA + empty state.
- **Legacy:** `GET /management/roles` — `Management\RoleController@index` —
  `resources/views/management/roles/index.blade.php`
- **New:** API `GET /management/roles` (returns roles + full permission catalog) → SPA `/roles` (`RolesView.vue`,
  table: name, member count, permission count)
- **Status:** API **exists**, SPA **partial**
- **Missing:** permission chips preview per role; description and "Default" badge were **never persisted** in
  legacy (`roles` table has no such columns), so their absence is fine; system-role delete guard (see #19).
- **Effort:** S — **Priority:** P1

### 17. Role create with permission matrix
- **What the user could do (legacy):** create a role with a name (unique per business) and permissions chosen
  from the full catalog grouped by module (accordion per group with count, per-group "All" toggle, global
  "Select All" / "Clear All"), submitting `permissions[]`. Description field existed in the form but was never
  validated or saved.
- **Legacy:** `GET /management/roles/create`, `POST /management/roles` — `Management\RoleController@create/store`
  — `resources/views/management/roles/create.blade.php`
- **New:** API `POST /management/roles` (duplicate-name check per business, `syncPermissions`) → SPA "Add Role"
  modal with grouped checkboxes, per-group toggle, selected count
- **Status:** API **partial**, SPA **partial**
- **Missing:** global Select All / Clear All buttons; `permissions.* exists:permissions,name` validation (new API
  only checks `string`, so a bogus permission name 500s); description field (safe to treat as obsolete — legacy
  never persisted it).
- **Effort:** S — **Priority:** P1

### 18. Role edit (rename + permission matrix)
- **What the user could do (legacy):** edit name and permissions; group checkboxes pre-tick when the group is
  fully granted; name uniqueness enforced against other roles in the business; description field (not persisted).
- **Legacy:** `GET /management/roles/{role}/edit`, `PUT /management/roles/{role}` —
  `Management\RoleController@edit/update` — `resources/views/management/roles/edit.blade.php`
- **New:** API `PUT /management/roles/{role}` (same payload as create, team-scoped) → SPA edit modal reusing the
  matrix
- **Status:** API **partial**, SPA **partial**
- **Missing:** **name-uniqueness validation on update** (legacy enforced it; new API just calls `update`, so
  renaming to a duplicate hits the DB unique index → 500); global Select All / Clear All; guard against renaming
  protected system roles.
- **Effort:** S — **Priority:** P1

### 19. Role delete with system-role guards
- **What the user could do (legacy):** delete a role unless it is one of the protected system roles
  (`Super Admin`, `Developer`, `Store Associate`) or is still assigned to users — in both cases it flashes an
  explanatory error and does not delete.
- **Legacy:** `DELETE /management/roles/{role}` — `Management\RoleController@destroy` — `roles/index.blade.php`
- **New:** API `DELETE /management/roles/{role}` blocks only `super admin` (case-insensitive) and roles with
  users → SPA `confirm()` then toast on error
- **Status:** API **partial**, SPA **partial**
- **Missing:** protection for `Developer` and `Store Associate`; the SPA surfaces the 409 reason only as a
  generic toast (legacy rendered the message inline).
- **Effort:** S — **Priority:** P1

### 20. Permission catalog (matrix source)
- **What the user could do (legacy):** permissions were seeded per module with `"ability action"` names
  (22 groups, ~64 permissions incl. `staff view|create|edit|delete|suspend|activate`), and every role form read
  the whole catalog.
- **Legacy:** `database/seeders/SpatiePermissionSeeder.php` — `RoleController@create/edit`
- **New:** identical catalog served by `GET /management/roles` (`permissions: string[]`) and grouped client-side
  by first word
- **Status:** API **exists**, SPA **exists**
- **Note:** same as legacy, the business matrix also lists the 20 `admin.*` platform permissions; assigning them
  to a business role has no effect (admin routes additionally require an admin-audience token), but it is noise in
  the matrix. Not a regression.
- **Effort:** S (catalog filtering) — **Priority:** P2

---

## C. Passwords and account security

### 21. Self-service password change
- **What the user could do (legacy):** change own password from the profile page (current password + new +
  confirmation), and for POS staff via `/staff/password/change`. New password clears `force_password_change`.
- **Legacy:** `Management\ProfileController`; `Staff\PosController@showPasswordChange/updatePassword`
  (`GET/POST /staff/password/change`)
- **New:** API `POST /api/v1/management/auth/change-password` (current_password + password + confirmation,
  verifies current, clears `force_password_change`) → SPA `ProfileView.vue` password card
- **Status:** API **exists**, SPA **exists**
- **Missing:** nothing for the business user; POS-terminal password change belongs to the POS app.
- **Effort:** — — **Priority:** P2

### 22. Forced password change after an admin reset
- **What the user could do (legacy):** when `force_password_change` is set (admin reset or owner-set invite
  password), any management route bounces the user to `/management/change-password`; a branded full-page form
  asks for a new password before they can continue.
- **Legacy:** `Management\PasswordChangeController@show/update` (`GET/POST /management/change-password`),
  enforced by `RedirectIfOnboardingIncomplete` middleware — `resources/views/management/auth/change-password.blade.php`
- **New:** API `nextStep()` returns `'change_password'` and `me`/login payloads expose `force_password_change`,
  but **no SPA consumes `next_step`** (LoginView ignores it; no `/change-password` route or view; no router guard)
- **Status:** API **partial**, SPA **missing**
- **Missing:** the forced-change screen and the post-login redirect/guard in the management SPA.
- **Effort:** S — **Priority:** P1

### 23. Forgot / reset password (email OTP)
- **What the user could do (legacy):** request a reset OTP by email and set a new password on the reset screen.
- **Legacy:** `Management\...\BusinessAuthController@showForgotPassword/sendResetOtp/showResetPassword/resetPassword`
  — `management/auth/*` views
- **New:** API `POST /api/v1/management/auth/forgot-password|reset-password` → SPA
  `auth/ForgotPasswordView.vue`, `auth/ResetPasswordView.vue`
- **Status:** API **exists**, SPA **exists**
- **Missing:** nothing (included here because "password resets" is in scope for this domain).
- **Effort:** — — **Priority:** P2

### 24. Admin-initiated password reset (superadmin)
- **What the user could do (legacy):** from the admin user detail, "Reset Password" generates a temporary
  password, emails it to the user, sets `force_password_change = true` and logs the action; on mail failure the
  temp password is shown on screen.
- **Legacy:** `POST /office/users/{user}/reset-password` — `Admin\UserController@resetPassword` —
  `resources/views/admin/users/show.blade.php`
- **New:** none — no API route (admin API stops at verify/unverify) and no SPA action
- **Status:** API **missing**, SPA **missing**
- **Missing:** the whole capability; combined with #22 this means a business owner's staff can be locked out with
  no recovery path from either console (only "forgot password" self-service, which requires mailbox access).
- **Effort:** S — **Priority:** P1

---

## D. Platform admin (superadmin) — staff/permissions adjacent

*(Included because the brief covers what admins could do with staff; the general admin business/user directory
may also be tracked by a separate admin-domains audit — flagged to avoid double-counting.)*

### 25. Platform admin (office staff) management
- **What the user could do (legacy):** superadmin-only "Admins" screen: invite a platform admin by email +
  role (non-Super-Admin office roles, e.g. Platform Admin/Support Admin/Finance Admin), role auto-assigned;
  table of admins with avatar/name/email, role badge, status (Pending/Active), invited date; **Resend** invite;
  inline **role change** dropdown per admin; **Remove** with confirm. Guards: cannot change own role, cannot
  change/remove a superadmin, cannot remove self.
- **Legacy:** `GET/POST /office/admins`, `POST /office/admins/{admin}/resend`, `PUT /office/admins/{admin}`,
  `DELETE /office/admins/{admin}` — `Admin\AdminsController` — `resources/views/admin/admins/index.blade.php`
  (permission `admin.admins`)
- **New:** none — no admin API routes, no admin SPA view or nav entry
- **Status:** API **missing**, SPA **missing**
- **Missing:** all of it. (The admin *accept* half of the flow exists in the API — see #26 — but no admin can be
  invited from the new stack.)
- **Effort:** M — **Priority:** P1

### 26. Admin invitation accept
- **What the user could do (legacy):** open the emailed admin invite link, set name + password, activate and log
  into the admin panel.
- **Legacy:** `GET/POST /office/invite/{token}` — `Admin\AdminInvitationController` —
  `resources/views/admin/auth/accept-invitation.blade.php`
- **New:** API `GET/POST /api/v1/admin/invitations/{token}` (`Api\V1\Auth\InvitationController@showAdmin/acceptAdmin`)
  issues an admin-audience token pair → **no admin SPA route/view consumes it**
- **Status:** API **exists**, SPA **missing**
- **Missing:** the accept-invite screen in `storify-admin`.
- **Effort:** S–M — **Priority:** P1

### 27. Admin user directory (business owners + staff)
- **What the user could do (legacy):** platform-wide user list with stats badges (owners / staff / suspended /
  unverified), filters (q, role owners|staff|all, status incl. deleted, verified, has business, subscription),
  columns incl. business, plan and last login, and a detail page with verify/unverify, suspend (reason
  **required**, modal), activate, **reset password**, **restore** (deleted users), **delete** (blocked for main
  store owners), and **impersonate** (blocked for admins/deleted/self) with a stop-impersonation path.
- **Legacy:** `/office/users*` — `Admin\UserController@index/show/update/suspend/activate/verify/unverify/resetPassword/destroy/restore/impersonate`
  — `resources/views/admin/users/index.blade.php`, `show.blade.php`
- **New:** API `GET /admin/users`, `GET /admin/users/{user}`, `PUT`, `POST .../suspend|activate|verify|unverify`
  (`Api\V1\Admin\UserController`); impersonation via `POST /admin/users/{user}/impersonate` (auth.php) → SPA
  `/users` (`UsersView.vue`) with filters (q, role, status, verified), sortable columns, bulk activate/verify/
  unverify/suspend-with-optional-reason, detail drawer with inline edit + actions
- **Status:** API **partial**, SPA **partial**
- **Missing:** reset-password, restore, delete endpoints/actions; "has business" and "subscription/plan" filters
  and stats badges; plan and business columns in the drawer; suspend reason is optional (legacy required it);
  legacy's full detail page (account/store context) is reduced to a drawer.
- **Effort:** M — **Priority:** P1

---

## Gaps worth calling out

1. **No way to change a staff member's role after invitation (A7).** Legacy had a full multi-role "Manage Roles"
   modal on the staff edit page; the new API `update` does not accept `roles` and the SPA hides the role select
   when editing. This breaks the most common day-to-day admin action (promote cashier → manager) and is a P0.
2. **`DELETE /management/staff/{staff}` is a hard delete (A10).** Legacy only set `status = deleted`. The new
   behaviour cascade-deletes `pos_sessions` (losing sales-session history) and nulls `orders.staff_id` (losing
   the "who served this sale" audit trail), with no restore path. This should be changed to soft-deactivation
   before anyone removes staff in production.
3. **Resend invitation is gone (A3).** Invites expire / emails get lost; the kebab action and the profile button
   both exist in legacy and are absent from the API and SPA.
4. **The invitation acceptance experience is still the legacy Bootstrap page (A4, D26).** `StaffInvitationMail`
   links to `management.staff.invitation.accept` (Blade). The new API invitation endpoints exist but no SPA page
   uses them, so new-stack users are bounced into legacy HTML for their first login.
5. **Staff documents (KYC/CV/certificates) are entirely gone (A8)** even though the `StaffDocument` model and
   `User::documents()` relation survive in the codebase.
6. **Forced-password-change is half-wired (C22).** The API computes `next_step = 'change_password'` but neither
   SPA reads `next_step`, so a user flagged `force_password_change` (e.g. pre-set invite password) is never
   forced to change it. Combined with the missing admin reset (C24), account-recovery flows are incomplete.
7. **Role validation gaps (B17, B18).** No uniqueness check on role rename (500 instead of a validation message),
   no `exists` validation on permission names, and system-role delete protection was narrowed to Super Admin —
   `Developer` and `Store Associate` can now be deleted.
8. **Store-side roster management is missing (A12).** Legacy exposed assigned staff from both the store settings
   tab and the store Staff tab with assign/remove forms; the new SPA has no store detail/settings at all, so
   staff↔store relationships can only be edited from the staff modal checkbox list, and there is no per-store
   view of the roster.
9. **Warehouse assignment has no UI (A13).** The API accepts `warehouse_ids` from the staff record but the SPA
   has no warehouse screens, so assigned warehouses are effectively unmanageable in the new stack.
10. **Platform admin management is absent (D25).** A superadmin cannot invite another office admin from the new
    admin SPA; only the legacy `/office/admins` Blade screen can. The accept half exists in the API with no UI.
