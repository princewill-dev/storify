# admin-users-admins — adversarial verification

**Verifier re-read, independently:** legacy route files `routes/v1/admin_dashboard.php` (users / admins / customers / activity-logs groups), `routes/v1/admin_auth.php` (invitation + impersonate-stop routes), `routes/v1/management.php` / `staff.php` (cross-check); legacy controllers `Admin\{UserController, AdminsController, AdminInvitationController, CustomerController, ActivityLogController}` in full; every in-scope Blade file (`admin/users/{index,show}`, `admin/admins/index`, `admin/customers/{index,show,edit}`, `admin/activity_logs/index`, `admin/auth/accept-invitation`) plus the admin sidebar/management layout; mailables `BusinessSuspended`, `BusinessReactivated`, `UserPasswordResetMail`, `AdminInvitationMail`, `CustomerAccountSuspendedMail`, `CustomerAccountActivatedMail`. New stack: `routes/api/v1/{admin,auth}.php`, `Api\V1\Admin\UserController`, `Api\V1\Auth\{ImpersonationController,InvitationController}`, `routes/api/v1` sweep for any other admins/customers/activity endpoints, `SpatiePermissionSeeder` admin roles, and the admin SPA (`router/index.ts`, `Layouts/AdminLayout.vue`, `views/UsersView.vue`, `api/endpoints.ts`, `composables/useDataTable.ts`, `components/TableFooter.vue`) plus a full-text sweep of both SPAs for `impersonat`.

**Result:** every legacy route in this domain and every in-scope Blade file is accounted for by the audit, and **no audit status (`exists`/`partial`/`missing`) was found to be wrong** — the four "missing" consoles (admins, customers, activity logs, plus reset-password/delete/restore) genuinely have no API route and no SPA screen, and the screens the audit marks `partial` really are stubs of the legacy behaviour. I found **no whole-feature omissions**; the audit's legacy-side descriptions are unusually accurate. The findings below are six concrete corrections, mostly to legacy-behaviour claims that would distort what gets rebuilt.

---

## Corrections

### C1 — 1.1: "column selection" is not a new-stack addition
- The audit says "The new stack **adds** server-side sorting (…), **column selection**, group select + bulk actions and a per-page control, which legacy did not have."
- There is no column-selection control anywhere in `storify-admin` (`grep -rni column src/` returns only an ApexCharts `columnWidth` option; `useDataTable.ts` exposes `perPage`/`sort`/`direction`/selection, `TableFooter.vue` renders pagination + per-page only; `UsersView.vue` has no column picker).
- **should_be:** "adds server-side sorting, select-all-on-page + bulk actions, and a per-page control (no column selection)."

### C2 — 1.5: legacy never captured an activation reason (both legacy UIs submit a hardcoded one)
- The audit frames activation as a reason-capturing action ("activate … with a required reason"; Missing: "reason capture … New SPA hardcodes no reason at all").
- Legacy reality: `admin/users/index.blade.php:145` submits `reason = "Reactivated by admin from user list"` as a hidden input, and `admin/users/show.blade.php:47–49` submits `reason = "Reactivated by admin"` — the admin never types a reason. The controller's `required` rule only forced those hidden defaults to exist.
- **should_be:** the gap is that the new API sends *no* reason at all, so `BusinessReactivated`, the `user_activated` ActivityLog and the KYC reviewer note ("Auto-approved during user activation: {reason}") lose the reason text. Rebuilding it does **not** need a reason form — a default reason (as legacy had) restores parity. Only suspend has a real required-reason UI (correctly flagged in the audit).

### C3 — 1.8: `legacy_views` wrongly includes the detail page for Delete
- The audit lists the delete source as `admin/users/index.blade.php`, `admin/users/show.blade.php`. The detail page has **no Delete control** — `grep "users.destroy"` matches `index.blade.php:174` only; `show.blade.php` offers Restore (when deleted) and never Delete.
- **should_be:** `resources/views/admin/users/index.blade.php` only (the detail page exposes Restore/Reset/Verify/Impersonate, not Delete). Port the Delete action into the list/row UI.

### C4 — 1.12: the staff "warehouse assignment context" gap is not real
- The audit claims: "no warehouse assignment context in the detail (legacy loaded `assignedStores`; the API exposes `accessibleStores` only)."
- `Admin\UserController@show:85` eager-loads `assignedStores`, but `admin/users/show.blade.php` never renders it — the view lists `$user->stores` (owned stores, empty for staff). And the new API's `User::accessibleStores()` (`User.php:103–113`) *returns* `assignedStores()` for restricted staff, so the new stack exposes **more** store context than legacy rendered.
- **should_be:** drop the assigned-store/warehouse item from the missing list; staff parity gaps are only the shared ones (reset-password, delete, restore, audit log, notifications, detail cards).

### C5 — 1.19: `sort_by`/`sort_order` are controller-only (no legacy sort UI)
- The audit presents sorting ("plus `sort_by` / `sort_order` …") as part of the customer directory's user-facing capability. `Admin\CustomerController@index:54–56` does accept the params, but `resources/views/admin/customers/index.blade.php` contains no sort control (`grep "sort"` in that view = empty); only an unvalidated query string exercised it.
- **should_be:** "the controller also accepts unvalidated `sort_by`/`sort_order` params, but no legacy view exposes sorting — pagination is the only user-visible ordering feature." (When porting, the country filter + stats + detail pages are the real work items.)

### C6 — 1.2: `joined date` is already in the new API payload
- The audit's user-console missing list includes "joined date", implying an API gap. `Api\V1\Admin\UserController::payload()` already returns `created_at` and `last_login_at` (and `routes/api/v1/admin.php` serves `show`); only the SPA drawer fails to render them.
- **should_be:** "the API already returns created_at / last_login_at (only the drawer omits them); the true API-side gaps are location, last IP, force_password_change, business metrics/subscription/payments and activity feed."

---

## Notable-but-minor (no structured correction)

- **1.25 phrase "every request inside the `/office` route group"** — the `AdminRouteActivityLogger` group starts at `admin_dashboard.php:50`; `/office/dashboard`, `/office/executive` and `/office/settings` are registered outside it (44–48), so dashboard views and settings edits are absent from the legacy trail (also documented in `admin-dashboard-config.md`). The new-stack gap claim stands regardless.
- **Default role filter drift (1.1):** legacy defaulted the user list to `business_owner`; the SPA defaults to all roles. Not a missing feature, but a behavioural difference worth deciding on deliberately.
- **Ghost routes verified absent on the new side:** no `GET/POST/PUT/DELETE` admin `admins` endpoints, no admin-scoped `customers` endpoints, no admin `activity-logs` endpoint, and no `users/{user}/reset-password|restore` or `DELETE users/{user}` anywhere under `routes/api/v1/**` (only management-scoped customers, which is `business_id`-scoped at `Api\V1\Management\CustomerController:19`).
- **Impersonation end-to-end broken, confirmed by grep:** the only callers are `storify-admin/src/views/UsersView.vue:87–94` (discards the returned management token pair, toasts the message) and `storify-management/src/api/endpoints.ts:48` (`stopImpersonation`, called nowhere). No impersonation banner exists in the management SPA, while legacy `management/layout.blade.php:43–55` renders one ("You are viewing as …", Return-to-admin form posting `admin.impersonate.stop`).
- **Admin SPA nav** (`AdminLayout.vue:29–51`) = Dashboard, Businesses (All Businesses), Users (All Users), Stores, Transactions, Coupons — confirming the audit's gap #1 that Admins / Customers / Activity Logs have no nav entry.
- **Sidebar badges** (legacy Users count, Customers count) have equivalents/absence worth mirroring when the missing screens are built; legacy `admin/users/index` reads accessible to owners+staff only, same role set as the new API.

## Verified as correct (spot-checks)

- All 11 `Admin\UserController` routes, 5 `Admin\AdminsController` routes, 2 invitation routes, 4 customer routes + suspend/activate, the activity-log route and `admin.impersonate.stop` each map to an audit feature; the view-referenced route set (`grep route('admin. …`) contains nothing unaccounted for.
- Statuses: users index/show/update are genuinely partial, verify/unverify genuinely exist, impersonation genuinely half-wired, admins/customers/activity-logs genuinely missing, `admin.admins` permission seeded (`SpatiePermissionSeeder.php:52`) with no new route granting it.
- Side effects the audit lists are real and missing: KYC auto-approval (`UserController:165–173`), six mailables, `ActivityLog` writes in `Admin\UserController::log()` and `Admin\CustomerController`, the customer-suspend transaction + `email_verified_at` clearing, the main-store and open-orders/open-transactions delete guards, and the `AdminRouteActivityLogger` middleware.
