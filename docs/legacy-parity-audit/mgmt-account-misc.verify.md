# Verification pass — mgmt-account-misc (audience: business)

**Verifier method:** independently re-read the full legacy surface in scope — `routes/v1/management.php` (auth/logout, change-password, setup, dashboard, switch-store, search, profile, kyc, support-messages, stores.show/web-metrics), all seven in-scope controllers in full (`Management\{Dashboard,Setup,Profile,PasswordChange,SupportMessage,Search,StoreDashboard}Controller`, plus `KycController` and its `SubmitKycRequest`), `App\Http\Middleware\{RedirectIfOnboardingIncomplete,CheckSubscription}`, the sidebar/header View Composer in `AppServiceProvider`, `App\Services\StoreAnalyticsService`, and a file-by-file pass over every in-scope Blade view: `dashboard.blade.php`, `layout.blade.php`, `kyc.blade.php`, `auth/change-password.blade.php`, `profile/index.blade.php`, `support-messages/index.blade.php`, `components/{header,sidebar,footer}.blade.php` (the `kyc/` and `{support-messages}` dirs on disk are empty). New stack: `routes/api/v1/management.php` + `auth.php`, `Api\V1\Management\{Dashboard,Search}Controller`, `Api\V1\Auth\ManagementAuthController` + `BuildsAuthResponses`, and the management SPA (`router/index.ts`, `endpoints.ts`, `stores/auth.ts`, `layouts/AppLayout.vue`, `views/{DashboardView,ProfileView,StoresView}.vue`, `components/SearchModal.vue`).

**Coverage verdict:** the audit is substantively complete — every in-scope view file, controller method and route maps to a feature entry, and all 25 status pairs were checked by reading the new controller/endpoint and the SPA view. **No `missing` claim hides an existing endpoint, and no `exists` claim is a stub** (the closest call, `§3.1/§3.3` profile + password, are genuinely complete; `§8.1` correctly flagged as partial on the API). One real UI feature was omitted (sidebar counters/KYC pill), and there are six concrete text/status corrections. Nothing else was invented.

---

## Missed feature

### A. Sidebar badge counters and KYC status pill — `missing` (API) / `partial` (SPA)

- **What the user could do (legacy):** On every management page the nav showed live counters, scope-aware (restricted staff see assigned-store counts; owners see business counts) and cached 15 min per user in the View Composer: **Stores** (count), **Warehouses** (count), **Staff**, **Customers**, **Orders** (amber pending count), **Dispatches** (blue in-progress count), **Transactions** (amber pending count), **POS** (total open sessions in the group header plus "N active" per store) — and business owners saw a **KYC status pill** on the KYC Verification link: Pending / Verified / Rejected / Required.
- **Legacy route:** rendered on every `management.*` page — no dedicated route; data comes from the `management.components.sidebar` / `header` View Composer.
- **Legacy controller:** `App\Providers\AppServiceProvider` (composer at ~line 321, `sidebar.counts.{user_id}` cache), queries `Order`/`Transaction`/`Customer`/`OrderDelivery`/`User`/`Store`/`PosSession`; KYC pill reads `User::kycApplication`.
- **Legacy views:** `resources/views/management/components/sidebar.blade.php`
- **Key UI:** numeric badges on nav links + KYC status pill (Pending/Verified/Rejected/Required)
- **Side effects:** none (read-only, 15-minute cache).
- **API status:** `missing` — the new `GET /management/dashboard` returns only `orders.pending`, `stores.total`, `customers.total` and `products.low_stock`; there is no pending-transaction, in-progress-dispatch, active-staff, warehouse, POS-open-session or KYC-status payload for the shell, and `me` exposes only the bare stores list.
- **SPA status:** `partial` — `AppLayout.vue` renders exactly one badge (`badge: auth.stores.length` on the Stores link); no other counters and no KYC pill.
- **Effort:** S — **Priority:** P2

---

## Corrections

### 1. §1.1 — the legacy Orders card did **not** show a month-over-month percentage

- **Feature:** Business dashboard KPI metric cards
- **Field:** legacy capability — was: *"Revenue and order cards also carried month-over-month change percentages."*
- **Should be:** only the **Revenue** card rendered `revenue_change_percent`; `orders_change_percent` is computed by `Management\DashboardController@index` but never rendered anywhere in `dashboard.blade.php` (grepped). The Orders card subtitle was `"N pending · N this month"` — no % change exists in legacy to port.

### 2. §1.1 — "card deep links" is not a legacy behaviour

- **Feature:** Business dashboard KPI metric cards
- **Field:** Missing list — was: *"… permission-scoped visibility per card; card deep links; …"*
- **Should be:** legacy metric cards were plain non-interactive `<div>`s — `components/management/metric-card.blade.php` accepts only `value/label/subtitle/icon` and the dashboard passes no href attributes. Drop "card deep links" as a legacy gap; the only deep links were the panel-header "View all"/"Manage" links, already tracked per panel in §1.3/§1.5/§1.7/§1.8.

### 3. §1.2 — the legacy revenue chart did **not** zero-fill empty months

- **Feature:** Revenue overview chart
- **Field:** Missing list — was: *"zero-filling of months with no revenue (legacy chart shows empty months, new one skips them)"*
- **Should be:** both stacks are built from grouped transaction rows, so **both** omit months with no revenue. Legacy's only zero-fill is the hardcoded `['Jan'…'Jun'] / [0,0,0,0,0,0]` fallback rendered when the series is completely empty (`dashboard.blade.php` @push scripts). No legacy zero-filling behaviour exists to port; the real gaps vs legacy are just the this-month headline and the ±% delta.

### 4. §5.1 — Customers search also lost two legacy match fields

- **Feature:** Global search modal
- **Field:** Missing list — was: *"Missing: Stores, Warehouses, Transactions and Staff result groups with their permission gates; 2-character minimum; per-entity deep links for products/customers; 5-minute cache; group icons/subtitles parity."*
- **Should be:** add **`account_id`** and the **`CONCAT(first_name, ' ', last_name)` full-name match** to the list — both were searched by legacy `SearchController` (lines 109–115) and neither is in the new `Api\V1\Management\SearchController@__invoke` (first/last/email/phone only).

### 5. Executive summary — the coverage table and headline counts are wrong

- **Feature:** Executive summary / coverage table
- **Field:** counts — was: *Dashboard 11 | 0 | 5 | 6; Profile & account 4 | 2 | 1 | 1; App shell/misc 2 | 2 | 0 | 0; "4 of 25 audited features are fully usable; 8 are partial; 13 are entirely missing."*
- **Should be:** Dashboard is **6 partial / 5 missing** (1.1, 1.2, 1.3, 1.6, 1.9, 1.10 partial; 1.4, 1.5, 1.7, 1.8, 1.11 missing); Profile & account has **5 entries — 3 exists / 1 partial / 1 missing** (3.5 counted); App shell is **1 exists / 1 partial** (8.1 is not fully usable); totals: **4 of 26 fully usable, 10 partial, 12 missing**.

### 6. §2.1 — the setup step did not persist `phone`, and `slug` is not auto-generated

- **Feature:** Business setup form
- **Field:** legacy side effects — was: *"Submitting created the `Business` row (status `active`, auto slug/business_code/prefix) …"*
- **Should be:** `prefix` and `business_code` are auto-generated (`Business::booted()` `creating` hook, plus a backfill migration) — but **`slug` is not generated anywhere**: the column is `nullable()->unique()` and `SetupController@store` never sets it. Also the validated **`phone` is never persisted** (`Business::create` receives only `name`/`description`/`business_location`/`status`; `phone` is not fillable). The port should decide deliberately whether to save the phone the form collects and whether to key businesses by slug.

---

## Smaller notes (no audit text change demanded)

- §1.1: the SPA Orders tile also dropped the legacy `"N this month"` sub-metric (legacy subtitle `"N pending · N this month"`; new hint is only `"N pending"`). Not listed in the audit's missing set.
- §5.1: legacy had **no Ctrl+K keybinding** — only the header button (`title="Search (Ctrl+K)"` was a tooltip and the `⌘K` chip in the modal was decorative; no keydown handler exists in any management view). The SPA implements Ctrl/⌘K, so this is net-new, not parity.
- §2.2: "VerifyOtpView.vue/LoginView.vue push `/` unconditionally" is exact only for VerifyOtpView; LoginView pushes `route.query.redirect ?? '/'`, i.e. it honours a `?redirect=` deep link but still never `next`. Immaterial to the gap.
- §1.3: the port renders the customer name (`order.customer ?? 'Walk-in'`) which the legacy list did not show (legacy: store · item count · time-ago); this is an addition, not a regression.
- §4.1: the legacy list is a **single flat list** (no thread/detail view) — the audit's "support messaging threads" framing should not imply the legacy had thread drill-down; the reply endpoint had no management UI at all (audit states this correctly in §4.2).
- `§8.2` reads as `exists/exists`; once feature A above is recognised the shell pair is at best `partial/partial` (missing all counters + KYC pill; initials-only avatar already noted in §3.2).
