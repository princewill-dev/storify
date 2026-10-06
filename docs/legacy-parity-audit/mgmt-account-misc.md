# mgmt-account-misc — Legacy → New Stack Feature Inventory

**Domain:** Everything else a business user touches — business dashboard, onboarding setup, profile/account, support messaging, global search, per-store dashboards and KYC (audience: business)
**Legacy source of truth:** `storify-api` @ `routes/v1/management.php` + `app/Http/Controllers/Management/{Dashboard,Setup,Profile,PasswordChange,SupportMessage,Search,StoreDashboard,Kyc}Controller.php` (+ `App\Services\StoreAnalyticsService`) + `resources/views/management/{dashboard,layout,kyc}.blade.php`, `resources/views/management/{profile,support-messages}/**`, `resources/views/management/stores/{show,web-metrics}.blade.php`, `resources/views/management/auth/change-password.blade.php`, `resources/views/auth/business/setup.blade.php`
**New stack:** `storify-api` (`routes/api/v1/management.php`, `routes/api/v1/auth.php`, `app/Http/Controllers/Api/V1/Management/**`, `app/Http/Controllers/Api/V1/Auth/ManagementAuthController.php`) + `storify-management` (`src/router/index.ts`, `src/views/**`, `src/components/SearchModal.vue`, `src/layouts/AppLayout.vue`, `src/api/endpoints.ts`)
**Status vocabulary:** `exists` = fully usable; `partial` = endpoint or screen present but missing actions/fields/validations; `missing` = absent.

---

## Executive summary

The account/misc surface in the new stack is a stub: **one dashboard endpoint with 6 scalars, a profile form without an avatar, a password form, and a search endpoint that returns 3 of the 6 legacy result groups.** Everything else a business user relied on outside of the module screens — the setup step that creates the business, the store switcher, the low-stock/staff/warehouse/transfer/transaction dashboard panels, support messages, the KYC page, the forced password change screen, the trial/KYC banners, and the per-store dashboard and web-metrics pages — is **absent from both the API and the SPA**.

| Area | Legacy features | exists | partial | missing |
|---|---|---|---|---|
| Business dashboard | 11 | 0 | 5 | 6 |
| Onboarding setup | 2 | 0 | 1 | 1 |
| Profile & account | 4 | 2 | 1 | 1 |
| Support messaging | 2 | 0 | 0 | 2 |
| Global search | 1 | 0 | 1 | 0 |
| Store dashboards (cross-ref mgmt-stores) | 2 | 0 | 0 | 2 |
| KYC | 1 | 0 | 0 | 1 |
| App shell / misc | 2 | 2 | 0 | 0 |

**Headline numbers:** 4 of 25 audited features are fully usable; 8 are partial; 13 are entirely missing.

**Route-level reality check (new API).** The complete business-account surface in `routes/api/v1/management.php` + `auth.php` is:

```php
Route::get('dashboard', [DashboardController::class, 'index']);   // 6 stats + recent orders + revenue series
Route::get('search', SearchController::class);                    // products, orders, customers only
Route::get('management/auth/me', ...);                            // user payload + next step
Route::put('management/auth/profile', ...);                       // name, phone ONLY (no photo)
Route::post('management/auth/change-password', ...);
Route::post('management/auth/logout' / 'logout-all', ...);
Route::post('management/auth/stop-impersonation', ...);           // endpoint exists, no UI
```

There is **no** setup endpoint, **no** support-messaging endpoint, **no** KYC endpoint, **no** per-store analytics endpoint, **no** avatar/photo upload and **no** store-switch endpoint. In the SPA, `router/index.ts` has no `/setup`, `/change-password`, `/support-messages` or store-detail route, and `stores/{store}` returns only counts — so several of these features cannot be reached at all.

---

## 1. Business dashboard (`GET /management`)

Legacy controller `Management\DashboardController@index` compiles ~40 stats and renders `resources/views/management/dashboard.blade.php` (419 lines). The new `Api\V1\Management\DashboardController@index` returns `stats.revenue` (total, this_month), `stats.orders` (total, pending, this_month), `stats.customers.total`, `stats.products` (total, low_stock count), `stats.stores.total`, `recent_orders` (8) and `revenue_series`. `DashboardView.vue` renders 4 stat tiles, the chart and recent orders.

### 1.1 Dashboard KPI metric cards — `partial` (API) / `partial` (SPA)
- **What the user could do (legacy):** A responsive grid of up to **11 metric cards**, each permission-gated so staff only see what they may access: Total Revenue (₦ total + `±% vs last month`), Products (count + `N in stock`), Stock Value (₦ active inventory valuation), Orders (total + `N pending · N this month`), Customers (total + `N active` in last 30 days), Warehouses (count + items stocked), Transfer Requests (pending approval count), Open POS (open session count + stores with POS enabled), Web Visits (online-store count), Total Staff (count + active), Stores (count + active). Revenue and order cards also carried month-over-month change percentages.
- **Legacy route:** `GET /management` (`management.dashboard`, middleware `permission:dashboard view`)
- **Legacy controller:** `Management\DashboardController@index`
- **Legacy views:** `resources/views/management/dashboard.blade.php`, `resources/views/management/components/{metric-card,page-header}.blade.php`
- **New API:** `GET /api/v1/management/dashboard` → `Api\V1\Management\DashboardController@index`. Returns only revenue.total/this_month, orders.total/pending/this_month, customers.total, products.total/low_stock, stores.total — no stock value, total stock, active customers, active stores, warehouses, pending transfers, open POS sessions, staff counts, web-visit count, and no month-over-month percentages.
- **New SPA:** `src/views/DashboardView.vue` (route `/`). Renders 4 tiles (Total Revenue, Orders, Customers, Products); the `stores` stat is returned but never displayed; no per-card permission gating; no drill-down links except the recent-orders list.
- **Missing:** stock valuation, total stock on hand, active customer count, active store count, warehouse/transfer/POS/staff/web-visit cards; revenue and order `% vs last month`; permission-scoped visibility per card; card deep links; whole-fleet scoping for restricted staff (legacy also filtered the transaction/order queries to the acting user, not just the business).
- **Side effects:** none (read-only).
- **Effort:** M — **Priority:** P0

### 1.2 Revenue overview chart (6-month area chart) — `partial` / `partial`
- **What the user could do (legacy):** A "Revenue Overview" card showing the current month's revenue as a headline, the % change vs last month (green/red), a "Last 6 months" label and an ApexCharts smooth area chart of confirmed-transaction revenue grouped by month (₦-formatted axis/tooltips, gradient fill).
- **Legacy route:** `GET /management`
- **Legacy controller:** `Management\DashboardController@index` (`monthly_revenue` via `Transaction` where `status = confirmed`)
- **Legacy views:** `resources/views/management/dashboard.blade.php` (+ `@push('scripts')` ApexCharts block)
- **New API:** `GET /api/v1/management/dashboard` → `revenue_series` (last 6 months, `YYYY-MM` + total, omits zero months).
- **New SPA:** `DashboardView.vue` ApexCharts area chart — same chart type/gradient, month labels; **no** this-month headline or % change.
- **Missing:** headline amount and month-over-month delta; zero-filling of months with no revenue (legacy chart shows empty months, new one skips them); order-count series (`monthly_orders`) is computed in legacy but only revenue is charted.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 1.3 Recent Orders panel — `partial` / `partial`
- **What the user could do (legacy):** The right-hand card lists the 8 latest orders (scoped to the active store when one is selected): order number + status badge, store name, **item count**, relative time ("2 hours ago") and total ₦; each row links to the order detail; header "View all" links to the order list; empty state "No orders yet".
- **Legacy route:** `GET /management`
- **Legacy controller:** `Management\DashboardController@index` (`recent_orders`, `with('store','items')`)
- **Legacy views:** `resources/views/management/dashboard.blade.php`
- **New API:** `GET /api/v1/management/dashboard` → `recent_orders` (8, `customer`, `store`, `total`, `status`, `created_at`).
- **New SPA:** `DashboardView.vue` recent-orders list — order number, customer, store, total, status badge; links to `/orders/{order_number}`.
- **Missing:** item count, relative timestamp, "View all" link; new API returns `created_at` but the SPA does not render it.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 1.4 Recent Transactions table — `missing` / `missing`
- **What the user could do (legacy):** A "Recent Transactions" table (5 latest) with Reference (link), Order (link), Customer name ("Walk-in" fallback), Amount ₦, Status badge, Date; header "View all" links to the transactions module. Permission-gated by `transactions view`.
- **Legacy route:** `GET /management`
- **Legacy controller:** `Management\DashboardController@index` (`recent_transactions`, `with('order.customer','order.store')`)
- **Legacy views:** `resources/views/management/dashboard.blade.php`
- **New API:** none — `dashboard` returns no transactions. (The standalone `GET /management/transactions` module exists in the new stack.)
- **New SPA:** none on the dashboard (`src/views/TransactionsView.vue` exists for the module page).
- **Missing:** the whole dashboard table; must be re-added to the dashboard payload + view.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 1.5 Stock Transfer Requests table — `missing` / `missing`
- **What the user could do (legacy):** When any transfer is pending/approved, the dashboard shows a table (up to 10) with Code (link), From location, To location, item count, Status badge and "Requested N ago"; header "View all" links to `/management/transfers?status=pending`. Permission-gated by `transfers view`.
- **Legacy route:** `GET /management`
- **Legacy controller:** `Management\DashboardController@index` (`pending_transfer_list` over stores + warehouses the user can access)
- **Legacy views:** `resources/views/management/dashboard.blade.php`
- **New API:** none — no `transfers` endpoints exist in `routes/api/v1/management.php` at all.
- **New SPA:** none.
- **Missing:** dashboard table plus the whole stock-transfer module endpoint surface it links into.
- **Side effects:** none.
- **Effort:** M — **Priority:** P2

### 1.6 Low-stock alerts panel (with out-of-stock badge) — `partial` / `partial`
- **What the user could do (legacy):** A "Low Stock" card listing up to 6 active products with `0 < quantity <= 10` (name + store + "N left" amber pill), plus an out-of-stock count pill ("N out") in the header, and an empty state "All products well stocked".
- **Legacy route:** `GET /management`
- **Legacy controller:** `Management\DashboardController@index` (`low_stock_products`, `out_of_stock`)
- **Legacy views:** `resources/views/management/dashboard.blade.php`
- **New API:** `GET /api/v1/management/dashboard` returns `products.low_stock` as a **count only** (non-digital products, quantity 1–5 — a different threshold) and no out-of-stock figure, no product rows, no store names.
- **New SPA:** `DashboardView.vue` renders "N low stock" as a tile hint; no panel or list.
- **Missing:** the product list (name, store, quantity), the correct 1–10 threshold, the out-of-stock count, links to the products.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 1.7 Staff panel — `missing` / `missing`
- **What the user could do (legacy):** A "Staff" card (permission `staff view`) listing the 5 most recent staff with a coloured initial avatar (active/invited/suspended), name, role names and status badge; "Manage" link to staff list; empty state with "Invite your first team member" link. The underlying stats (total/active/invited/suspended) also fed the dashboard KPI card.
- **Legacy route:** `GET /management`
- **Legacy controller:** `Management\DashboardController@index` (`recent_staff`, `staffStats`)
- **Legacy views:** `resources/views/management/dashboard.blade.php`
- **New API:** none on the dashboard (the `staff` module endpoints exist separately).
- **New SPA:** none.
- **Missing:** recent-staff list, per-status counts, invite/manage shortcuts on the dashboard.
- **Side effects:** none.
- **Effort:** S — **Priority:** P2

### 1.8 Warehouses panel — `missing` / `missing`
- **What the user could do (legacy):** A "Warehouses" card listing each warehouse with an active/inactive dot, name, city/state and stocked-product count, linking to the warehouse detail; "Manage" link; empty state with "Add your first warehouse".
- **Legacy route:** `GET /management`
- **Legacy controller:** `Management\DashboardController@index` (`warehouses` with `stockLocations` count, `warehouse_total_stock`)
- **Legacy views:** `resources/views/management/dashboard.blade.php`
- **New API:** none — no warehouse endpoints exist in the new management API.
- **New SPA:** none.
- **Missing:** dashboard panel plus the warehouse module surface it links into.
- **Side effects:** none.
- **Effort:** M — **Priority:** P2

### 1.9 Store context switcher ("All Stores" vs one store) — `partial` (API) / `missing` (SPA)
- **What the user could do (legacy):** A dropdown in the dashboard header (shown when the business has stores) to switch the whole dashboard between **All Stores (Combined)** and a single store; the choice is persisted in the session (`POST /management/switch-store`), is access-checked against accessible/assigned stores, and every order/transaction/customer/product/stock/POS stat on the dashboard is recalculated for the chosen scope. "Showing all stores."/"Store switched successfully." flashes.
- **Legacy route:** `POST /management/switch-store` (`management.stores.switch`), consumed by `GET /management`
- **Legacy controller:** `Management\DashboardController@switchStore` / `@index`
- **Legacy views:** `resources/views/management/dashboard.blade.php` (Alpine dropdown), `resources/views/management/components/header.blade.php`
- **New API:** `GET /api/v1/management/dashboard` accepts `store_id` and intersects it with accessible stores (no switch endpoint — the client must send the param on every request; the store-switch concept is stateless now).
- **New SPA:** none — `dashboardApi.index()` never sends `store_id`; `AppLayout.vue` has no store selector anywhere.
- **Missing:** the switcher UI, persistence of the chosen store, and scoping of the search/other endpoints to it.
- **Side effects:** legacy wrote `active_store_id` to the session; new design would need client-side state (Pinia).
- **Effort:** M — **Priority:** P1

### 1.10 Trial / subscription status banners — `partial` (API) / `missing` (SPA)
- **What the user could do (legacy):** The dashboard showed a conditional banner (with CTA): trial ending in ≤ 2 days ("Upgrade Now" → payment), trial expired ("Subscribe Now" → payment), plan selected but unpaid ("Pay Now"), or no plan ("Choose Plan" → plans page).
- **Legacy route:** `GET /management` (banner only; CTAs go to `management.subscription.payment` / `management.subscription.plan`)
- **Legacy controller:** `Management\DashboardController@index` + `Business::hasActiveSubscription()`, `User::isOnTrial()/trialHasExpired()`
- **Legacy views:** `resources/views/management/dashboard.blade.php`
- **New API:** `GET /management/auth/me` returns `subscription.state` (`active|trial|none`, plan, expiry/trial end) and `next` (`plans`), so the data is reachable — but there is no billing/trial banner payload on the dashboard endpoint.
- **New SPA:** none — `DashboardView.vue` shows no banner; `stores/auth.ts` stores `next` but no view reads it.
- **Missing:** the four banner variants, their CTAs, and any trial countdown UI. (The plans/payment screens themselves are a separate domain that also does not exist yet in the SPA.)
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 1.11 KYC onboarding banners — `missing` / `missing`
- **What the user could do (legacy):** Business owners saw one of two prompts on the dashboard when they had pending/inactive stores: "Complete KYC to publish your store(s)" (purple, "Complete KYC" → `/management/kyc`) or "KYC under review" (amber, "View Status" → `/management/kyc`).
- **Legacy route:** `GET /management` (links to `management.kyc.show`)
- **Legacy controller:** `Management\DashboardController@index` (`$user->kycApplication`, pending-store count)
- **Legacy views:** `resources/views/management/dashboard.blade.php`
- **New API:** none — `me` payload has no KYC status and no pending-store count.
- **New SPA:** none.
- **Missing:** both banners and their underlying KYC status data.
- **Side effects:** none.
- **Effort:** S (once KYC status is exposed) — **Priority:** P1

---

## 2. Onboarding setup wizard

### 2.1 Business setup form (creates the Business) — `missing` / `missing` — **P0 blocker**
- **What the user could do (legacy):** After email verification, a verified user with no `business_id` was forced (by `RedirectIfOnboardingIncomplete`) to `/management/setup` and completed the wizard's setup step: **Business Name** (required, max 255), **Business Location** (country select from `Countries::business()`), **Business Description** (max 1000), **Business Phone** (max 50). Submitting created the `Business` row (status `active`, auto slug/business_code/prefix), attached `business_id` to the user, **seeded the business's Spatie roles** (`SpatiePermissionSeeder::createRolesForBusiness`) and **set up the accounting ledger** (`LedgerSetupService::ensureForBusiness`), then redirected to plan selection with "Your business is set up! Now choose a plan to get started."
- **Legacy route:** `GET /management/setup` (`management.setup`), `POST /management/setup` (`management.setup.store`)
- **Legacy controller:** `Management\SetupController@show` / `@store`
- **Legacy views:** `resources/views/auth/business/setup.blade.php`
- **New API:** none. `ManagementAuthController@register` creates only a `User`; `BuildsAuthResponses@nextStep()` returns `'setup'` for a user without `business_id`, but no endpoint in `routes/api/v1/auth.php` or `management.php` can create the business.
- **New SPA:** none — no `/setup` route or view exists, and `VerifyOtpView.vue`/`LoginView.vue` push `/` unconditionally, so a fresh business owner lands on a dashboard whose queries silently return zeros with `business_id = null`.
- **Missing:** the entire setup step — API endpoint, validation, `Business` creation, role seeding, ledger bootstrap, SPA screen, post-auth redirect honouring `next === 'setup'`.
- **Side effects:** creates `businesses` row; assigns user `business_id`; seeds 12 roles/64 permissions for the business; creates the chart of accounts.
- **Effort:** M — **Priority:** P0

### 2.2 Onboarding step routing (register → verify → setup → plans → pay → dashboard) — `partial` / `missing`
- **What the user could do (legacy):** `RedirectIfOnboardingIncomplete` + `CheckSubscription` middleware walked the user through the enforced sequence, exempting each step's routes (verify-otp, setup, plans/checkout/coupon, subscription plan/payment/callback, plus profile/KYC/logout while unsubscribed). Users could not deep-link past an incomplete step.
- **Legacy route:** middleware on `GET /management/*` (`Management\Middleware` via `routes/v1/management.php` route groups)
- **Legacy controller:** `App\Http\Middleware\RedirectIfOnboardingIncomplete`, `App\Http\Middleware\CheckSubscription`
- **Legacy views:** `resources/views/auth/business/*` (setup, plans, verify-otp)
- **New API:** `GET management/auth/me` (and the login/verify responses) return `next: change_password | verify_email | setup | plans | dashboard`, so the backend signals the step.
- **New SPA:** `src/stores/auth.ts` stores `next` but **no route guard or view consumes it**; the router only checks `requiresAuth`/`guest`. There are no `/setup`, `/plans` or `/change-password` routes.
- **Missing:** client-side step gating and the screens the steps route to; unauthenticated deep links into incomplete onboarding are not intercepted.
- **Effort:** S (once the target screens exist) — **Priority:** P0

---

## 3. Profile & account

### 3.1 Profile details editing — `exists` / `exists`
- **What the user could do (legacy):** Edit Full Name (required) and Phone (nullable), see the email address read-only, save with success flash. Breadcrumbs Dashboard → Profile.
- **Legacy route:** `GET /management/profile` (`management.profile.index`), `PUT /management/profile` (`management.profile.update`)
- **Legacy controller:** `Management\ProfileController@index` / `@update`
- **Legacy views:** `resources/views/management/profile/index.blade.php`
- **New API:** `PUT /api/v1/management/auth/profile` → `ManagementAuthController@updateProfile` (name required, phone nullable max 20) and `GET management/auth/me` for display.
- **New SPA:** `src/views/ProfileView.vue` (route `/profile`) — name/phone inputs, read-only email, account summary sidebar (account code, role, business, status, last login) and assigned-stores list.
- **Missing:** nothing material. (Legacy's `old()` re-fill/error display is replaced by toast errors.)
- **Side effects:** none.
- **Effort:** — — **Priority:** P1 (already done)

### 3.2 Avatar (profile photo) upload & remove — `missing` / `missing`
- **What the user could do (legacy):** On the profile page, upload a profile photo (jpeg/png/jpg/webp, max 2 MB) with instant client-side preview (FileReader) and a 2 MB pre-check alert; replacing deleted the old file from the `public` disk and stored the new one under `photos/`; "Remove photo" deleted the file and fell back to a Gravatar-style placeholder. The header/sidebar avatar and profile card used `$user->photoUrl()`.
- **Legacy route:** `PUT /management/profile` (`management.profile.update`, `photo` + `remove_photo` fields); view route `management.profile.index`
- **Legacy controller:** `Management\ProfileController@update` (`$request->hasFile('photo')`, `remove_photo`, `Storage::disk('public')`)
- **Legacy views:** `resources/views/management/profile/index.blade.php` (Alpine `photoUpload` component, hidden file inputs synced via `DataTransfer`), `resources/views/management/components/header.blade.php`
- **New API:** none — `updateProfile` accepts name/phone only; `userPayload` does not return a photo URL.
- **New SPA:** none — `ProfileView.vue` has no photo card; `AppLayout.vue` renders initials instead of a photo.
- **Missing:** multipart photo upload endpoint + validation (mime/size), storage write/delete, `photo_path`/`photo_url` in the user payload, preview/remove UI, avatar rendering in the header/profile.
- **Side effects:** writes/deletes files on the `public` disk.
- **Effort:** S — **Priority:** P2

### 3.3 Change password (from profile) — `exists` / `exists`
- **What the user could do (legacy):** On the profile page, enter current password + new password + confirmation; server validated `current_password:web` and `Password::defaults()`, hashed and saved, flashed "Password updated successfully."
- **Legacy route:** `PUT /management/profile/password` (`management.profile.password`)
- **Legacy controller:** `Management\ProfileController@updatePassword`
- **Legacy views:** `resources/views/management/profile/index.blade.php`
- **New API:** `POST /api/v1/management/auth/change-password` → `ManagementAuthController@changePassword` — current password checked with `Hash::check`, new min 8 + confirmed, clears `force_password_change`, logs `api.management.password_changed`.
- **New SPA:** `ProfileView.vue` Change Password form with current/new/confirm and toasts.
- **Missing:** nothing material (legacy `Password::defaults()` configuration should be verified to match the new `min:8` rule, but no behavioural gap is visible in code).
- **Side effects:** password hash updated; audit log entry.
- **Effort:** — — **Priority:** P1 (already done)

### 3.4 Forced password change after an admin reset — `partial` (API) / `missing` (SPA)
- **What the user could do (legacy):** A user whose password was reset by an admin (`force_password_change = true`) was routed to a dedicated standalone screen (outside the onboarding/subscription gates) showing "Your password was reset by an administrator" with only New Password + Confirm (no current-password field), plus a Sign out link. On submit the flag cleared and they landed on the dashboard with "Password updated successfully. Welcome back!".
- **Legacy route:** `GET /management/change-password` (`management.password.change`), `POST /management/change-password` (`management.password.change.update`)
- **Legacy controller:** `Management\PasswordChangeController@show` / `@update`
- **Legacy views:** `resources/views/management/auth/change-password.blade.php`
- **New API:** `POST management/auth/change-password` exists but **requires the current password**, and `me`/login return `next: 'change_password'` — there is no endpoint that resets the password with only the new value, and no forced-flow branch.
- **New SPA:** none — no `/change-password` route; `auth.ts` stores `next` but nothing redirects on it.
- **Missing:** the forced-change screen, its redirect from the `next` signal, and an API path that accepts a new password for a forced-change user (or the product decision to require the temporary password).
- **Side effects:** clears `force_password_change`; logs `management.password_changed`.
- **Effort:** S — **Priority:** P1

### 3.5 Session management (logout / logout everywhere) — `exists` / `exists`
- **What the user could do (legacy):** Log out from the header profile dropdown, the sidebar, or (POST and GET) `/management/logout`.
- **New API/SPA:** `POST management/auth/logout` (revokes current access token + refresh token) and `POST management/auth/logout-all` (all devices) — an addition beyond legacy; `AppLayout.vue` wires the logout button.
- **Missing:** nothing; `logout-all` has no button yet, but that is net-new beyond legacy.
- **Effort:** — — **Priority:** P2

---

## 4. Support messaging

### 4.1 Support inbox (messages from the business's stores) — `missing` / `missing`
- **What the user could do (legacy):** A Support Messages screen (permission `support view_tickets`) listing every `SupportMessage` belonging to the business's stores (scoped by `Store::where('user_id', $user->id)`), ordered by status then newest: sender name (or email), time-ago, and the message text (clamped to 2 lines); empty state "Customer inquiries will appear here." Each message carries `store`, `phone`, `status` (`pending`/`replied`) and the stored `reply` server-side, though the legacy Blade list is deliberately thin and does not render those columns.
- **Legacy route:** `GET /management/support-messages` (`management.support-messages.index`)
- **Legacy controller:** `Management\SupportMessageController@index`
- **Legacy views:** `resources/views/management/support-messages/index.blade.php`
- **New API:** none — `SupportMessage` is only referenced by customer-side endpoints (`POST api/v1/storefront/support`, `POST api/v1/home/support`); no management list endpoint exists.
- **New SPA:** none — no route, view or sidebar entry.
- **Missing:** list endpoint (business-scoped, with store/status/thread fields), screen, unread/pending indicator, sidebar link.
- **Side effects:** none (read).
- **Effort:** S — **Priority:** P1

### 4.2 Reply to a support message (emails the customer) — `missing` / `missing`
- **What the user could do (legacy):** Reply to a message (permission `support reply`): a required `reply` string (max 2000) was stored on the message, status set to `replied`, `replied_by_type: business`, `replied_by_id`, `replied_at`, an audit log entry written, and `SupportMessageReplyMail` queued to the customer's email with a success flash ("Reply sent successfully to the customer.") and failure handling. Ownership was enforced against the business's stores (403 otherwise). Note: the legacy **management Blade view contained no reply form** — only the admin view did — so the reply capability existed end-to-end on the route/controller but had no visible management UI.
- **Legacy route:** `POST /management/support-messages/{supportMessage}/reply` (`management.support-messages.reply`)
- **Legacy controller:** `Management\SupportMessageController@reply`
- **Legacy views:** `resources/views/management/support-messages/index.blade.php` (no form — gap in legacy itself), admin counterpart at `resources/views/admin/support-messages/index.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** reply endpoint with ownership checks + validation, mail queueing, audit log, and a reply form/thread view (which legacy never rendered for businesses, so this is also an opportunity to exceed legacy).
- **Side effects:** `support_messages` row updated (status/reply/replied_by/replied_at), `SupportMessageReplyMail` queued to customer, `Log::info` audit entry.
- **Effort:** S — **Priority:** P1

---

## 5. Global search

### 5.1 Global search modal (Ctrl/⌘K) — `partial` / `partial`
- **What the user could do (legacy):** Open a command-palette-style modal from the header search button (or Ctrl+K); after typing ≥ 2 characters (250 ms debounce) the server searched **six groups** — Products (name/code, link to product), Stores (name/code, link to store), Warehouses (name/code, link to warehouse), Customers (first/last name, email, phone, account id, full-name concat, link to customer), Transactions (reference, subtitle ₦amount + date, link to transaction) and Staff (name/email/phone, subtitle email + role, link to staff profile) — each capped at 5 results and gated by the caller's permissions. Results rendered grouped with coloured group dots, icons, titles/subtitles, hover arrows; loading spinner, "No results for…" and idle "Search your business" states; Esc closes. Responses were cached 5 minutes per business+query.
- **Legacy route:** `GET /management/search?q=` (`management.search`)
- **Legacy controller:** `Management\SearchController` (invokable)
- **Legacy views:** `resources/views/management/components/header.blade.php` (modal markup + Alpine fetch), `resources/views/management/layout.blade.php` (state)
- **New API:** `GET /api/v1/management/search?q=&limit=` → `Api\V1\Management\SearchController@__invoke` — returns **products, orders, customers only**; no stores, warehouses, transactions or staff; no 2-char minimum, no caching, no permission checks per group (all results come from accessible stores), and results are raw entities rather than pre-built title/subtitle/url items.
- **New SPA:** `src/components/SearchModal.vue` — modal with debounce, Ctrl/⌘K, states; renders Products/Orders/Customers groups, but the products and customers rows navigate to **list pages** (no per-entity deep link) and the `stores` array it types is never returned by the API or rendered. Placeholder text still says "products, orders, customers…".
- **Missing:** Stores, Warehouses, Transactions and Staff result groups with their permission gates; 2-character minimum; per-entity deep links for products/customers; 5-minute cache; group icons/subtitles parity.
- **Side effects:** none (legacy wrote a 5-minute cache entry).
- **Effort:** M — **Priority:** P1

---

## 6. Store dashboards (overlap noted with `mgmt-stores.md`, which audits the same screens in depth)

### 6.1 Per-store dashboard overview — `missing` / `missing`
- **What the user could do (legacy):** `GET /management/stores/{store}` (access-checked via `StoreAccessService`) rendered a tabbed store detail screen whose default **Dashboard** tab showed: 4 metric cards (Revenue this month with ±% vs last month, Total Sales with pending/completed breakdown, Products with active + stock on hand, Customers = unique buyers), a 6-month store revenue area chart, a "Recent Sales" list (8 orders with status/time-ago/total), a **Web Storefront** card (Live badge + URL + Visit, or "Enable Web Storefront" modal), a **POS Terminal** card (session open/closed, opened-by/since/float, open/close forms, or Enable POS), and a **Low Stock** card (with out-of-stock count). A tab bar switched between Dashboard / Products / Sales / Transactions / Customers / Invoices / Staff / Web Store / Settings via AJAX (`StoreTabController@show`), with "Visit Storefront", "Open POS Portal" and "Stock Adjustment" header actions.
- **Legacy route:** `GET /management/stores/{store}` (`management.stores.show`), `GET /management/stores/{store}/tab/{tab}` (`management.stores.tab`)
- **Legacy controller:** `Management\StoreDashboardController@show` + `App\Services\StoreAnalyticsService@dashboard`; `Management\StoreTabController@show`
- **Legacy views:** `resources/views/management/stores/show.blade.php`, `resources/views/management/stores/tabs/**`
- **New API:** none — `GET /api/v1/management/stores/{store}` returns only the store payload (counts, balance, flags); no analytics endpoint. Business-level `GET /management/dashboard` accepts `store_id` but returns no chart/list payload for a single store.
- **New SPA:** none — `StoresView.vue` rows are not clickable and there is no store-detail route.
- **Missing:** the whole per-store dashboard: analytics endpoint, metrics/chart/lists, storefront+POS cards, low-stock card, tab shell, header actions. Cross-ref `mgmt-stores.md` §2.2 for the tab-by-tab inventory.
- **Side effects:** the POS/websites actions mutated sessions/store flags (those modules are audited elsewhere).
- **Effort:** M — **Priority:** P1

### 6.2 Per-store web metrics page — `missing` / `missing`
- **What the user could do (legacy):** For a store with a website: 4 metric cards (Store Views, Product Views, Web Orders, Web Revenue), a 6-month Web Orders bar chart, "Top Products by Views" ranking (top 10), a "Visit Store" card with the storefront URL, and a "Recent Activity" feed (ActivityLog description, time-ago, IP). `webMetrics` redirects back with an error when the store has no website.
- **Legacy route:** `GET /management/stores/{store}/web-metrics` (`management.stores.web-metrics`); also tab `tab/web-metrics`
- **Legacy controller:** `Management\StoreDashboardController@webMetrics` + `App\Services\StoreAnalyticsService@web`
- **Legacy views:** `resources/views/management/stores/web-metrics.blade.php`, `resources/views/management/stores/tabs/web-metrics.blade.php`
- **New API:** none (no view/order counters are exposed anywhere).
- **New SPA:** none.
- **Missing:** entire page; also storefront view counters are not surfaced by any new endpoint.
- **Effort:** M — **Priority:** P2

---

## 7. KYC verification

### 7.1 KYC submission & status page — `missing` / `missing`
- **What the user could do (legacy):** Business owners opened `/management/kyc` to see their application status (not started / submitted / approved / rejected with review notes) and submit or resubmit an application: Legal name, Phone number, Date of birth, Address, City, State, Country, KYC document type (active types from `kyc_document_types`), document ID number, government ID upload and selfie upload. Submission stored the files under `kyc/documents` + `kyc/selfies`, captured device type/browser/IP, set the application to `submitted`, set the user status to `pending`, emailed the business (`BusinessKycSubmitted`) and every superadmin (`AdminKycSubmitted`), and flashed a 1–2 business-day review message. Submitted/approved applications were blocked from resubmission with warnings.
- **Legacy route:** `GET /management/kyc` (`management.kyc.show`), `POST /management/kyc` (`management.kyc.submit`)
- **Legacy controller:** `Management\KycController@show` / `@submit` (+ `SubmitKycRequest`)
- **Legacy views:** `resources/views/management/kyc.blade.php` (top-level view in scope), linked from the sidebar and dashboard banner
- **New API:** none — no management KYC routes; `Api\V1\Admin\DashboardController` only exposes KYC counts to admins.
- **New SPA:** none.
- **Missing:** the entire feature (status view, form, uploads, mails, user-status transition) plus the dashboard banner that links to it.
- **Side effects:** database row created, user `status` → `pending`, document/selfie files stored, two queued emails, audit logs.
- **Effort:** M — **Priority:** P1

---

## 8. App shell / misc

### 8.1 Impersonation banner ("Return to Admin") — `partial` (API) / `missing` (SPA)
- **What the user could do (legacy):** When a superadmin impersonated a business user, the management layout showed an amber banner: "You are viewing as {user} — signed in as admin {name}" with a **Return to Admin** button posting to `admin.impersonate.stop`. The session key `impersonator_id` drove it.
- **Legacy route:** banner on every `management.*` page (`resources/views/management/layout.blade.php`); stop route `admin.impersonate.stop`
- **Legacy controller:** `Admin\ImpersonationController` (stop) + layout session read
- **Legacy views:** `resources/views/management/layout.blade.php`
- **New API:** `POST /api/v1/management/auth/stop-impersonation` exists (`ImpersonationController@stop`), and the SPA exposes `authApi.stopImpersonation()` — but nothing calls it and the `me` payload contains no impersonation flag.
- **New SPA:** none — `AppLayout.vue` has no banner.
- **Missing:** impersonation state in the user payload and the banner/return button in the shell.
- **Effort:** S — **Priority:** P2

### 8.2 Navigation shell, breadcrumbs, profile menu and toasts — `exists` / `exists`
- **What the user could do (legacy):** Sidebar with grouped nav and permission-gated links (Dashboard, Stores, Warehouses, Catalog, Staff/Roles, Customers, Orders, Dispatches, Transfers, POS, Transactions, Invoices, Payment settings, Subscription, Accounting, Profile, KYC, Support messages), collapsible, mobile drawer; header with breadcrumbs, search trigger, profile dropdown (Profile / My Stores / Logout) and flash toasts.
- **New SPA:** `src/layouts/AppLayout.vue` reproduces the shell (persisted-style sidebar groups, breadcrumbs, Ctrl+K search button, profile dropdown, toasts via `ToastHost.vue`).
- **Missing links (because the features are missing):** Support messages, KYC, Subscription, Warehouses, Transfers, POS and Dispatches have no sidebar entries — the nav currently exposes Dashboard, Stores, Products, Categories, Orders, Customers, Transactions, Staff, Roles and Accounting only.
- **Side effects:** none.
- **Effort:** — — **Priority:** P2

---

## Gaps worth calling out

1. **A brand-new business cannot get set up at all in the new stack (P0).** Register → verify → then nothing: `nextStep()` says `setup`, but there is no setup endpoint or screen, and `VerifyOtpView.vue` pushes `/` regardless. The dashboard then queries with `business_id = null` and shows zeros. This blocks every other module for new customers. The same missing step also skips role seeding and ledger setup, so even manual DB fixes leave the business without RBAC roles or a chart of accounts.
2. **The dashboard lost ~two thirds of its widgets and all permission scoping.** Legacy's 11 permission-gated cards, revenue % change, recent-transactions table, transfer table, low-stock list, staff panel and warehouse panel collapse to 4 tiles + chart + recent orders. Two thresholds even disagree (legacy low stock `1–10` active; new `1–5` non-digital, count only).
3. **Support messages, KYC and the store switcher have no reachable path in the SPA** — they are not stub screens, they are absent from the router, the sidebar and the API route table.
4. **Search regressed from 6 groups to 3** (legacy: products, stores, warehouses, customers, transactions, staff; new: products, orders, customers) and lost per-entity deep links for products/customers — the modal navigates to list pages instead of the record.
5. **The forced-password-change flow is half-built:** the API advertises `next: 'change_password'` but the SPA ignores `next`, and `change-password` demands a current password the legacy flow deliberately did not.
6. **No avatar anywhere in the new app** — the header renders initials and `updateProfile` cannot accept a file, so every user loses their photo on migration.
7. **Store switcher asymmetry:** the new dashboard endpoint already accepts `store_id`, and `search`/`stores` are stateless — the only work left is a selector in `AppLayout.vue` plus passing the param (and deciding where to persist it, since sessions no longer exist).
8. **Two features exist in the API but are unreachable from the SPA:** `stop-impersonation` (no banner) and `logout-all` (no button) — low-effort wins.

### Cross-references
- `mgmt-stores.md` §2.2 / §2.9 own the deep detail for the per-store dashboard tabs and web-metrics tab; §6 here records the controller-level status pairs for the same screens so the two audits agree.
- The subscription/plans/payment screens the dashboard banners link to are **not** in this domain's controller list and are unaudited here — they appear to be equally missing from both API and SPA.
