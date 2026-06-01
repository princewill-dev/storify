# AGENTS.md — Storify

Laravel 12 + Tailwind CSS v4 multi-store ecommerce platform with POS.

## Quickstart

```bash
npm install && npm run build      # Frontend assets (Tailwind v4 via Vite)
composer install                   # PHP dependencies
php artisan migrate --force        # Database (always use --force)
php artisan optimize:clear         # After any route/controller/view change
```

**Database**: MySQL on port 3307. Session and cache drivers are `database` (not Redis/file).

## Architecture

### Auth: Single `users` table (Spatie RBAC)
- `users` table is the sole auth entity for all users (business owners, staff, superadmins).
- Guard: `web` (session-based). Old `vendor` and `staff` guards removed.
- `auth:customer` guard for customers only.
- Staff are Users with `role = 'staff'` — assign Spatie roles via `$user->assignRole('Cashier')`.
- All `vendor_id` and `staff_id` FKs were renamed to `user_id` across all tables.

### Route Structure
```
routes/v1/management.php   — Business dashboard (/management/*)
routes/v1/staff.php         — Staff POS (/staff/*)
routes/v1/home.php          — Public storefronts (subdomains)
routes/v1/account.php       — Customer accounts
routes/v1/admin_dashboard   — Superadmin panel
routes/v1/vendor.php        — Legacy redirects only
```

### Onboarding Flow (enforced by middleware)
```
Register → Verify Email → Business Setup (/management/setup) → Plans (/management/plans) → Pay → Dashboard
```
- `RedirectIfOnboardingIncomplete`: Blocks unverified users and users without business setup.
- `CheckSubscription`: Blocks users without active subscription (exempts setup/plans/auth routes).

### Key Controllers
```
Auth/BusinessAuthController   — Registration, login, OTP verification
Management/SetupController    — Business setup form (ownership type, business model, currency, etc.)
Management/SubscriptionController — Plans, checkout, Paystack integration, trial activation
Management/DashboardController — Main dashboard with metric cards
```

## Conventions

### NEVER put raw PHP in Blade views
Database queries, variable setup, and business logic belong in controllers or View Composers.
The `AppServiceProvider` has a View Composer for `sidebar` + `header` that provides all shared data.
Inline `@php` blocks for session access (`session('key')`) are acceptable.

### Frontend
- **Tailwind CSS v4** via `@vite('resources/css/app.css')` — included in management layout.
- **Alpine.js** loaded via CDN `<script defer>`. Used for sidebar toggle, dropdowns, modals.
- **ApexCharts** loaded from `vendor_files/` — used for dashboard revenue chart.
- **Flaticon Uicons** for icons (`fi fi-rr-*`). Font Awesome was dropped.
- After changing any Blade file: `npm run build` to rebuild Tailwind (it tree-shakes unused classes).

### Models
- `User` is the central model — has `stores()`, `warehouses()`, `locations()`, `subscriptions()`, `payments()`, `kycApplication()`, `assignedStores()`, `assignedWarehouses()`, `posSessions()`.
- `User` uses Spatie `HasRoles` trait — roles/permissions managed via Spatie's `roles()` relationship (not the old custom Role model).
- `Subscription` (was `VendorSubscription`) — `is_trial`, `trial_days` are on `SubscriptionPlan`, not the subscription itself.
- `SubscriptionPlan` uses `plan_code` (24-char random string) as route key, not numeric ID.
- Coupon codes exist in `coupons` table with `discount_type` (percentage/fixed) and `discount_value`.
- Store creation: slug is auto-generated server-side. `has_website` toggles online storefront availability.
- Location model exists in DB but is NOT used in UI — warehouse location is set via state/city directly on the warehouse form.

### UI Components
Reusable Blade components live in `resources/views/components/management/`:
- `metric-card`, `page-header`, `status-badge`, `empty-state`, `card`, `data-table`, `form-input`, `modal`, `action-menu`
- All styled with Tailwind. Use these instead of raw HTML for consistency.

### Route naming
All management routes use `management.*` prefix. Staff routes use `staff.*`. No `vendor.*` routes (redirect only).

### Testing
```bash
php artisan test                 # Pest PHP
php artisan test --filter=Foo    # Single test
```

### Session & Cookies
- `SESSION_DOMAIN` in `.env` must match the domain you're testing on (e.g., `.storify.test`).
- `SESSION_SECURE_COOKIE=false` for local dev (no HTTPS).
- Session mismatch = CSRF failure → proper 419 error page (not redirect to login).

### Debugging
- `laravel-debugbar` is installed — provides detailed request info in development.
- Logs: `storage/logs/laravel-{date}.log` (daily rotation).
- php artisan tinker is your friend: `App\Models\User::first()`, etc.

### Spatie RBAC
- Permissions use `"ability action"` format (e.g., `"warehouses view"`, `"pos open_session"`).
- Route middleware: `permission:warehouses view` or `role:Super Admin`.
- Assign roles: `$user->assignRole('Cashier')`, check: `$user->hasRole('Cashier')`.
- Check permissions: `$user->hasPermissionTo('warehouses view')`, `$user->can('warehouses view')`.
- All 12 roles + 64 permissions seeded via `SpatiePermissionSeeder`.
- Staff users (role='staff') get `Store Associate` role by default.
- Superadmin users get `Super Admin` role.

### Common Gotchas
- NEVER use "vendor" as a variable name (e.g., `$vendor`, `vendor_id`, `@var Vendor`, `'vendor' =>`). The old Vendor model was deleted. Use `$user` for the authenticated user and `$user->business` for business-level data. Log strings should avoid "vendor" prefixes too — use "store" or "business" context instead.
- `npm run build` is required after ANY Blade change (Tailwind v4 tree-shaking).
- `php artisan optimize:clear` after ANY route/controller/view/config change.
- Alpine.js scope chains: nested `x-data` blocks shadow parent scope. Use `Alpine.$data(el)` to bridge scopes, or use `onclick` handlers with global functions.
- The `location_id` column was dropped from `warehouses`. Don't reference it.
- `Route::resource` names must match view `route()` calls exactly.
- Plan checkout URLs use `plan_code` (random string), not numeric IDs.
- Old `Staff` model, `Role` model, `CheckStaffPermission` middleware, and `StaffStatus` enum are deleted — use `User` with Spatie.


DO NOT USE ANY vendor terminated variables, use user instead
