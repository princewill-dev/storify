# mgmt-customers — Legacy → New Stack Feature Inventory

**Domain:** Customer management (audience: business) + platform-admin customer oversight
**Legacy source of truth:** `storify-api` @ `routes/v1/management.php` (prefix `management`), `routes/v1/admin_dashboard.php` (prefix `office`), `app/Http/Controllers/Management/CustomerController.php`, `app/Http/Controllers/Admin/CustomerController.php`, `resources/views/management/customers/**`, `resources/views/admin/customers/**`
**New stack:** `storify-api` (`routes/api/v1/management.php`, `app/Http/Controllers/Api/V1/Management/CustomerController.php`) + `storify-management` (`src/router/index.ts`, `src/views/CustomersView.vue`, `src/api/endpoints.ts`); admin stack = `routes/api/v1/admin.php` + `storify-admin/src/**`
**Status vocabulary:** `exists` = fully usable in the new stack; `partial` = endpoint or screen present but missing actions/fields/validations from legacy; `missing` = absent from the new stack.

---

## Executive summary

The business customers domain is the thinnest area of the rewrite so far. The new management stack ships **one screen** (`CustomersView.vue`: list + read modal + edit modal + suspend/activate buttons) backed by **five API endpoints**. Legacy had a smaller route surface (6 business routes) but substantially richer behaviour on each: country and store filters, summary stat cards, an account-verified/status edit flow, mandatory suspension reasons written to an audit log, an activity timeline, per-user and per-store scoping for restricted staff, and full customer-facing email on admin actions.

The **entire admin customer module is absent from the new stack**: `routes/api/v1/admin.php` has no customers routes, there is no `Api/V1/Admin/CustomerController`, no admin API customer payload anywhere, no admin SPA view, no nav item. The admin SPA only shows a customer *count* tile on its dashboard (fed by `Api\V1\Admin\DashboardController`).

**Headline numbers:** 0 of 2 business list-filter features complete; 0 of 4 admin customer features exist; the 4 business mutation endpoints that exist are all **partial** (missing validations/side effects the legacy had). No customer-facing email is sent by any new management API mutation.

| Area | Legacy features | exists | partial | missing |
|---|---|---|---|---|
| Business customer list, filters & navigation | 5 | 0 | 3 | 2 |
| Business customer record (detail, edit, status) | 5 | 0 | 3 | 2 |
| Customer-facing messaging | 2 | 0 | 0 | 2 |
| Admin customer management | 4 | 0 | 0 | 4 |
| Cross-cutting (dashboards, self-service reset) | 2 | 1 | 1 | 0 |
| **Totals** | **18** | **1** | **8** | **9** |

---

## 1. Business customer list, filters & navigation

### 1.1 Customer list with search, status filter and pagination — `partial`
- **What the user could do (legacy):** Paginated (20/page) list of customers with **summary metric cards** above it (Total, Active, Suspended, Total Orders), free-text search across first name / last name / email / phone / **account_id** (debounced live search, 300 ms), a **status** dropdown (Active / Suspended), a **store** dropdown (accessible stores), a **country** dropdown (distinct countries derived from the customers' delivery addresses → delivery routes), a "Clear" link when any filter is active, columns Customer (avatar initial + name), Email, Phone, Status badge, Orders count, Actions (View / Edit), and Laravel pagination preserving the query string. Restricted staff (Store Associates) see **only customers with orders in their assigned stores**, and the per-row order count is scoped to orders placed with the current user.
- **Legacy route:** `GET /management/customers` (`management.customers.index`)
- **Legacy controller:** `Management\CustomerController@index`
- **Legacy views:** `resources/views/management/customers/index.blade.php`
- **New API:** `GET /api/v1/management/customers` → `Api\V1\Management\CustomerController@index` — supports `q` (first/last name, email, phone — **no account_id**), `status`, `per_page`; returns id, account_id, name, first/last name, email, phone, lowercased status, orders_count, created_at. Scoped to `business_id` only.
- **New SPA:** `src/views/CustomersView.vue` (route `/customers`), `customersApi.index`; search input + status select + Filter button; columns Customer (name + account_id), Contact (email + phone), Orders, Status, Actions (Edit, Suspend/Activate).
- **Missing:** `account_id` search; restricted-staff scoping (legacy limited Store Associates to assigned stores — the new API exposes every customer of the business to anyone with `customers view`); per-user order counts; debounced/as-you-type search; "Clear filters" affordance; the legacy page's metric cards (see 1.2); the separate View action (the SPA opens a modal instead — see 1.3).
- **Side effects:** none.
- **Effort:** S — **Priority:** P0

### 1.2 Customer list filters — country & store — plus summary metric cards — `missing`
- **What the user could do (legacy):** Narrow the list to customers who have a delivery address on a route in a given **country**, and to customers who have ordered from a given **store**. See four metric cards (Total / Active / Suspended / Total Orders) computed over the business (legacy actually scopes them to customers with orders by the current user).
- **Legacy route:** `GET /management/customers?country=…&store_id=…`
- **Legacy controller:** `Management\CustomerController@index` (`$stats`, `$countries`, `$stores`)
- **Legacy views:** `resources/views/management/customers/index.blade.php` (stat cards + filter selects)
- **New API:** no `country`/`store_id` filters and no stats block in `GET /api/v1/management/customers`.
- **New SPA:** no corresponding controls or cards in `CustomersView.vue`.
- **Missing:** both filters (API + SPA controls), the countries lookup, the stores lookup, and all four stat cards. The admin legacy also had a status filter value for `DELETED` — the business list did not; keep it out of the business list.
- **Side effects:** none.
- **Effort:** S (one `whereHas` for store, one nested `whereHas` for country, a stats query, two selects and a card row) — **Priority:** P1

### 1.3 Customer detail screen (orders + spend) — `partial`
- **What the user could do (legacy):** Open a customer and see a header with their full name, "Customer since {created_at}", status badge; a **Customer Information** card (Email, Phone, **Account ID**); and a **Recent Orders** list (last 10 orders placed with the current user, each row: order number, date, status badge, total, linking to the order detail screen). The controller also loaded delivery addresses, spend stats (total/completed/pending orders, total spent), last 10 transactions and the last 20 activity-log entries — the management Blade never rendered the stats/transactions/activity blocks, only the admin view did.
- **Legacy route:** `GET /management/customers/{customer}` (`management.customers.show`) — bound by `account_id`
- **Legacy controller:** `Management\CustomerController@show`
- **Legacy views:** `resources/views/management/customers/show.blade.php`
- **New API:** `GET /api/v1/management/customers/{customer}` → `Api\V1\Management\CustomerController@show` — returns the customer payload, stats {total_orders, completed_orders, total_spent} and the last 10 recent orders (id, order_number, total, status, created_at).
- **New SPA:** `CustomersView.vue` detail `AppModal` (opened from the name; not deep-linkable), `customersApi.show`.
- **Missing:** a real route/page (`/customers/:id`), so the modal cannot be linked to from orders, transactions, search or a bookmark; **pending orders** count (legacy computed it); order store name and item count per recent order (legacy admin rendered them); delivery addresses (loaded by the legacy business controller, rendered only in admin); transactions list; activity timeline; "Customer since" date (API returns `created_at` but the modal does not show it).
- **Definition drift worth flagging:** legacy business `total_spent` = sum of **all** order totals; the new API computes only **completed** orders; legacy admin computed orders with **confirmed transactions**. Pick one definition and label the tile.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 1.4 Customer edit form (including status) — `partial`
- **What the user could do (legacy):** Edit First name, Last name, Email, Phone, Location and **Status (Active / Suspended / Deleted)**; a sidebar Account Summary (Total Orders, Email Verified yes/no, Last Updated) and the created timestamp. Server rules: first/last/email required, email unique (ignoring self), max lengths; setting status to **Active** marks the email verified, any other status **clears** `email_verified_at`.
- **Legacy route:** `GET /management/customers/{customer}/edit` (`management.customers.edit`), `PUT /management/customers/{customer}` (`management.customers.update`)
- **Legacy controller:** `Management\CustomerController@edit` / `@update`
- **Legacy views:** `resources/views/management/customers/edit.blade.php`
- **New API:** `PUT /api/v1/management/customers/{customer}` → `Api\V1\Management\CustomerController@update` — accepts first_name (`sometimes`), last_name (`nullable`), email (`sometimes`, unique), phone, location. **No `status` field at all**, no verification side effect.
- **New SPA:** edit `AppModal` in `CustomersView.vue` (`customersApi.update`) — First name (required), Last name, Email (required), Phone, Location. No status control, no account summary, no email-verified indicator; the form does not pre-fill `location` from the customer (always blank).
- **Missing:** status editing (both API and UI), email-verification sync on status change, required-field validation parity (legacy: first/last/email required), account summary sidebar, created/updated timestamps, "Last Updated" info.
- **Side effects (legacy):** toggles `email_verified_at` implicitly through the status field; writes nothing to `ActivityLog`.
- **Effort:** S — **Priority:** P1

### 1.5 Suspend customer with mandatory reason — `partial`
- **What the user could do (legacy):** Suspend a customer from the list/show/edit; a **reason is required** (max 500 chars); already-suspended customers get a warning instead of a double write; the operation runs in a DB transaction, sets status `SUSPENDED`, clears `email_verified_at`, and writes an **ActivityLog** entry (`customer_suspended`, reason, old/new values, IP, actor in metadata) plus an application log line. The business side did **not** email the customer.
- **Legacy route:** `POST /management/customers/{customer}/suspend` (`management.customers.suspend`)
- **Legacy controller:** `Management\CustomerController@suspend`
- **Legacy views:** `resources/views/management/customers/index.blade.php` (no modal — form post from the row)
- **New API:** `POST /api/v1/management/customers/{customer}/suspend` → `Api\V1\Management\CustomerController@suspend` — sets status `SUSPENDED` + clears `email_verified_at`; `reason` is optional and is **discarded** (no persistence anywhere); no already-suspended guard; no transaction; no log.
- **New SPA:** `toggleStatus()` in `CustomersView.vue` — a native `confirm()` only, no reason field (the API client method accepts a reason param that the view never passes).
- **Missing:** required reason + reason capture UI, reason persistence (ActivityLog or a `suspension_reason` column), audit trail, already-suspended warning, and (product decision) the customer email that admin legacy sent.
- **Side effects (legacy):** ActivityLog write, `Log::info`, email-verified clear.
- **Effort:** S — **Priority:** P1

### 1.6 Activate / reactivate customer — `partial`
- **What the user could do (legacy):** Activate a suspended (or deleted) customer; guards against already-active; sets status `ACTIVE`, marks the email verified if not already, writes an ActivityLog entry + application log.
- **Legacy route:** `POST /management/customers/{customer}/activate` (`management.customers.activate`)
- **Legacy controller:** `Management\CustomerController@activate`
- **Legacy views:** row action in `resources/views/management/customers/index.blade.php`
- **New API:** `POST /api/v1/management/customers/{customer}/activate` → sets `ACTIVE` and fills `email_verified_at` if empty. No activity log, no already-active guard.
- **New SPA:** same toggle button in `CustomersView.vue`.
- **Missing:** audit trail, already-active warning; button appears correctly for non-active statuses.
- **Side effects (legacy):** ActivityLog + `Log::info`; email verification.
- **Effort:** S — **Priority:** P1

### 1.7 Customer activity timeline / audit log — `missing`
- **What the user could do (legacy):** Admin customer detail rendered the last 20 `ActivityLog` entries for the customer (actor name — or "System", description, relative + absolute timestamp). Business suspend/activate wrote entries; admin suspend/activate wrote entries with the admin as actor (business writes set `user_id = null` and put the actor in `metadata.user_id`).
- **Legacy route:** n/a (part of show screens)
- **Legacy controller:** `Admin\CustomerController@show` (`$activityLogs`), writes in both `suspend`/`activate` methods of both controllers
- **Legacy views:** `resources/views/admin/customers/show.blade.php` (Activity Log card); `management/customers/show.blade.php` is passed `$activityLogs` but never renders it
- **New API:** no activity-log endpoint or writes in `Api\V1\Management\CustomerController`; the customer `show` payload has no activity block.
- **New SPA:** none.
- **Missing:** writes on suspend/activate/update, a payload block (or endpoint), and a UI timeline. When porting, set the real actor (`user_id`) rather than the legacy's `user_id: null` + metadata workaround.
- **Effort:** M — **Priority:** P2

### 1.8 Global customer search and cross-linking — `partial`
- **What the user could do (legacy):** The management header search matched customers on first/last/email/phone/**account_id** and full name, in the group "Customers", and each result linked straight to that customer's detail page. Sidebar showed a live **customer count badge** (business-wide; restricted staff saw counts scoped to their stores).
- **Legacy route:** `GET /management/search?q=…` (`management.search`), rendered by the header partial
- **Legacy controller:** `Management\SearchController@__invoke`
- **Legacy views:** `resources/views/management/components/header.blade.php`, `resources/views/management/components/sidebar.blade.php` (+ count from `AppServiceProvider` view composer / `Staff\DashboardController` module links)
- **New API:** `GET /api/v1/management/search` → `Api\V1\Management\SearchController` returns a `customers` group (id, account_id, name, email, phone; matches name/email/phone — **not account_id**, and no `customers view` permission gate).
- **New SPA:** `src/components/SearchModal.vue` lists customer results, but clicking one routes to the **customers list** (`/customers`), not to that customer — there is no per-customer route to open. Nav item `Customers` in `src/layouts/AppLayout.vue` has no count badge.
- **Missing:** account_id matching; permission gating; per-result navigation to the customer (needs 1.3's route); sidebar count badge;
- **Effort:** S — **Priority:** P2

### 1.9 Store-scoped customer list (store detail "Customers" tab) — `missing`
- **What the user could do (legacy):** On a store's detail page, a **Customers** tab listed the distinct customers who purchased from that store, with per-store order counts, name/email/phone/status/since columns, pagination, and links into the customer detail. The same page shows a "Customers — unique buyers" metric card.
- **Legacy route:** `GET /management/stores/{store}/tab/customers` (`management.stores.tab`), page `GET /management/stores/{store}` (`management.stores.show`)
- **Legacy controller:** `Management\StoreTabController@customers`; `StoreController@show` (`$customerCount`)
- **Legacy views:** `resources/views/management/stores/tabs/customers.blade.php`, `resources/views/management/stores/show.blade.php`
- **New API:** the management store `show` payload includes a `customers_count` key, but `Api\V1\Management\StoreController@show` only calls `loadCount(['products','orders'])` and the `Store` model has no `customers` relation — **the value is always null**. No store-customers list endpoint.
- **New SPA:** no store detail route at all in `storify-management` (`/stores` list only); no tab UI.
- **Missing:** a customers list scoped to a store (api + UI), the working `customers_count`, and the store detail screen itself (cross-domain dependency: mgmt-stores audit).
- **Effort:** M — **Priority:** P2 — *cross-domain: store detail page is owned by the stores audit; the customer slice is small once it exists.*

---

## 2. Customer-facing messaging and notifications

### 2.1 Suspension / activation emails to the customer (admin) — `missing`
- **What the user could do (legacy):** When a **platform admin** suspended a customer, the customer received `CustomerAccountSuspendedMail` including the admin's reason; on activation they received `CustomerAccountActivatedMail`. The business-side controller never sent these (asymmetry to preserve or fix deliberately — see gaps).
- **Legacy route:** `POST /office/customers/{customer}/suspend|activate`
- **Legacy controller:** `Admin\CustomerController@suspend` / `@activate`
- **Legacy views:** `resources/views/emails/customer-account-suspended.blade.php`, `resources/views/emails/customer-account-activated.blade.php` (mailables `app/Mail/CustomerAccountSuspendedMail.php`, `CustomerAccountActivatedMail.php`)
- **New API:** no admin customer controller/routes at all; no mail is dispatched from `Api\V1\Management\CustomerController` either.
- **New SPA:** n/a.
- **Missing:** everything (endpoints + mail wiring). The mailables and Blade templates already exist in the repo.
- **Side effects:** queued email to the customer's address.
- **Effort:** S — **Priority:** P2

### 2.2 Order status-change emails to the customer (cross-domain, customer-facing) — `missing`
- **What the user could do (legacy):** Every guarded order transition from management (`OrderController@notifyOrderUpdate`) queued `OrderStatusUpdatedMail` to the customer, store owner and platform admin; the admin order screen queued `CustomerOrderStatusUpdatedMail` to the customer. This is the main way a business "talks" to its customers.
- **Legacy route:** `POST /management/orders/{order}/accept|process|dispatch|deliver|complete|cancel|return`; `Admin\OrderController@updateStatus`
- **Legacy controller:** `Management\OrderController@notifyOrderUpdate`, `Admin\OrderController@updateStatus`
- **New API:** `Api\V1\Management\OrderController` sends **no mail** on any transition (`updateStatus`/`updatePaymentStatus` are silent).
- **New SPA:** n/a.
- **Missing:** mail wiring on order transitions (ownership: mgmt-orders audit; flagged here because it is the customer-facing messaging surface).
- **Effort:** S — **Priority:** P1 (owned by orders)

---

## 3. Admin (platform) customer management

The whole module is absent from the new stack: `routes/api/v1/admin.php` has no customers group, `app/Http/Controllers/Api/V1/Admin/` has no `CustomerController`, `storify-admin/src/views/` has no customer view, `src/router/index.ts` has no route, `AdminLayout.vue` has no nav entry, `src/api/endpoints.ts` has no `customersApi`. The only customer artefact in the admin stack is the dashboard count (`Api\V1\Admin\DashboardController` `customers` = `Customer::count()`, rendered as a tile).

### 3.1 Admin customer list with filters, stats and sort — `missing`
- **What the user could do (legacy):** Browse all platform customers (20/page) with four stat cards (Total, Active, Suspended, Total Orders; a "new this month" count was computed but not rendered), a **Filter Customers** modal (search by name/email/phone/account_id, status incl. **Deleted**, country), filter-active indicator and Clear All, a table with Customer (avatar, full name, **company name**, account_id), Email, Phone, Location, Orders count pill, Status badge, Joined date and an actions menu (View, Edit, Suspend/Activate); the controller supported `sort_by`/`sort_order` params (no UI control in the Blade).
- **Legacy route:** `GET /office/customers` (`admin.customers.index`)
- **Legacy controller:** `Admin\CustomerController@index`
- **Legacy views:** `resources/views/admin/customers/index.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the full screen (endpoint + view + nav entry + permission `admin.customers` already seeded).
- **Effort:** M — **Priority:** P1

### 3.2 Admin customer detail (stats, address, orders, transactions, activity) — `missing`
- **What the user could do (legacy):** Open any customer and see four stat cards (Total Orders, Completed, **Total Spent** [sum of orders with confirmed transactions], Pending), a Customer Information card (avatar/initials, full name, status, email, phone, **company name**, member since), an **Address Information** card (street address, apartment/unit, city, state, ZIP, country), a Recent Orders table (order #, store, item count, total, status, date — linked to admin order detail), a Recent Transactions table (reference, payment method, amount, status, date) and an Activity Log card (actor, description, relative + absolute time). Header actions: Edit, Suspend, Activate.
- **Legacy route:** `GET /office/customers/{customer}` (`admin.customers.show`)
- **Legacy controller:** `Admin\CustomerController@show`
- **Legacy views:** `resources/views/admin/customers/show.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the full screen. Note the API should return addresses, transactions and activity — none of that exists in any customer payload today.
- **Effort:** M — **Priority:** P1

### 3.3 Admin customer edit (fields, status, last login, account summary) — `missing`
- **What the user could do (legacy):** Edit First/Last name, Email, Phone, Location, Status (Active/Suspended/Deleted) with the same server rules as business (status Active → mark verified; other → clear verification); see a "Last login" badge (`last_login`, "Never" fallback) and an Account Summary (Total Orders, Verified Email badge, Last Updated).
- **Legacy route:** `GET /office/customers/{customer}/edit` (`admin.customers.edit`), `PUT /office/customers/{customer}` (`admin.customers.update`)
- **Legacy controller:** `Admin\CustomerController@edit` / `@update`
- **Legacy views:** `resources/views/admin/customers/edit.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the full form + endpoint, and `last_login` in any payload.
- **Side effects (legacy):** toggles `email_verified_at` via status.
- **Effort:** S — **Priority:** P1

### 3.4 Admin suspend / activate with reason modal, email and audit — `missing`
- **What the user could do (legacy):** From the list, detail or edit screen, open a Suspend modal (warning copy, customer name, **required reason** textarea, note that the customer will receive the reason by email) or an Activate modal (confirmation copy, note about the notification email). Submitting writes status + clears/sets email verification in a transaction, writes an ActivityLog (`customer_suspended`/`customer_activated`, actor = admin, old/new values, IP), logs, and emails the customer (tolerating mail failure).
- **Legacy route:** `POST /office/customers/{customer}/suspend` (`admin.customers.suspend`), `POST /office/customers/{customer}/activate` (`admin.customers.activate`)
- **Legacy controller:** `Admin\CustomerController@suspend` / `@activate`
- **Legacy views:** `resources/views/admin/customers/{index,show}.blade.php` (modals + `showSuspendModal`/`showActivateModal` JS)
- **New API:** none.
- **New SPA:** none.
- **Missing:** endpoints, modals, audit writes, customer emails.
- **Effort:** S — **Priority:** P1

---

## 4. Cross-cutting

### 4.1 Dashboard customer metrics — `partial`
- **What the user could do (legacy):** Business dashboard showed a **Customers** metric card with the total and an "N active" subtitle (active = ordered within the recent-activity window, scoped to the current user's orders and the selected store). Admin dashboard showed a **Customers** tile (total; `new_customers_this_month` and `active_customers` were computed but not all rendered).
- **Legacy route:** `GET /management/dashboard` (`management.dashboard`), `GET /office/dashboard` (`admin.dashboard`)
- **Legacy controller:** `Management\DashboardController@index` (`$totalCustomers`, `$activeCustomers`), `Admin\AdminDashboardController@index`
- **Legacy views:** `resources/views/management/dashboard.blade.php`, `resources/views/admin/index.blade.php`
- **New API:** `Api\V1\Management\DashboardController` returns `customers.total` (business-wide); `Api\V1\Admin\DashboardController` returns `stats.customers`.
- **New SPA:** `DashboardView.vue` (management) renders a Customers tile; `storify-admin DashboardView.vue` renders a Customers tile.
- **Missing (business):** the "active customers" metric and its store/current-user scoping. **(admin):** new-this-month/active breakdowns if wanted.
- **Effort:** S — **Priority:** P2

### 4.2 Customer self-service password reset — `exists` (no business/admin-triggered reset in either stack)
- **What the user could do (legacy):** A customer (not the business/admin) could request an OTP password reset and set a new password via `/account/forgot-password` → `/account/reset-password/verify` → `/account/reset-password/{token}`; customer registration/verification also lives there. Neither the business CustomerController nor the admin CustomerController ever triggered or reset a customer's password — there is **no admin/business "send password reset" feature to port**.
- **Legacy route:** `GET/POST /account/forgot-password`, `GET/POST /account/reset-password/verify`, `GET/POST /account/reset-password/{token}` (`account.*`)
- **Legacy controller:** `Account\AccountController@showForgotPassword|sendResetOtp|showVerifyOtp|verifyOtp|showResetPassword|resetPassword`
- **Legacy views:** `resources/views/account/**` (customer-facing storefront layout)
- **New API:** `POST /api/v1/storefront/auth/forgot-password`, `POST /api/v1/storefront/auth/reset-password` → `Api\V1\Auth\CustomerAuthController` (OTP-based) — implemented.
- **New SPA:** `storify-storefront` `src/views/account/AccountForgotView.vue` (route `/account/forgot-password`) and `AccountResetView.vue` (route `/account/reset-password`); endpoints in `src/api/endpoints.ts`.
- **Missing:** nothing to build in this domain. If the product wants a business/admin-initiated reset link or OTP, that is **net-new** (neither legacy had it).
- **Effort:** S (verify parity only) — **Priority:** P2

### 4.3 Customers nav entry with live count badge — `partial`
- **What the user could do (legacy):** Business sidebar showed a "Customers" item with a count badge (business-wide; restricted staff saw the count scoped to their stores). Admin sidebar showed a Customers item with the platform-wide customer count.
- **Legacy route:** n/a (layout data via `AppServiceProvider` view composer; admin sidebar inline `Customer::count()`)
- **Legacy controller:** n/a (composer) / `Admin\CustomerController@index`
- **Legacy views:** `resources/views/management/components/sidebar.blade.php`, `resources/views/admin/components/sidebar.blade.php`
- **New API:** counts exist only in the two dashboard payloads.
- **New SPA:** management `AppLayout.vue` has the Customers nav item (no badge); admin `AdminLayout.vue` has no Customers node at all.
- **Missing:** badges; the admin nav node (blocked by 3.1).
- **Effort:** S — **Priority:** P2

---

## 5. Gaps worth calling out

1. **The admin customer module does not exist at all.** No `routes/api/v1/admin.php` customers group, no `Api\V1\Admin\CustomerController`, no admin SPA view/route/nav/endpoint. The legacy admin screen is the *fuller* of the two legacy screens (address card, transactions, activity log, company name, last login), so this is 4 missing features (3.1–3.4) plus the emails in 2.1, and it is the single biggest hole in this domain.
2. **`customers message` permission is seeded but was never implemented in legacy.** `SpatiePermissionSeeder` defines `customers => [view, edit, suspend, message]` and grants it to several roles (Business Owner, Customer Support, …), but no route, controller or Blade ever used it. Do **not** build a "message customer" feature believing legacy had one; either implement it deliberately as net-new or drop it from the permission list so role editors stop implying it exists. (The Staff dashboard tile copy "…send messages" is likewise aspirational.)
3. **There is no customer export in legacy.** Nothing under `Management\CustomerController`/`Admin\CustomerController`, no export route, no CSV/PDF button in either customer view. The only CSV machinery in the repo is accounting reports (`AccountingReportController::csv`). Customer export, if wanted, is **net-new** — the audit scope asked about it, and the honest answer is "legacy never had it".
4. **There is no customer "create" screen in legacy either.** Customers enter the system via (a) storefront registration, (b) POS walk-in auto-create (`Customer::firstOrCreate` by phone in the legacy POS sale), (c) the invoice form's "Save as customer for future invoices" checkbox, and (d) admin/business edits. In the new stack (a) exists (`CustomerAuthController@register`) and (b) exists (`ProcessPosSale::resolveCustomer`). **(c) is gone**: the management API has no invoices at all, and the new POS invoice `store` accepts an existing `customer_id` but offers no save-as-customer path. If customer growth from invoicing matters, this is a quiet regression.
5. **Restricted-staff scoping was dropped.** Legacy business list/show scoped customers to those with orders in the staff member's assigned stores for `isRestrictedStaff()` users, and the sidebar count did the same. The new API scopes only by `business_id`, so a Store Associate with `customers view` (it is granted to Store Associate, Cashier, Delivery Agent roles in the seeder) can enumerate every customer of the business. That is both a parity gap and a tenant-privacy concern.
6. **Suspension reasons are validated then thrown away** in the new API (`'reason' => ['nullable', ...]`, never persisted), and the SPA never asks for one. Legacy made the reason mandatory and written to `ActivityLog` for both audiences. Without a stored reason, the audit trail cannot answer "why was this customer suspended?" — build a column or an activity entry before the admin module lands.
7. **No customer route in the SPA means no deep links.** The detail is a modal; `searchApi` results and (future) order-detail customer links can only dump the user on `/customers`. Support workflows need `/customers/:accountId`.
8. **Spend definition drift.** Business legacy `total_spent` = all orders; new API = completed only; admin legacy = orders with confirmed transactions. Three definitions of the same tile; pick one and label it (e.g. "Total spent (completed)").
9. **Legacy bugs not to reproduce:** (a) business-side `ActivityLog` writes used `user_id: null` and stashed the actor in `metadata.user_id` — the admin controller got it right (`Auth::id()`); (b) the admin index computed `this_month` and supported `sort_by`/`sort_order` but the Blade exposed neither; (c) `management/customers/show.blade.php` is passed `$stats`, `$transactions` and `$activityLogs` that it never renders (dead data — the admin view is the reference for what those blocks should look like); (d) the admin list/detail suspend modals build URLs from `account_id` and both controllers bind by `account_id` (model `getRouteKeyName`), which is why the new API's account_id binding is right — keep it consistent.
10. **Latent new-stack bug:** `Api\V1\Management\StoreController@show` returns `customers_count` but never loads it (`loadCount(['products','orders'])`, no `customers` relation on `Store`) — always `null`. Fix before any store UI consumes it (see 1.9).
11. **Business vs admin email asymmetry (legacy):** only the admin suspended/activated with an email; a business suspension left the customer silently locked out. Decide product behaviour explicitly when implementing 1.5/1.6 rather than copying the asymmetry by accident.
12. **Status vocabulary:** `CustomerStatus` is `ACTIVE|SUSPENDED|DELETED` with labels and badge classes already in the enum. The new API lowercases status in payloads and the SPA `StatusBadge` renders it; there is no transition to `DELETED` anywhere in the new stack (see 1.7/legacy edit form), and no delete/restore action to port beyond the edit-form status value.

---

## Appendix A — Legacy → new endpoint map (this domain)

| Purpose | Legacy | New | Status |
|---|---|---|---|
| Customer list | `GET /management/customers` | `GET /api/v1/management/customers` | partial |
| Customer detail | `GET /management/customers/{customer}` | `GET /api/v1/management/customers/{customer}` | partial |
| Customer edit form | `GET /management/customers/{customer}/edit` | n/a (modal in list) | partial |
| Customer update | `PUT /management/customers/{customer}` | `PUT /api/v1/management/customers/{customer}` | partial |
| Suspend | `POST /management/customers/{customer}/suspend` | `POST /api/v1/management/customers/{customer}/suspend` | partial |
| Activate | `POST /management/customers/{customer}/activate` | `POST /api/v1/management/customers/{customer}/activate` | partial |
| Store customers tab | `GET /management/stores/{store}/tab/customers` | none | missing |
| Global search (customers group) | `GET /management/search` | `GET /api/v1/management/search` | partial |
| Admin list | `GET /office/customers` | none | missing |
| Admin detail | `GET /office/customers/{customer}` | none | missing |
| Admin edit/update | `GET/PUT /office/customers/{customer}/edit` | none | missing |
| Admin suspend/activate | `POST /office/customers/{customer}/suspend|activate` | none | missing |
| Customer self-service reset | `/account/forgot-password` … | `POST /api/v1/storefront/auth/forgot-password|reset-password` | exists |

New API routes (business) live at `routes/api/v1/management.php` lines 82–95, guarded by `permission:customers view|edit|suspend`. Admin API has no equivalent.

## Appendix B — Permission map (seeded, shared `SpatiePermissionSeeder`)

| Ability | Used by legacy | Used by new API | Notes |
|---|---|---|---|
| `customers view` | list, show | list, show | granted to Business Owner, Store Manager, Customer Support, Store Associate, Cashier, Delivery Agent |
| `customers edit` | edit, update | update | — |
| `customers suspend` | suspend, activate | suspend, activate | — |
| `customers message` | **nothing** | **nothing** | phantom permission (see gaps #2) |
| `admin.customers` | all `/office/customers/*` | **nothing** | module missing (see gaps #1) |

## Appendix C — Status transitions (legacy)

| Transition | Trigger | Reason | Email | ActivityLog | Email verified |
|---|---|---|---|---|---|
| ACTIVE → SUSPENDED | business suspend / admin suspend | required | admin only | yes | cleared |
| SUSPENDED → ACTIVE | business activate / admin activate | — | admin only | yes | set |
| any → DELETED | business/admin edit form status select | — | none | none | cleared |
| DELETED → ACTIVE | business/admin edit form / activate | — | admin only (activate) | activate only | set |

New stack supports only ACTIVE ↔ SUSPENDED via dedicated buttons; edit form cannot set status at all.

---

*Method: legacy `Management\CustomerController`, `Admin\CustomerController`, `StoreTabController@customers`, management `SearchController`, `Customer` model, `CustomerStatus` enum, routes `v1/management.php` + `v1/admin_dashboard.php` + `v1/account.php`, Blade views `management/customers/**`, `admin/customers/**`, `management/stores/tabs/customers.blade.php`, sidebars/header, mails and `emails/customer-account-*.blade.php`, and the permission seeder read in full. New stack: `routes/api/v1/management.php`, `routes/api/v1/admin.php`, `Api\V1\Management\{Customer,Search,Store,Dashboard}Controller`, `Api\V1\Admin\DashboardController`, `Api\V1\Pos\{CustomerController,ProcessPosSale}`, management SPA router/`CustomersView.vue`/`endpoints.ts`/`SearchModal.vue`, admin SPA router/views/nav/endpoints, and storefront auth routes/views verified by grep/read.*
