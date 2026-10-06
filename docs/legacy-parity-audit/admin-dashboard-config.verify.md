# admin-dashboard-config — Adversarial Verification

**Verified:** 2026-10-06 — independent re-scan; the audit's quotes were re-checked against source, not trusted.

**Re-read myself:** `routes/v1/admin_dashboard.php`; `Admin/{AdminDashboardController,AdminSettingsController,ActivityLogController,DeliveryIntervalController}.php`; `SettingsUpdateRequest`; `AdminRouteActivityLogger`; models `ActivityLog`, `Setting`, `DeliveryInterval`, `Pack`; `AppServiceProvider` composers; `SpatiePermissionSeeder`; every in-scope Blade file (`admin/index.blade.php`, `admin/layout.blade.php`, `admin/advanced/settings.blade.php`, `admin/settings/delivery_intervals.blade.php`, `admin/activity_logs/index.blade.php`, plus `admin/components/{header,sidebar}.blade.php`); new stack: `routes/api/v1/admin.php` and a case-insensitive grep of all of `routes/api/`, every `Api/V1/Admin/*` controller, `Api/V1/Home/HomeController`, `app/Http/Middleware/`, and the admin SPA (`src/router/index.ts`, all `src/views/`, `src/api/endpoints.ts`, `src/layouts/AdminLayout.vue`).

**Verdict:** no unaccounted screens or routes. All five in-scope view files map to feature entries, and all ten in-domain route registrations (dashboard, executive, settings GET/POST, activity-logs, delivery-intervals index/store/update/toggle/destroy) are covered. No false `missing` status was found: a case-insensitive grep of `routes/api/` shows no settings/activity/interval route; no `Api/V1` controller manages the settings row (only `Home/HomeController` reads it); the only audit middleware in the repo is the legacy `AdminRouteActivityLogger`; and `storify-admin` has no settings/activity/interval view, route or endpoint call. The findings below are claim-level errors that would misdirect the rebuild.

## Missed features

**None found.** Near-misses checked and dismissed:

- `admin/layout.blade.php` is shared chrome (sidebar collapse, mobile menu, session toasts), not a domain capability.
- `AdminDashboardController` computes `pending_transactions`, `completed_orders`, `new_customers_this_month`, `active_customers`, `stockInToday`, `stockOutToday` (`:151,154,164,165,87-91`) but no legacy view renders them (grep of `admin/index.blade.php` — dead data, not something an admin could see).
- The other sidebar "Settings" entries (Plans & Pricing, Payment Methods, Bank Accounts, VAT, Delivery Routes, Company Services, Business Types, Ownership Types, Page Styling) belong to the finance/content/subscriptions audits, as the audit's boundary note says.
- `GET /office/executive` is a redirect and is already recorded in the audit's gap #10.

## Corrections

### C1 — §1.1/§1.10: the store filter never scoped the whole KPI row (or the stats grid)

- **Feature:** 1.1 Dashboard KPI tile row; 1.10 Dashboard filter bar
- **Field:** what_user_can_do
- **Was:** 1.1 "Six top tiles, each recomputed against the active **store filter**"; 1.10 "The **store filter** scoped almost every widget: KPI tiles, …"
- **Should be:** Only four of the six KPI tiles honour `store_id` — Today's Revenue, Today's Orders, Revenue MTD and Stock Value, which come from the filtered `$txnQuery` / `$orderQuery` / `$stockLocationQuery` (`AdminDashboardController.php:45-72`). The **Active Stores** tile and the **Customers** tile read `$stats['active_stores']` / `$stats['total_customers']` (`index.blade.php:51,56`), and the entire `$stats` array (`AdminDashboardController.php:148-170`) plus `$transferStats` (`:122-126`) are computed without `store_id` — so the whole 8-card stats grid is global under every filter. A rebuilt store filter must scope exactly what legacy scoped (the four tiles, stock value/counts, MTD figures, both daily charts, the payment donut, the pending-transfers list and the store table) and must **not** filter the stores/customers counts.

### C2 — §2.7: `settings.api_keys` is write-only — and every settings save wipes it

- **Feature:** 2.7 Platform API keys
- **Field:** what_user_can_do
- **Was:** "Judge: obsolete as a UI feature, but the storage contract (`settings.api_keys`) remains and is read elsewhere."
- **Should be:** Nothing reads `settings.api_keys`. A grep of `app/`, `resources/`, `routes/`, `config/` returns only the `Setting` model cast, the request rule and `AdminSettingsController` itself (the other `api_keys` hits are store payment-method pivots — a different column). The column also cannot hold data: the Blade renders no `api_key_names[]`/`api_key_values[]` inputs, so on every ordinary save `$apiKeys` falls through to `$request->api_keys ?? null` (`AdminSettingsController.php:56-72`) and `'api_keys' => null` is written into `$data` (`:82`) — the first UI save nulls whatever a crafted POST had stored. "Do not port the UI" stands with more force: there is no storage contract to preserve.

### C3 — §4.1: interval delete is a guaranteed 500, not a working usage guard

- **Feature:** 4.1 Delivery Intervals management
- **Field:** what_user_can_do (Delete) / side_effects
- **Was:** "Delete (confirm modal; blocked with an error when the interval is used by family-pack orders)"; "delete is guarded by usage in family-pack orders."
- **Should be:** `DeliveryInterval` (`app/Models/DeliveryInterval.php`) defines no `familyPackOrders()` relation — only `scopeActive()` — and no model anywhere defines a family-pack-order relation, so `$interval->familyPackOrders()->exists()` (`DeliveryIntervalController.php:104`) throws `BadMethodCallException` (HTTP 500) on **every** delete attempt, used or not. Nothing consumes `delivery_intervals` at all: a full grep shows only its own controller and `DeliveryIntervalSeeder`; `family_packs*` appear solely as table names in the `add_business_id_to_all_tables` migration, with no create migration or consumer. (Sibling audit `admin-content-support.md` §E reached the same conclusion.) A rebuild should ship without a fictional guard, or gate on a real consumer once one exists.

### C4 — §4.1 priority: P1 overstates an orphaned screen

- **Feature:** 4.1 Delivery Intervals management
- **Field:** priority
- **Was:** P1
- **Should be:** P2 — and only after an explicit revive-or-retire decision: nothing in the legacy or new stack reads intervals (grep), the delete action 500s, and no order/checkout path uses the table. This matches the sibling `admin-content-support.md` §E judgement; count the feature once in the build plan.

### C5 — §2: the settings POST is only partially validated

- **Feature:** 2.5 / 2.6 (settings form validation)
- **Field:** what_user_can_do / validations
- **Was:** implied full-form coverage ("one POST endpoint saves all fields (`SettingsUpdateRequest` validation)").
- **Should be:** `SettingsUpdateRequest` validates only the four file uploads, `company_name`, `support_email`, `support_phone`, the two address fields, `api_keys`, `main_store_id`, `default_currency_id`, `store_creation_limit` and the trial fields. `company_description`, `og_title`, `og_description`, `og_url`, `og_type`, `greeting_modal_enabled` and `greeting_modal_frequency` are stored raw — arbitrary `og_type`/frequency strings persist (the controller defaults them only when absent). The rebuild must add enum/string constraints rather than copy the loose validation.

## Minor notes (not worth their own rows)

- **Tile-order nit (§1.1):** the new SPA's Businesses tile is the 5th of six, not the sixth (Customers is last); and not all tiles are clickable links — Today's Orders, Customers, Products, Low Stock and KYC are plain `div`s in `DashboardView.vue`.
- **§1.3/§1.5 statuses:** the legacy charts "respected the store filter", so under the audit's own vocabulary ("exists = fully usable") their `exists` is arguably `partial`. The audit deliberately tracks the filter as 1.10, so I did not overturn it — just note the summary's `exists` count assumes that decomposition.
- **Settings save UX:** no `@error` rendering exists anywhere on the settings page or in `admin/layout.blade.php` — a failed POST keeps `old()` values with no message. Rebuild with per-field errors.
- **The new stack already consumes the settings row** (undocumented in the audit): `Api\V1\Home\HomeController::company()` (`home_api_company`, 600 s cache) serves company name/description, logo, favicon, support email/phone, address and all `og_*` fields to the home app, and `HomeController::support()` sends the contact form to `settings.support_email` (`:54`, falling back to `mail.from.address`). Any rebuilt save endpoint must bust `home_api_company` (and legacy `company_settings`) — not just `company_settings`.
- **New-stack permissions already seeded:** `SpatiePermissionSeeder` defines `admin.settings`, `admin.activity-logs` and `admin.delivery` on the Super Admin / Platform Admin roles — rebuilt routes can reuse them, mirroring the existing `permission:admin.dashboard` gate on `GET /api/v1/admin/dashboard`.
- **Main-store guards cross-reference:** `Setting::value('main_store_id')` also blocks suspending/deleting the homepage store or its owning business (`Admin/StoreController`, `Admin/BusinessController`, `Admin/UserController`). That behaviour is inventoried by the sibling audits (`admin-stores-catalog.md`, `admin-businesses.md`, `admin-users-admins.md`) — don't re-count it under 2.3, but wire it when 2.3 ships.
- **§2.3's `$adminMainStore`:** injected into every `admin.*` view by the `AppServiceProvider` composer (300 s cache) but read by no view (grep) — harmless, worth knowing before "porting" the consumer.
