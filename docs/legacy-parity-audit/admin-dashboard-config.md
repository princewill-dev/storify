# admin-dashboard-config — Legacy → New Stack Feature Inventory

**Domain:** Superadmin home (platform dashboard) and platform configuration (audience: admin)
**Legacy source of truth:** `storify-api` @ `routes/v1/admin_dashboard.php` + `app/Http/Controllers/Admin/{AdminDashboardController,AdminSettingsController,ActivityLogController,DeliveryIntervalController}.php` + `resources/views/admin/{index,layout}.blade.php`, `resources/views/admin/advanced/settings.blade.php`, `resources/views/admin/activity_logs/index.blade.php`, `resources/views/admin/settings/delivery_intervals.blade.php`
**New stack:** `storify-api` (`routes/api/v1/admin.php`, `app/Http/Controllers/Api/V1/Admin/DashboardController.php`) + `storify-admin` (`src/router/index.ts`, `src/views/DashboardView.vue`, `src/api/endpoints.ts`, `src/layouts/AdminLayout.vue`)
**Status vocabulary:** `exists` = fully usable in the new stack; `partial` = endpoint or screen present but missing actions/fields/validations from legacy; `missing` = absent from the new stack.

---

## Executive summary

The superadmin rewrite currently ships **one** screen for this domain: `DashboardView.vue`, backed by a single endpoint, `GET /api/v1/admin/dashboard`. Platform settings and the activity log — the two configuration/oversight pillars of the legacy Superadmin panel — have **no API routes, no controllers and no SPA screens** in the new stack. The admin SPA's sidebar "Settings" section contains only Coupons; there is no "Platform Settings", no "Activity Logs" and no "Delivery Intervals" entry anywhere in `storify-admin`.

On the dashboard itself the new API is a re-think, not a port: it drops the stock-value metric, the per-store today/MTD table, the pending-transfers panel, the recent-transactions and recent-orders panels, and the store filter; it adds `recent_businesses`, monthly revenue/orders series and 7/90-day ranges. Two of the three legacy charts survive essentially intact.

| Area | Legacy features | exists | partial | missing |
|---|---|---|---|---|
| Admin dashboard | 10 | 2 | 5 | 3 |
| Platform settings | 7 | 0 | 0 | 7 |
| Activity log / audit | 2 | 0 | 0 | 2 |
| Settings-screen extras (delivery intervals) | 1 | 0 | 0 | 1 |

**Headline numbers:** 2 of 20 audited features are fully usable in the new stack; 5 are partial (dashboard read-outs only); 13 are entirely missing. The P0 gaps are **platform settings** (branding, homepage store, default currency, store limits, trial rules, SEO — none editable at all) and **activity log visibility** (the audit trail exists in the DB but no new endpoint or screen reads it).

**Route-level reality check (new API, `routes/api/v1/admin.php`):** the admin group contains `dashboard`, `search`, `businesses*`, `stores*`, `users*`, `transactions*`, `coupons*` — and nothing else. There is no `settings`, no `activity-logs`, no `delivery-intervals`.

---

## 1. Admin dashboard — `GET /office/dashboard`

Legacy page: `resources/views/admin/index.blade.php`, served by `AdminDashboardController@index`. Available to any platform admin (`auth` + `platform.admin` middleware; no per-permission gate on the route). All figures are rendered server-side; charts are ApexCharts fed by inline JSON.

### 1.1 Dashboard KPI tile row (6 tiles) — `partial`
- **What the admin could do (legacy):** Six top tiles, each recomputed against the active **store filter**: *Today's Revenue* (confirmed transactions today), *Today's Orders*, *Revenue MTD* (confirmed transactions this month), *Stock Value* (Σ qty × product amount across active products with qty > 0), *Active Stores*, *Customers* (total).
- **Legacy route:** `GET /office/dashboard` (`admin.dashboard`)
- **Legacy controller/view:** `Admin\AdminDashboardController@index`; `resources/views/admin/index.blade.php` (KPI row, lines 27–59)
- **New API:** `GET /api/v1/admin/dashboard` → `Api\V1\Admin\DashboardController@index`. Provides `revenue_today`, `orders_today`, `revenue_mtd`, `active_stores`, `businesses`, `customers` — but **no equivalent of Stock Value**.
- **New SPA:** `storify-admin/src/views/DashboardView.vue` (KPI row). Sixth tile is **Businesses**, not Stock Value; tiles are clickable links (new-stack nicety).
- **Missing:** Stock Value metric entirely (neither API nor SPA); store filter for every tile; the sixth KPI slot is repurposed so the legacy stock-value read-out has no replacement anywhere on the dashboard.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 1.2 Dashboard stats grid (8 metric cards) — `partial`
- **What the admin could do (legacy):** Eight cards with secondary lines: *Total Revenue* (+ all-transaction count), *Orders* (+ pending), *Businesses* (+ active), *Stores* (+ active · warehouse count), *Products* (+ active · total units in stock), *Low Stock* (qty ≤ 10, + out-of-stock count), *Staff* (business users who are not owners, + open POS sessions), *KYC Pending* with a **"Review →" link** into the KYC submissions list filtered to `submitted`.
- **Legacy route/controller/views:** as 1.1 (`admin/index.blade.php` lines 73–115, backed by the `$stats` array and `$totalStock`, `$lowStockCount`, `$outOfStockCount`).
- **New API:** same endpoint; stats `businesses`, `active_businesses`, `stores`, `active_stores`, `users`, `staff`, `customers`, `products` (active only), `low_stock` (qty **1–5** only), `orders`, `orders_pending`, `orders_today`, `transactions` (confirmed only), `revenue_*`, `kyc_pending`.
- **New SPA:** stats grid in `DashboardView.vue` (lines 219–260); KYC tile is a plain `div` with "Submissions awaiting review" — **no link** to the KYC queue.
- **Missing:** warehouse count; units-in-stock total; out-of-stock count; open POS sessions; KYC "Review →" deep link; low-stock threshold changed silently from legacy ≤10 to 1–5; products count is active-only (legacy showed total + active); transaction count is confirmed-only (legacy counted all). `open_pos_sessions`, `total_warehouses`, `out_of_stock`, `stock` have no API fields at all.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 1.3 Daily Revenue chart (30-day area chart) — `exists`
- **What the admin could do (legacy):** Area chart of confirmed transaction totals per day for the last 30 days, with ₦-abbreviated axis and tooltip; respects the store filter.
- **Legacy route/controller/views:** as 1.1; `admin/index.blade.php` lines 62–66 + chart script lines 277–288; data from `$dailyRevenue`.
- **New API:** `daily_revenue` series (server-side zero-filled) in `GET /api/v1/admin/dashboard`; selectable 7/30/90-day window via `?days=`.
- **New SPA:** `DashboardView.vue` — same ApexCharts area chart (lines 40–55), header "Daily Revenue ({{ days }} days)".
- **Missing:** only the store filter (tracked in 1.10). The chart itself is fully usable and gains a 7/90-day selector.
- **Effort:** — — **Priority:** P1 (already shipped)

### 1.4 Payment Methods donut chart — `partial`
- **What the admin could do (legacy):** Donut of confirmed transaction totals grouped by payment method for the selected **from/to date range** (defaults: start of month → now), with method labels, ₦-abbreviated data labels and tooltips; respects the store filter.
- **Legacy route/controller/views:** as 1.1; `admin/index.blade.php` lines 67–70 + chart script lines 290–298; data from `$paymentBreakdown` (the **only** widget the dashboard's from/to filter actually scoped).
- **New API:** `payment_breakdown` in the same endpoint — **all-time**, no date range and no store filter; method name resolved via `leftJoin` with `'Other'` fallback; ordered by total desc.
- **New SPA:** donut in `DashboardView.vue` (lines 57–69).
- **Missing:** the date-range scoping (the legacy dashboard's date filter existed almost solely for this chart); store scoping. Because it is all-time, the chart's numbers will not match any of the KPI windows — a behavioural regression for admins who used it to read a month's payment mix.
- **Effort:** S — **Priority:** P2

### 1.5 Daily Orders chart (30-day bar chart) — `exists`
- **What the admin could do (legacy):** Bar chart of order counts per day for the last 30 days; store filter applied.
- **Legacy route/controller/views:** as 1.1; `admin/index.blade.php` lines 117–121 + script lines 300–308; data from `$dailyOrders`.
- **New API:** `daily_orders` series, 7/30/90-day window (`?days=`).
- **New SPA:** bar chart in `DashboardView.vue` (lines 71–83).
- **Missing:** store filter only (1.10).
- **Effort:** — — **Priority:** P1 (already shipped)

### 1.6 Store Performance table — `partial`
- **What the admin could do (legacy):** Table of **all non-deleted stores** sorted by MTD revenue desc, one row per store: Store name (links to the admin store detail) + owner name; **Revenue Today**; **Revenue MTD**; **Orders Today**; **Products** count; **POS** status pill (green "Live" when an active POS session exists, else "Offline"); **Last Sale** (relative time). Store filter narrows to one row.
- **Legacy route/controller/views:** as 1.1; `admin/index.blade.php` lines 123–169; built from the `$stores` query (per-store `withCount`/`withSum` + POS/last-order enrichment).
- **New API:** `top_stores` — **top 8 active stores only**, by all-time confirmed revenue; fields `name`, `business`, `orders_count` (all-time), `revenue` (all-time).
- **New SPA:** "Store Performance" table in `DashboardView.vue` (lines 270–299) with columns Store (name + business), Orders, Revenue. Rows are not links.
- **Missing:** revenue today, revenue MTD, orders today, product count, POS live/offline status, last-sale age; coverage of all stores (legacy listed every store; new lists 8 actives); store-name drill-through to the store detail; store filter. The two remaining columns are all-time, not today/MTD — so a store's daily pulse is not observable from the new dashboard.
- **Effort:** M — **Priority:** P1

### 1.7 Pending Stock Transfers panel — `missing`
- **What the admin could do (legacy):** Right-hand panel listing the 5 latest transfers in `pending`/`approved` status: transfer code (links to the transfer detail), "from → to" location names, status pill (approved vs pending). Header shows live counters: *pending*, *dispatched today*, *received today*.
- **Legacy route/controller/views:** as 1.1; `admin/index.blade.php` lines 171–201; `$pendingTransfers` + `$transferStats`.
- **New API/SPA:** nothing — no transfer fields in `GET /admin/dashboard`, no panel in `DashboardView.vue`; the admin SPA has no transfers screen either.
- **Missing:** the panel and the transfer stats; the superadmin loses at-a-glance visibility of stock-transfer workload. (Note: transfers are otherwise a separate domain; here only the dashboard panel is audited.)
- **Effort:** S/M (panel) — **Priority:** P2

### 1.8 Recent Transactions panel — `missing`
- **What the admin could do (legacy):** Panel of the 10 latest **confirmed** transactions with reference (truncated mono), store name, amount; "View all" link to the transactions list.
- **Legacy route/controller/views:** as 1.1; `admin/index.blade.php` lines 203–229; `$stats['recent_transactions']`.
- **New API/SPA:** no `recent_transactions` field; no panel. The transactions list page/endpoint exists in the SPA, but the dashboard read-out was dropped.
- **Missing:** the panel (API field + SPA card). The "View all" pattern is replaced by `recent_businesses`, which shows a different entity entirely.
- **Effort:** S — **Priority:** P2

### 1.9 Recent Orders panel — `missing`
- **What the admin could do (legacy):** Panel of the 10 latest orders: order number, customer (or "Walk-in"), store, total, status pill; "View all" link to the orders list.
- **Legacy route/controller/views:** as 1.1; `admin/index.blade.php` lines 231–263; `$stats['recent_orders']`.
- **New API/SPA:** no `recent_orders` field; no panel; the admin SPA has no order views at all.
- **Missing:** the panel (API field + SPA card).
- **Effort:** S — **Priority:** P2

### 1.10 Dashboard filter bar (store selector + date range) — `partial`
- **What the admin could do (legacy):** Filter form with `from`/`to` date inputs and an **All Stores** dropdown (auto-submitting on change), a Filter button and a Clear link. The **store filter** scoped almost every widget: KPI tiles, stock value/quantities, low/out-of-stock counts, MTD revenue/orders, both daily charts, payment breakdown, the pending-transfers panel and the store table. The **date range** only scoped the payment-breakdown chart (defaults: start of month → today).
- **Legacy route/controller/views:** `AdminDashboardController@index` (`$filterStoreId`, `$fromDate`, `$toDate`); `admin/index.blade.php` lines 10–24.
- **New API:** only `days` (7/30/90) — no `store_id`, no `from`/`to`.
- **New SPA:** 7/30/90 segmented control + Auto-refresh toggle (30 s polling) + manual Refresh button + "updated at" timestamp (`DashboardView.vue` lines 136–163). These are new-stack additions; the legacy store selector and arbitrary date inputs are gone.
- **Missing:** store scoping across the dashboard (the big one — a multi-store operator cannot slice the dashboard by store any more); arbitrary date-range selection; Clear/reset. New-stack-era extras (auto-refresh, range pills) are retained.
- **Effort:** M — **Priority:** P1

---

## 2. Platform settings — `GET/POST /office/settings`

Legacy page: `resources/views/admin/advanced/settings.blade.php`, served by `AdminSettingsController@edit/@update`. Gated by `permission:admin.settings` **and** a hard superadmin role check inside both controller methods. The form is a two-tab Alpine page (General info / SEO Settings) with tab state persisted in `localStorage` + URL hash; one POST endpoint saves all fields (`SettingsUpdateRequest` validation). The `settings` table is a singleton row.

### 2.1 General info tab — company profile, branding & contact — `missing`
- **What the admin could do (legacy):** Edit and save: Company Logo (PNG/JPG/WEBP, 2 MB, live preview, old file deleted on replace), Favicon (ICO/PNG, 512 KB label / 1 MB validation, preview), Company Certificate (PDF/JPG/PNG/WEBP, 5 MB; PDF rendered as "View PDF" link, images previewed), Company Name, Company Description ("shown in the greeting modal"), Support Email, Support Phone, Company Address (textarea), Branch Address (textarea). All fields fall back to `old()` on validation errors.
- **Legacy route:** `GET /office/settings` (`admin.settings.edit`), `POST /office/settings` (`admin.settings.update`)
- **Legacy controller:** `Admin\AdminSettingsController@edit/@update`; validation `App\Http\Requests\Admin\SettingsUpdateRequest`
- **Legacy view:** `resources/views/admin/advanced/settings.blade.php`
- **New API:** none — no settings route/controller in `routes/api/v1/admin.php` or `Api/V1/**`.
- **New SPA:** none — no settings view or nav entry in `storify-admin` (the "Settings" sidebar section holds only Coupons).
- **Missing:** the entire screen and endpoint, including the multipart file uploads, per-file size/mime validation, old-file cleanup, and the branding that every admin view consumes via the cached `company_settings` (logo/favicon render in the legacy admin shell; the SPA hardcodes "Storify").
- **Side effects (legacy):** `Cache::forget('company_settings')`; `Log::info` entries `settings_viewed` / `settings_update_requested` / `settings_updated` with a changed-keys diff (values redacted); queued `SettingsUpdated` mailable to the first superadmin when anything changed.
- **Effort:** L — **Priority:** P0

### 2.2 General info tab — store creation limit & free-trial configuration — `missing`
- **What the admin could do (legacy):** Set *Store Creation Limit* (number, min 1, default 5 — "maximum number of stores a user can create") and *Free Trial*: an enable/disable toggle that reveals *Duration* (1–90 days, default 7). Controls the platform-wide signup/trial rules.
- **Legacy route/controller/views:** `AdminSettingsController@edit/@update`, `SettingsUpdateRequest` (`store_creation_limit` min:1; `trial_enabled` boolean; `trial_days` 1–90); `advanced/settings.blade.php` lines 102–129.
- **New API/SPA:** none.
- **Missing:** the fields, validation and storage; also their downstream consumers (signup trial provisioning, store-creation guard) have no admin control surface in the new stack.
- **Side effects (legacy):** cache bust + superadmin email on change.
- **Effort:** M — **Priority:** P1

### 2.3 General info tab — Homepage Store selection — `missing`
- **What the admin could do (legacy):** Pick which store populates the public homepage ("Products on the homepage will be populated from this store") from a dropdown of all non-deleted stores.
- **Legacy route/controller/views:** `AdminSettingsController@edit/@update` (`main_store_id`, `exists:stores,id`); `advanced/settings.blade.php` lines 131–144. Cached as `admin_main_store` (300 s) and injected into admin views as `$adminMainStore`.
- **New API/SPA:** none.
- **Missing:** the selector and endpoint; the platform's homepage merchandising cannot be pointed at a store from the new UI.
- **Side effects (legacy):** `Cache::forget('admin_main_store')` when changed; superadmin email.
- **Effort:** S — **Priority:** P1

### 2.4 General info tab — Default Currency — `missing`
- **What the admin could do (legacy):** Choose the site-wide default currency from the currencies list (name + code + symbol); saving flips `is_default` on the selected currency and clears the flag from all others ("sets the default currency used across the site").
- **Legacy route/controller/views:** `AdminSettingsController@edit/@update` (`default_currency_id`, `exists:currencies,id`, plus a `Currency::where('is_default')->update(...)` swap); `advanced/settings.blade.php` lines 156–171.
- **New API/SPA:** none.
- **Missing:** the selector, validation and the is_default swap; no other new-stack screen manages currencies.
- **Side effects (legacy):** `company_settings` cache bust; superadmin email.
- **Effort:** S — **Priority:** P1

### 2.5 General info tab — Greeting Modal configuration — `missing`
- **What the admin could do (legacy):** Enable/disable the visitor welcome modal and choose its frequency: Never / Always (every page load) / Once Per Session / Once Per Day / Once Per Week / Once Per Month. Copy explains the modal shows company information and services (fed by Company Description).
- **Legacy route/controller/views:** `AdminSettingsController@edit/@update` (`greeting_modal_enabled` boolean, `greeting_modal_frequency`); `advanced/settings.blade.php` lines 173–206.
- **New API/SPA:** none.
- **Missing:** toggle, frequency select, storage and any storefront consumer wiring exposed to admin.
- **Side effects (legacy):** cache bust + superadmin email on change.
- **Effort:** S — **Priority:** P2

### 2.6 SEO Settings tab (Open Graph) — `missing`
- **What the admin could do (legacy):** A dedicated tab editing social-sharing metadata: OG Title, OG Description (textarea), OG Image upload (PNG/JPG/WEBP, 2 MB, preview, default fallback image), OG URL (canonical URL, pre-filled with app URL), OG Type (website / article / product).
- **Legacy route/controller/views:** `AdminSettingsController@edit/@update` (SEO block); `SettingsUpdateRequest` (`og_image` mime/size); `advanced/settings.blade.php` lines 251–323.
- **New API/SPA:** none.
- **Missing:** the tab, the fields, the image upload and cleanup. The tab-localStorage persistence behaviour dies with it.
- **Side effects (legacy):** old OG image deleted on replace; cache bust; superadmin email.
- **Effort:** M — **Priority:** P1

### 2.7 Platform API keys (name/value vault) — `missing`
- **What the admin could do (legacy):** The controller accepts and stores an arbitrary associative array of API keys, either as parallel `api_key_names[]` / `api_key_values[]` inputs or a legacy `api_keys` array, blank rows skipped, values excluded from the changed-fields log (only key names are diffed). **However, the settings Blade renders no API-keys inputs** — the feature is unreachable through the legacy UI and only usable by a crafted POST. Judge: obsolete as a UI feature, but the storage contract (`settings.api_keys`) remains and is read elsewhere.
- **Legacy route/controller/views:** `AdminSettingsController@update`; `advanced/settings.blade.php` (no inputs); `Setting` model cast `api_keys => array`.
- **New API/SPA:** none.
- **Missing:** the whole path. Recommendation: do not port the UI as-is; if third-party keys must be managed, design a dedicated, masked key-management screen instead.
- **Side effects (legacy):** changed-keys-only audit entry (values never logged).
- **Effort:** S — **Priority:** P2 (judgement: legacy UI was already absent; defer)

---

## 3. Activity log & auditing

### 3.1 Activity Log viewer (list, filters, pagination) — `missing`
- **What the admin could do (legacy):** Open **Activity Logs** (`permission:admin.activity-logs` + hard superadmin check). Table of audit entries, newest first, 50 per page with query-string preserved: **When** (Y-m-d H:i), **User** (name or —), **Action** (pill), **Description** (truncated with title tooltip), **IP**, **User Agent** (truncated). A **Filter** modal offers: User dropdown (all users), Action dropdown (distinct actions actually present in the table), free-text Search across action / description / IP / user agent, From and To dates, plus Reset and Apply Filters buttons. **No detail view, no row expansion, no export, no sorting** — the richer columns that exist on the model (`subject_type`, `subject_id`, `old_values`, `new_values`, `metadata`, `business_id`) are captured but never surfaced; the Description tooltip is the only way to read full text.
- **Legacy route:** `GET /office/activity-logs` (`admin.activity-logs.index`) — note it sits inside the `AdminRouteActivityLogger` group, so viewing logs itself is audited.
- **Legacy controller:** `Admin\ActivityLogController@index`
- **Legacy view:** `resources/views/admin/activity_logs/index.blade.php`
- **New API:** none — no activity-log route or controller under `Api/V1/**`; grep of `routes/api/` shows no occurrence of `activity` or `settings`.
- **New SPA:** none — no view, no nav entry, no endpoint method in `storify-admin/src/api/endpoints.ts`.
- **Missing:** everything (endpoint with filters/pagination + screen + filter modal). The scope's "detail, export" items have **no legacy counterpart either** — legacy shipped neither, so there is nothing to port there; a new implementation should arguably add them (see gaps).
- **Side effects:** read-only.
- **Effort:** M — **Priority:** P1

### 3.2 Admin route-access audit trail — `missing`
- **What the platform logged (legacy):** An `AdminRouteActivityLogger` middleware wraps every route in the `/office/*` module group (`AdminRouteActivityLogger` applied at `routes/v1/admin_dashboard.php` line 50) and writes an `ActivityLog` row (action `admin_route_accessed`) per request with description "«name» «METHOD» «route-name» («url»)" and metadata: role, route, url, path, method, response status, IP, user agent, referer; user resolved from the authenticated admin. Failures are swallowed so logging can never break a request. **Gap in legacy itself:** `/office/dashboard` and `/office/settings` are registered *outside* that group, so dashboard views and (critically) platform-settings edits were **not** written to the ActivityLog table — settings changes only appear in Laravel logs.
- **Legacy route/controller/views:** `routes/v1/admin_dashboard.php`; `App\Http\Middleware\AdminRouteActivityLogger`; `App\Services\ActivityLogger`; `app/Models/ActivityLog.php`.
- **New API:** no equivalent middleware — grep of `app/Http/Controllers/Api/**` for `ActivityLogger|ActivityLog` returns nothing; no middleware alias referencing it in `bootstrap/app.php`; no activity-log table writes are visible from admin API controllers.
- **New SPA:** n/a.
- **Missing:** the auditing mechanism itself. If the new API does not write ActivityLog rows, the Activity Log screen (3.1) has no data to show even once built — these two features must ship together. Recommended: API middleware on all admin routes **including dashboard and settings**, and make settings changes explicitly audited (the legacy settings code deliberately kept values out of logs; preserve that).
- **Effort:** M — **Priority:** P1

---

## 4. Settings-screen extras

### 4.1 Delivery Intervals management — `missing`
- **What the admin could do (legacy):** A two-column screen under the Settings nav: left "Add New Interval" form (Name, Days Count ≥1, Sort Order ≥0, "Create Interval"); right "Existing Intervals" table (Name, Days, Order, Status pill Active/Inactive) with per-row **Edit** (modal: name, days, sort — slug re-generated, duplicate-slug rejected), **Activate/Deactivate** toggle, and **Delete** (confirm modal; blocked with an error when the interval is used by family-pack orders). Slug uniqueness enforced on create and update; success/error flashes.
- **Legacy route:** `GET /office/delivery-intervals` (`admin.delivery-intervals.index`), `POST`, `PUT`, `POST /{id}/toggle`, `DELETE` — under `permission:admin.delivery`
- **Legacy controller:** `Admin\DeliveryIntervalController@index/store/update/toggle/destroy`
- **Legacy view:** `resources/views/admin/settings/delivery_intervals.blade.php` (the only file in `admin/settings/**`)
- **New API:** none — no delivery-interval route/controller in the new API.
- **New SPA:** none — no reference to intervals in `storify-admin` or `storify-management`.
- **Missing:** the entire CRUD screen. **Boundary note:** the route belongs to the delivery permission group (delivery domain) even though its view lives under `settings/**`; if a delivery-domain agent also audits intervals, de-duplicate against that report.
- **Side effects:** delete is guarded by usage in family-pack orders.
- **Effort:** M — **Priority:** P1

---

## Gaps worth calling out

1. **Platform settings are entirely absent from the new stack** — no API route, controller, or SPA view. Branding, support contacts, team addresses, homepage store, default currency, store-creation limit, trial rules, greeting modal and SEO/OG are all DB-tunable only. This is the single biggest P0 hole in this domain and blocks the platform operator from running the product.
2. **The activity trail has no reader — or writer.** No new endpoint exists for the existing `ActivityLog` table, and no new admin API middleware appears to write to it. Build 3.1 and 3.2 together; also fix the legacy blind spot where dashboard visits and settings changes were never written to the table.
3. **The dashboard lost its store filter**, which used to scope almost every widget on the page, and the arbitrary from/to date range (which only scoped the payment donut). New `days=7/30/90` and auto-refresh are nice, but they are not replacements.
4. **Stock-value and stock-health read-outs vanished from the dashboard**: stock value, units in stock, out-of-stock count, warehouse count and open POS sessions have no API field and no UI. Low stock silently re-thresholded from ≤10 to 1–5.
5. **Store Performance table was re-scoped** — from "every store, today/MTD, POS live status, last sale, drill-through" to "top 8 active stores by all-time revenue", losing all recency signals. An operator can no longer see which store is offline or quiet today.
6. **Pending transfers / recent transactions / recent orders panels** have no new-stack equivalent despite the data being one query away; the transactions list page exists in the SPA, but the dashboard's at-a-glance feed was replaced by "Recent Businesses".
7. **New API returns `revenue_series` / `orders_series` (6-month monthly series) that the SPA never renders** and that had no legacy counterpart — either wire them into a chart or drop them; they are dead payload today.
8. **KYC tile lost its deep link** ("Review →" into the submissions queue filtered to `submitted`), and the SPA's KYC tile is inert.
9. **Activity log's rich audit data is invisible:** `subject_type/id`, `old_values`, `new_values`, `metadata`, `business_id` are persisted but no legacy (or new) screen exposes them; a port is the moment to add a detail drawer/expandable row, and arguably CSV export. Legacy never had detail or export, so this is an enhancement, not a regression.
10. **Legacy dashboard "Executive" route** is a 301-style redirect to the dashboard (`admin.executive`); nothing to rebuild — include only as a bookmarked-URL redirect if parity matters.
11. **Boundaries:** admin/accounting/**, admin/admins/**, admin/early-access/** and the sidebar component (`admin/components/**`) were not audited here; delivery intervals (4.1) overlaps the delivery domain and settings-adjacent config screens (payment methods, VAT, bank accounts, business/ownership types, styling, company services) belong to their own audits.

## New-stack extras not present in legacy (for context, not gaps)

- Dashboard: 7/90-day range pills, 30-second auto-refresh toggle, manual refresh with spinner, "updated at" timestamp, clickable KPI/stat tiles, loading skeletons, empty states.
- Admin API: `recent_businesses` panel; `users`/`staff` stats; `GET /admin/search` powering a command palette (⌘K) in `storify-admin` — the legacy admin had no global search.
- New-stack permission gate on the dashboard itself (`permission:admin.dashboard`), where legacy allowed any platform admin.
