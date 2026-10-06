# Platform Admin Console (storify-admin) — Legacy-Parity Build Roadmap

**Audience:** platform office / superadmin (`storify-admin` SPA + `storify-api` `routes/api/v1/admin.php`)
**Built from:** the six verified audits in this folder — `admin-dashboard-config`, `admin-businesses`, `admin-orders-finance`, `admin-stores-catalog`, `admin-users-admins`, `admin-content-support` — including every correction in their `.verify.md` files.
**Date:** 2026-10-06
**Feature IDs:** `DC` = admin-dashboard-config, `AB` = admin-businesses, `OF` = admin-orders-finance, `SC` = admin-stores-catalog, `UA` = admin-users-admins, `CS` = admin-content-support. Numbers match the feature headings in those reports, with verification corrections applied.

**Scope carried:** every feature with `api_status`/`spa_status` of **missing** or **partial** in the audits — **112 features**, grouped into **18 independently buildable workstreams**. Nine audit features are consciously **deferred** with reasons (§Deferred). Nothing is silently dropped.

---

## 1. Where the console is today

The admin SPA ships **7 child routes** (Dashboard, Businesses, Business Detail, Stores, Users, Transactions, Coupons) backed by 23 API registrations and 7 `Api\V1\Admin` controllers. The audits put current parity at roughly: dashboard ~35%, stores ~10%, orders 0%, accounting 0%, warehouses 0%, KYC 0%, customers 0%, admins 0%, activity log 0%, support 0%, settings 0%, users ~40%, coupons ~95%.

The API plumbing is already in place — `auth:sanctum` + `token.audience:admin` + `team.context` + `permission:*`, `ApiController::ok/error/paginationMeta`, `spatie` roles seeded — so almost all of this is additive controllers + routes + SPA views, not architecture.

## 2. How to read this roadmap

- **Workstreams** are ordered **dependencies first, then P0 (daily-use/blocking), then P1, then P2**. Each is an independent ship: an API slice + the SPA views that consume it.
- **Effort** is rough workstream size: `S` ≈ days, `M` ≈ 1–2 weeks, `L` ≈ 2–4 weeks for one engineer.
- **"Improve on legacy"** bullets flag legacy behaviour that is wrong, dead or obsolete — build the better behaviour, do not clone the bug (all such items are also consolidated in §21).
- All endpoints are relative to the existing file `routes/api/v1/admin.php` (prefix `/api/v1/admin`, within the existing `auth:sanctum` + `token.audience:admin` + `team.context` group, name prefix `api.admin.`).
- Route keys follow the existing model bindings: Business→`business_code`, Store→`store_id`, User→`account_code`, Order→`order_number`, Product→`product_code`, Warehouse→`warehouse_code`, StockTransfer→`transfer_code`, Transaction→`reference`, EarlyPass→`code`, SubscriptionPlan→`plan_code`, Customer→`account_id`, Invoice→`invoice_number`.

## 3. Cross-cutting foundations (apply to every workstream)

### 3.1 Permissions — already seeded, just wire them

`SpatiePermissionSeeder` already defines all 20 admin permissions. No new permission strings are needed. The mapping to use:

| Permission | Gates (new routes) |
|---|---|
| `admin.dashboard` | dashboard (existing) |
| `admin.businesses` | businesses CRUD, KYC applications, early-access passes |
| `admin.stores` | stores CRUD + suspend/activate/delete |
| `admin.warehouses` | warehouses, stock transfers |
| `admin.products` | products, categories |
| `admin.customers` | platform customer console |
| `admin.orders` | orders, shop4me orders |
| `admin.transactions` | transactions + status override |
| `admin.subscriptions` | subscriptions, subscription plans |
| `admin.coupons` | coupons (existing) |
| `admin.support` | support inbox |
| `admin.content` | testimonials, business types, ownership types, company services |
| `admin.delivery` | delivery routes, delivery intervals |
| `admin.finance` | VAT, payment methods, bank accounts |
| `admin.accounting` | platform accounting |
| `admin.settings` | platform settings |
| `admin.activity-logs` | activity log viewer |
| `admin.admins` | admin accounts + invitations |
| `admin.users` | users console |
| `admin.users.impersonate` | impersonation start (route-level) |

### 3.2 Navigation plan (AdminLayout.vue `sections`)

Mirror legacy's four sections; add badge counts the legacy sidebar had (pending orders, KYC pending) fed by `GET /admin/dashboard` stats (already returns `orders_pending`, `kyc_pending`).

- **Navigation:** Dashboard · Businesses › (All Businesses, KYC Submissions `[kyc_pending badge]`, Early Access) · Users › (All Users, Admins) · Customers
- **Commerce:** Orders › (All Orders `[orders_pending badge]`, Shop4Me Orders) · Stores · Warehouses · Stock Transfers · Products · Categories · Transactions · Subscriptions & Plans
- **Content:** Support Messages `[pending badge]` · Testimonials
- **Settings:** Platform Settings · Coupons · VAT · Payment Methods · Bank Accounts · Delivery Routes · Business Types · Ownership Types · Activity Logs

### 3.3 API conventions (match the existing code)

- Paginated lists: `data[] + meta{current_page,last_page,per_page,total}` via `ApiController::paginationMeta`; query params `q`, `status`, `sort` (whitelist!), `direction`, `per_page`, plus domain filters; `from`/`to` date ranges where legacy had them.
- Mutations: validate in-controller (as `Admin\BusinessController` does) or a FormRequest; return `$this->ok([...], 'Message.')`; wrap money/stock mutations in `DB::transaction`; write an `ActivityLog` row for every mutation (§3.5).
- Never pass user-supplied `sort_by` straight to `orderBy` (legacy `OrderController@index` bug) — whitelist column + direction.
- File uploads: `multipart/form-data`, store on the `public` disk, delete the previous file on replace/delete, validate mime+size per the legacy limits listed per feature.

### 3.4 Shared SPA components

Existing: `AppModal`, `ConfirmDialog`, `DetailDrawer`, `EmptyState`, `SortHeader`, `TableFooter`, `TableSkeleton`, `StatusBadge`, `StatCard`, `ToastHost`, `CommandPalette` — reuse rather than reinvent. Add once and reuse everywhere:
1. `FilterPanel.vue` — the legacy "Filter modal" pattern (status/q/date-range + Apply/Reset) used by ~15 lists.
2. `FileUploadField.vue` — image/document upload with preview + size/mime error (settings, store logo, product images, bank logo, feature icon, testimonial photo).
3. `ActivityTimeline.vue` — activity feed card (order detail, user detail, customer detail).
4. `DetailPageHeader.vue` — back link + title + status badges + action buttons (legacy show pages).
5. `StatTileRow.vue` — wrapping `StatCard` for list stat strips (orders, customers, warehouses, passes).
6. Full-page routes for detail screens admins share/bookmark: `/orders/:orderNumber`, `/users/:accountCode`, `/customers/:accountId`, `/stores/:storeId`, `/warehouses/:warehouseCode`, `/transfers/:transferCode`, `/businesses/:businessCode` (exists), `/kyc-applications/:id`, `/early-access/:code`, `/products/:productCode`. Drawers remain fine for low-stakes detail (transactions, coupons).

### 3.5 Audit trail convention (WS1 lands the mechanism)

Every admin mutation writes an `ActivityLog` row (actor, business_id, action, subject_type/subject_id, description, old_values/new_values, ip, user_agent) through one `ActivityLogger` service. Settings-style secrets must be redacted from old/new values (legacy did this for `api_keys`). Route-access logging middleware wraps the whole admin group **including dashboard and settings** (fixing the legacy blind spot).

### 3.6 Emails / notifications that must fire (all mailables already exist in storify-api)

| Trigger | Mailable to | Workstream |
|---|---|---|
| Business created by admin | `AdminBusinessCreated` → all superadmins | WS4 |
| Business suspended / reactivated | `BusinessSuspended` / `BusinessReactivated` → owner | WS4 |
| KYC approved | `KycApproved` → owner (in-transaction, non-fatal) | WS3 |
| KYC rejected | **new** rejection email → owner (fix legacy bug: legacy flashed "notified" but sent nothing) | WS3 |
| KYC submitted | existing submission mails → admins + business (verify still wired) | WS3 |
| Store created / suspended / reactivated / moved inactive | `AdminStoreCreated` → superadmins; `StoreActivated`/`StoreSuspended`/`StoreReactivated` → owner | WS6 |
| Order status changed | `CustomerOrderStatusUpdatedMail` → customer | WS5 |
| User suspended / reactivated / password reset / invited | `BusinessSuspended`, `BusinessReactivated`, `UserPasswordResetMail` (carries temp password), `AdminInvitationMail` | WS8/WS10 |
| Customer suspended / activated | `CustomerAccountSuspendedMail`, `CustomerAccountActivatedMail` | WS9 |
| Support replied | `SupportMessageReplyMail` → customer; **add** admin/platform notification on new submission (`AdminNewSupportMessageMail` currently only goes to the store) | WS16 |
| Settings updated | `SettingsUpdated` → first superadmin (legacy) | WS2 |
| New support message | see above; storefront submissions currently email only the store | WS16 |

### 3.7 Guards to reproduce exactly (they protect live money/operations)

- **Main-store protection:** `Setting::value('main_store_id')` blocks suspend/delete of that store, its owning business and its owner user (legacy), and blocks setting the homepage store inactive/suspended. Build one `MainStoreGuard` helper used by WS4/WS6/WS8; expose the selector in WS2.
- **Open-orders/transactions guard:** business/user/store delete refused while any store has an order not `completed` or a transaction not `confirmed`.
- **Status cascade:** legacy wrote `User.status` on business actions while the list read `Business.status` (divergence). Decide once — recommendation: `Business.status` canonical for the business, and cascade to owner `User.status` deliberately when the business is suspended/deleted, with tests.

---

## 4. Workstream 1 — Activity log & audit trail (foundation) — M

**Delivers (3):** `DC-3.2`/`UA-25` admin route-access logger; `UA-11` activity-log writes for admin mutations; `DC-3.1`/`UA-24` activity log viewer (list, filters, pagination, detail drawer).

**Depends on:** nothing. Every later workstream emits its audit events through this.

**API**
- `GET /api/v1/admin/activity-logs` — filters `user_id`, `action`, `q` (action/description/ip/user agent), `from`, `to`; 50/page with query preserved; payload adds `subject_type`, `subject_id`, `old_values`, `new_values`, `metadata`, `business_id` for the detail drawer.
- No other routes. Middleware `AdminApiActivityLogger` applied to the whole admin group in `routes/api/v1/admin.php` (and registered in `bootstrap/app.php`), writing `admin_route_accessed` rows with role/route/url/method/status/ip/user-agent, failures swallowed.

**Controllers / services**
- New `Api\V1\Admin\ActivityLogController@index`.
- New `App\Services\ActivityLogger` (port of the legacy service) + `App\Middleware\AdminApiActivityLogger`.
- Per-mutation `ActivityLogger` calls added in each workstream's controllers as they land.

**SPA**
- Route `/activity-logs` → `ActivityLogsView.vue`; nav under Settings; filter panel (user dropdown, action dropdown, free text, from/to, Reset/Apply); table When / User / Action / Description / IP / User Agent; row click → drawer with subject, old→new values, metadata; CSV export button. Viewing logs is itself audited.

**Acceptance criteria (parity+)**
- Every admin mutation from WS2 onward produces a row with old/new values; settings saves log changed keys only with sensitive values redacted.
- Dashboard visits and settings changes **are** logged (legacy excluded them — deliberate fix).
- Filters combine and paginate with query-string preservation; action dropdown lists only actions actually present.
- `permission:admin.activity-logs` gates the route; non-superadmin with the permission can read (legacy hard-gated to superadmin — keep permission-only, it is the modern equivalent).

**Improve on legacy**
- Add the detail drawer + CSV export (legacy had neither; `subject_type/id/old/new/metadata` were captured but never shown).
- Log the dashboard and settings screens (legacy blind spot).

---

## 5. Workstream 2 — Platform settings & branding — L

**Delivers (6):** `DC-2.1` general info/branding/contact; `DC-2.2` store-creation limit + free trial; `DC-2.3` homepage store; `DC-2.4` default currency; `DC-2.5` greeting modal; `DC-2.6` SEO/Open Graph.

**Depends on:** WS1 (audit). Provides the main-store value + branding + homepage-store selector consumed by WS4/WS6/WS8.

**API**
- `GET /api/v1/admin/settings` — full settings payload incl. file URLs, plus option lists: `stores` (non-deleted, for homepage picker), `currencies`.
- `PUT /api/v1/admin/settings` — multipart; accepts all fields; validates logo (PNG/JPG/WEBP ≤2 MB), favicon (ICO/PNG ≤1 MB), certificate (PDF/JPG/PNG/WEBP ≤5 MB), OG image (PNG/JPG/WEBP ≤2 MB), `main_store_id` (`exists:stores,id`), `default_currency_id`, `store_creation_limit` (min 1), trial fields (`trial_days` 1–90), **enum-validated** `greeting_modal_frequency` and `og_type`.
- `POST /api/v1/admin/settings/logo|favicon|certificate|og-image` optional split endpoints if single multipart gets unwieldy.

**Controllers**
- New `Api\V1\Admin\SettingsController@show/update`; file replace deletes the old file; `Currency::where('is_default')->update(['is_default'=>false])` swap inside a transaction; bust `company_settings`, `admin_main_store`, `home_api_company` caches; queue `SettingsUpdated` to the first superadmin; audit with changed-key diff (values redacted).

**SPA**
- Route `/settings` → `SettingsView.vue`, tabs: General (company logo/favicon/certificate, name, description, support email/phone, address, branch), Limits & Trial, Homepage (store picker), Currency (default picker), Greeting Modal (toggle + frequency), SEO (OG title/description/image/url/type). Per-field errors.

**Acceptance criteria**
- Every legacy field editable and persisted; uploads validate and replace with old-file cleanup; homepage store selector lists all non-deleted stores; default currency is exclusive; saving busts the home API cache so `GET /api/v1/home` reflects new branding.
- Superadmin email fires on change; main-store helper used by later guards resolves to the saved value.

**Improve on legacy**
- Render per-field validation errors (legacy rendered none) and validate `og_type`/greeting frequency against enums (legacy stored raw strings).
- Do **not** port the unreachable `api_keys` vault (deferred, §20). Keep certificate/favicon — they are real.
- Make the two tabs a proper tabbed route with dirty-state guard rather than localStorage+hash persistence.

---

## 6. Workstream 3 — KYC review — M

**Delivers (5):** `AB-9` KYC queue; `AB-10` review screen; `AB-11` approve; `AB-12` reject with reason; `AB-13` reject→resubmit loop (admin dependency; business half lives in the management roadmap).

**Depends on:** WS1. Shares `KycApprovalService` with WS4 (activate auto-approval) and WS8 (user activate).

**API**
- `GET /api/v1/admin/kyc-applications` — status filter (default `submitted`), status-count pills, pagination 20; columns business/legal name/owner/submitted/status.
- `GET /api/v1/admin/kyc-applications/{application}` — full application: legal name, phone, DOB, address, device, IP, identification document link, reviewer fields, business snapshot, plus **selfie, `kyc_document_type`, `kyc_document_id`, `payload`** (stored by legacy but never displayed).
- `POST /api/v1/admin/kyc-applications/{application}/approve` — optional `review_notes` (≤2000); transaction: status approved, `approved_at=now()`, `rejected_at=null`, `reviewed_by=admin`, owner `User.status=active`; queue `KycApproved` (non-fatal).
- `POST /api/v1/admin/kyc-applications/{application}/reject` — required `review_notes` (≤2000); transaction: status rejected, `rejected_at`, `approved_at=null`, `reviewed_by`, owner status `pending`; **send a rejection email** (new).

**Controllers**
- New `Api\V1\Admin\KycApplicationController@index/show/approve/reject`.
- New `KycApprovalService` (approve/reject/auto-approve) used by this controller, `BusinessController@activate`, `UserController@activate` — one writer for KYC state transitions.

**SPA**
- Routes `/kyc-applications` (queue) and `/kyc-applications/:id` (review). Nav: Businesses › KYC Submissions with `kyc_pending` badge. Dashboard KYC tile deep-links to the queue filtered `submitted` (lands in WS7).
- Review screen: application card + business snapshot + document/selfie preview + approve/reject forms (reason required, field-level errors); "Take Action" only when `submitted`.

**Acceptance criteria**
- A submitted application can be approved or rejected from the new UI; approve activates the owner; reject moves owner back to pending and (unlike legacy) actually emails the rejection; status guards prevent re-approving a rejected application by direct request.
- Business activate auto-approves an open application with `approved_at` + reviewer note persisted (fixing the legacy write that persisted only status+reviewed_by due to a missing column / un-fillable field).
- Dashboard `kyc_pending` count matches the queue.

**Improve on legacy**
- Fix legacy auto-approval that persisted nothing usable (no `reviewed_at` column, `reviewer_notes` not fillable) — set `approved_at` and the reviewer note properly.
- Surface `selfie_image_path`, `kyc_document_id`, `kyc_document_type_id` and decide on `payload` (show or drop) — legacy stored them invisibly.
- Add a real rejection email; stop over-promising in the flash.
- Leave a true `info_requested` status out for now (deferred, §20) — reject+resubmit covers legacy parity exactly.

---

## 7. Workstream 4 — Business lifecycle & directory — L

**Delivers (10):** `AB-1` directory filters; `AB-2` create business; `AB-3` detail console; `AB-4` edit owner/business; `AB-5` suspend parity; `AB-6` activate parity; `AB-7` delete business; `AB-8` owner email-verified indicator; `AB-15` business types CRUD; `AB-16` ownership types CRUD.

**Depends on:** WS1 (audit), WS2 (main-store guard value), WS3 (`KycApprovalService` for activate auto-approval).

**API**
- Extend `GET /api/v1/admin/businesses` — add `from`/`to` created range; default-hide `deleted`; warehouses count; `q` over name/code/owner name/email/phone.
- `POST /api/v1/admin/businesses` — admin-provisioned owner (name, email, phone, status, slug auto); honour `ALLOW_MS_SETUP` semantics deliberately (see improve-on-legacy).
- `PUT /api/v1/admin/businesses/{business}` — owner name/email/phone/status; slug re-normalise; return-to-detail honoured by SPA.
- `DELETE /api/v1/admin/businesses/{business}` — guarded: main store, open orders, unconfirmed transactions; soft-delete semantics (owner `status=deleted`, row survives).
- Extend `POST .../suspend` and `.../activate` — add main-store guard, owner status cascade, suspension/reactivation emails; activate also runs KYC auto-approval.
- `POST /api/v1/admin/businesses/{business}/verify-owner` → delegates to WS8's user verify (or reuse `POST /admin/users/{account_code}/verify` from the detail screen).
- Business types: `GET|POST /api/v1/admin/business-types`, `PUT|DELETE /api/v1/admin/business-types/{businessType}` (name ≤255 unique, alphabetical, paginated 20).
- Ownership types: identical set at `/api/v1/admin/ownership-types`.

**Controllers**
- Extend `Api\V1\Admin\BusinessController` (store/update/destroy/enriched show).
- New `BusinessTypeController`, `OwnershipTypeController`.

**SPA**
- `BusinessesView.vue`: add date filter, deleted-inclusive status option, warehouses column, "view stores" link (`/stores?q=`), owner phone in detail.
- `BusinessDetailView.vue`: add Business & Owner card fields (owner phone/status/verified badge, `prefix`, subscription end date), Team table (name/email/roles/status), Stores table (ownership type, business type, status), Warehouses table (code, stock items, status), KYC panel with "View KYC Details" link, Edit Owner / Delete / Activate buttons, no-Business-record fallback.
- Routes `/settings/business-types`, `/settings/ownership-types` (Settings section).

**Acceptance criteria**
- Full lifecycle: create (with the main-store/multi-business guard semantics made explicit), edit, suspend (required reason + email + owner cascade), activate (email + KYC auto-approval), delete (all three guards, soft delete).
- Directory hides deleted by default, supports the date range, warehouses count, and links into stores; detail answers "who works here, what do they run, are they verified" without leaving the page.
- Both type CRUDs gate store/business setup dropdowns (they feed `businesses.*_type_id` / `stores.*_type_id`).

**Improve on legacy**
- Fix the legacy status divergence (`Business.status` vs owner `User.status`): one canonical field + deliberate cascade, covered by tests.
- Do not rebuild `BusinessController@create/edit` dead methods or a status option set that lets edits bypass delete guards.
- Decide deliberately whether admin-provisioned businesses use an invite/password-set flow instead of the legacy undefined password path.
- Add audit-log entries for every action (legacy wrote them, new API currently writes only `Log::info`).

---

## 8. Workstream 5 — Platform orders oversight — L

**Delivers (8):** `OF-1.1` orders list; `OF-1.2` order detail; `OF-1.3` order edit; `OF-1.4` status update + customer email; `OF-1.5` payment-status transaction sync; `OF-1.6` order delete; `OF-1.7` Shop4Me list; `OF-2.3` transaction status override.

**Depends on:** WS1. Dashboard "recent orders" panel (WS7) consumes the list endpoint.

**API**
- `GET /api/v1/admin/orders` — filters `q` (order #, customer name/email), `store`/`store_id`, `status`, `payment_status` (derived from transactions: paid/refunded/failed/unpaid, incl. pending/partial), `from`/`to`, whitelisted `sort_by`/`sort_order`, 20/page; stat cards in meta (total/pending/processing/revenue).
- `GET /api/v1/admin/orders/{order}` — items + totals, customer, delivery info, transactions, notes, activity timeline, quick info (store, owner, dates). Bound by `order_number`.
- `PUT /api/v1/admin/orders/{order}` — `shipping_fee`, `tax`, `status`, `notes` only.
- `PATCH /api/v1/admin/orders/{order}/status` — status + optional note (note appended to `notes` with timestamp header); ActivityLog old→new; `CustomerOrderStatusUpdatedMail` to customer.
- `PATCH /api/v1/admin/orders/{order}/payment-status` — unpaid deletes the order's transaction; paid/refunded/failed create/update a transaction (manual reference `MAN-xxxxxxxxxx`, cash method fallback) **atomically**; ActivityLog `payment_status_updated`.
- `DELETE /api/v1/admin/orders/{order}` — soft delete with ActivityLog capture; no child cascade.
- `GET /api/v1/admin/orders?source=shop4me` (or `GET /api/v1/admin/shop4me-orders`) — Shop4Me list with the same filters + stat cards.
- `PATCH /api/v1/admin/transactions/{transaction}/status` — enum-validated `TransactionStatus`; audit; affects dashboard revenue (CONFIRMED sums).

**Controllers**
- New `Api\V1\Admin\OrderController` (index/show/update/updateStatus/updatePaymentStatus/destroy) — deliberately share serialization with `Api\V1\Management\OrderController` rather than a divergent copy.
- New `Api\V1\Admin\Shop4meOrderController@index` (or a scope on OrderController).
- Extend `Api\V1\Admin\TransactionController` with `updateStatus`.

**SPA**
- Routes `/orders`, `/orders/:orderNumber`, `/orders/shop4me`; nav Commerce › Orders (badge `orders_pending`); detail page with status card (select + note + update), payment card, items/totals, customer/delivery cards, notes, activity timeline; transactions drawer gains an "Update status" action.

**Acceptance criteria**
- An admin can find any order by number/customer/store/status/payment/date, open it, change its status (customer emailed), adjust payment against transactions, edit fees/notes, soft-delete it, and see Shop4Me orders separately.
- Payment badges are **derived from transactions** on both lists (the working legacy semantics); the order status flow sends the customer email exactly once per transition; the pending-orders sidebar badge matches the list.
- Transaction override is enum-validated and audit-logged; revenue figures on the dashboard change accordingly.

**Improve on legacy**
- Do not port the edit form's customer/delivery inputs or the model-level no-op `payment_status` select (silently discarded in legacy); expose only transitions that actually persist.
- Whitelist sort columns (legacy passed raw `sort_by` into `orderBy`).
- Soft delete only, with a clearer confirm ("hidden from lists; retained for refunds/audit") instead of the legacy "cannot be undone" copy; do not claim item cascade.
- Fix the Shop4Me payment badge bug (legacy `@switch` against strings never matched enum values → every row "Unpaid").

---

## 9. Workstream 6 — Store moderation & lifecycle — M

**Delivers (6):** `SC-STORE-1` directory filters; `SC-STORE-2` store detail; `SC-STORE-3` store create; `SC-STORE-4` store edit; `SC-STORE-5` suspend/reactivate with reason; `SC-STORE-6` delete with guards.

**Depends on:** WS1, WS2 (main store), WS15 (product/category panels on the detail page — those panels can land after this WS), WS4 (owner/business links).

**API**
- Extend `GET /api/v1/admin/stores` — add `from`/`to`; `q` over name/store_id/owner name; `is_main`; ownership/business type; logo path.
- Extend `GET /api/v1/admin/stores/{store}` — description, support email/phone, address, socials, ownership/business type, `business_code`, owner contact, recent products/categories/packs counts.
- `POST /api/v1/admin/stores` — 16-field create incl. logo upload, slug normalisation, main-store bootstrap rule.
- `PUT /api/v1/admin/stores/{store}` — slug uniqueness retry, logo replacement, status guard for the main store, owner email on inactive/suspended transition.
- `POST /api/v1/admin/stores/{store}/suspend` and `.../activate` — **required reason ≤2000**, owner emailed (`StoreSuspended`/`StoreReactivated`), main-store block on suspend.
- `DELETE /api/v1/admin/stores/{store}` — guards: main store; any order not `completed`; any transaction not `confirmed`; soft status `deleted`.

**Controllers**
- Extend `Api\V1\Admin\StoreController`.

**SPA**
- `StoresView.vue`: FilterPanel (status incl. a **working** deleted filter or none at all, q, created range), Main badge, logo, Owner/Type columns, shop-link, per-row kebab (View/Edit/Suspend/Activate/Delete). Detail becomes full route `/stores/:storeId` with quick actions (Edit, Suspend/Activate, Add Product, Add Category, Edit Slides) and Products/Categories/Packs panels.

**Acceptance criteria**
- Suspend/activate require a reason and email the owner; the homepage store cannot be suspended or deleted; delete refuses with the same three guards as legacy (main store / incomplete orders / unconfirmed transactions).
- Create/edit round-trips every legacy field incl. logo replace (old file deleted) and slug; the main-store bootstrap is honoured per the `ALLOW_MS_SETUP` decision; list filters combine and drill through.

**Improve on legacy**
- Restrict the Edit status select to active/inactive/suspended — legacy's edit form could set `deleted` directly, bypassing the delete guards.
- Make the "Deleted" filter option actually work (legacy applied `status != deleted` before the filter), or drop it.
- Store detail should link to the (future) transfer/product screens rather than embedding dead modals.

---

## 10. Workstream 7 — Dashboard parity completion — M

**Delivers (9):** `DC-1.1` KPI row parity; `DC-1.2` stats grid parity; `DC-1.4` payment donut date range; `DC-1.6` store performance table; `DC-1.7` pending transfers panel; `DC-1.8` recent transactions panel; `DC-1.9` recent orders panel; `DC-1.10` store selector + date range; `AB-14` KYC tile deep link.

**Depends on:** WS1; panels additionally need WS5 (orders/transactions) and WS15 (transfers) — build the card/filter items first, land the panel items after those workstreams.

**API** — extend `GET /api/v1/admin/dashboard`:
- Params: `store_id`, `from`, `to` (plus existing `days`).
- Fields: `stock_value`, `total_warehouses`, `out_of_stock`, `open_pos_sessions`, `units_in_stock`; `low_stock` with an explicit threshold (see improve); `recent_transactions` (10 latest confirmed), `recent_orders` (10 latest), `pending_transfers` + `transfer_stats`.
- `top_stores` → every non-deleted store with revenue today/MTD, orders today, product count, POS live/offline, last-sale age, drill-through id. Apply the store filter to exactly what legacy scoped (four KPI tiles, stock value/counts, MTD, both charts, donut, transfers, store table) and **not** to the stores/customers counts (verify correction C1).
- `payment_breakdown` respects `from`/`to`.

**Controllers**
- Extend `Api\V1\Admin\DashboardController` (heavy but single-file).

**SPA**
- `DashboardView.vue`: store selector + date range in the filter bar; KPI/stat tiles restored to legacy set (with modern click-throughs); payment donut date-scoped; Store Performance table with links; panels for transfers/transactions/orders; KYC tile becomes a link to the filtered queue.

**Acceptance criteria**
- A multi-store operator can slice the dashboard by store and read the same numbers legacy showed (revenue today/MTD, orders today, stock value, low/out-of-stock, POS live, last sale).
- Store filter scope matches the corrected legacy scope exactly; date range scopes the payment donut; panels deep-link to their lists.

**Improve on legacy**
- Restore the ≤10 low-stock threshold or make it configurable — the new API silently uses 1–5.
- Keep (and extend) the new-value additions: 7/30/90 range pills, auto-refresh, skeletons, clickable tiles.
- Either render or remove the dead `revenue_series`/`orders_series` payload.
- Default the payment donut to MTD rather than all-time (legacy never scoped it globally, but all-time mismatches every surrounding window).

---

## 11. Workstream 8 — User moderation completion — L

**Delivers (10):** `UA-1` directory filters/stats; `UA-2` detail console; `UA-3` edit-user audit entry; `UA-4` suspend parity; `UA-5` activate parity; `UA-7` admin password reset; `UA-8` delete user; `UA-9` restore user; `UA-10` impersonation end-to-end; `UA-12` staff parity.

**Depends on:** WS1; WS3 (`KycApprovalService`); WS2 (main-store guard).

**API**
- Extend `GET /api/v1/admin/users` — `has_business`, `subscription` filters; stat block (owners/staff/suspended/unverified); Plan column value; default role filter decision (legacy defaulted to owners).
- Extend `GET /api/v1/admin/users/{account_code}` — location, last IP, joined date (already in payload), `force_password_change`, business block (stores/warehouses/team/orders counts + per-store status), subscription + last-10 payments, last-25 activity feed.
- `POST /api/v1/admin/users/{account_code}/reset-password` — temp password `XXXX-xxxx-NNNN`, `force_password_change=true`, queue `UserPasswordResetMail`; fallback returns the temp password in the response if queuing fails.
- `DELETE /api/v1/admin/users/{account_code}` — soft (`status=deleted`), guards (main store, open orders/transactions).
- `POST /api/v1/admin/users/{account_code}/restore` — `deleted → active`.
- Extend suspend: required reason, already-suspended guard, main-store guard, email, audit. Extend activate: default reason (legacy hardcoded — see improve), KYC auto-approval, reactivation email, audit.
- Complete impersonation: return tokens with a clear hand-off contract; admin SPA opens the management app with the pair (e.g. magic-link/query exchange); management SPA shows a banner ("You are viewing as X") + "Return to admin" calling `stop-impersonation`.

**Controllers**
- Extend `Api\V1\Admin\UserController`; complete `Api\V1\Auth\ImpersonationController`; management-SPA banner is a cross-repo task (storify-management).

**SPA**
- `UsersView.vue` parity (stats strip, filters, plan column, bulk result reporting); add full route `/users/:accountCode` with the console sections (Account/Business/Subscription/Activity) replacing the drawer-only pattern; drawer keeps quick actions.

**Acceptance criteria**
- A support admin can reset a locked-out user's password, suspend/activate with reason + email, verify/unverify, delete (guarded) and restore, and impersonate end-to-end (land in the management app as the user, return to admin with session restored).
- Every one of the ten legacy `user_*` audit actions writes an ActivityLog row with old/new values.
- Directory filters/stats match legacy; staff rows reach the same actions.

**Improve on legacy**
- Do not build an activation reason form — legacy used hidden hardcoded reasons; a default reason restores parity, keep suspend's real reason UI.
- Per-row bulk action results (legacy bulk is a new-stack addition; report failures per row instead of `Promise.all` silence).
- Surface `created_at`/`last_login_at` the API already returns; add field-level errors in the edit form.

---

## 12. Workstream 9 — Platform customer console — M

**Delivers (5):** `UA-19` customer directory; `UA-20` customer detail; `UA-21` edit customer; `UA-22` suspend customer; `UA-23` activate customer.

**Depends on:** WS1, WS5 (order/transaction links in detail).

**API**
- `GET /api/v1/admin/customers` — stat cards (total/active/suspended/orders), search (name/email/phone/account_id), status + country filters, whitelisted sort, 20/page.
- `GET /api/v1/admin/customers/{customer}` — stats, customer info, address, last-10 orders, last-10 transactions, activity feed. Bound by `account_id`.
- `PUT /api/v1/admin/customers/{customer}` — first/last name, email unique, phone, location, status; setting ACTIVE marks email verified, any other status clears `email_verified_at`.
- `POST /api/v1/admin/customers/{customer}/suspend` — required reason ≤500; transaction: clear `email_verified_at`, status suspended, `CustomerAccountSuspendedMail`.
- `POST /api/v1/admin/customers/{customer}/activate` — status active, verify email if not, `CustomerAccountActivatedMail`.

**Controllers**
- New `Api\V1\Admin\CustomerController` (mirrors `Api\V1\Management\CustomerController` payloads where fields overlap).

**SPA**
- Routes `/customers`, `/customers/:accountId`; nav Navigation › Customers.

**Acceptance criteria**
- Admin can search/filter customers platform-wide (incl. country), open a customer and see orders/transactions/activity, edit details, suspend (reason captured + email) and activate (email).
- Status semantics (email verification flipping) match legacy; audit rows written.

**Improve on legacy**
- Validate sort params (legacy unvalidated); drop the unused `this_month` stat or display it deliberately.
- Country filter should be derived from a maintained list/cache rather than the legacy join each request.

---

## 13. Workstream 10 — Admin accounts & invitations — M

**Delivers (6):** `UA-13` admin list; `UA-14` invite admin; `UA-15` resend invite; `UA-16` change role; `UA-17` remove admin; `UA-18` accept-invitation screen.

**Depends on:** WS1. The accept endpoint already exists — this workstream is the cheapest high-value win.

**API**
- `GET /api/v1/admin/admins` — platform admins with roles, pending/active status.
- `POST /api/v1/admin/admins` — email unique across `users`+`customers`, platform role (business_id null), creates `role=admin`, `status=invited`, 64-char token, unusable password, `force_password_change`; queue `AdminInvitationMail`.
- `POST /api/v1/admin/admins/{admin}/resend` — rotate token, refresh `invited_at`, re-queue; warn when already accepted.
- `PUT /api/v1/admin/admins/{admin}` — role change via `syncRoles`; guard self + superadmin.
- `DELETE /api/v1/admin/admins/{admin}` — hard delete; guard self + superadmin.
- Accept flow: `GET/POST /api/v1/admin/invitations/{token}` already exists — extend the payload to distinguish "already accepted" from "invalid" (legacy did; new API collapses both to 404).

**Controllers**
- New `Api\V1\Admin\AdminController`; extend `Api\V1\Auth\InvitationController`.

**SPA**
- Route `/admins` (Navigation › Users › Admins); invite modal (email + role), row role select, resend, remove; accept-invitation route `/accept-invitation/:token` (name + password min 8, confirmed).

**Acceptance criteria**
- Full loop works in-product: invite → email → accept screen → login → role change → remove; guards prevent self/superadmin modification; tokens rotate on resend; duplicates across users/customers refused.
- `admin.admins` permission gates the screen (previously seeded but unusable).

**Improve on legacy**
- Distinguish invalid vs accepted invitation states in the API and SPA.
- Audit rows for invite/resend/role-change/remove (legacy logged; new stack currently nothing).

---

## 14. Workstream 11 — Subscriptions, plans & early access — M

**Delivers (7):** `OF-3.1` subscriptions oversight list; `OF-3.2` subscription plan CRUD; `CS-8` pass list; `CS-9` create/edit pass; `CS-10` activate/deactivate; `CS-11` guarded delete; `CS-12` pass detail + usage history.

**Depends on:** WS1, WS4 (business links).

**API**
- `GET /api/v1/admin/subscriptions` — status (active/trial/expired/cancelled) + `q` over business/plan; 15/page.
- `GET|POST /api/v1/admin/subscription-plans`; `PUT|DELETE /api/v1/admin/subscription-plans/{plan}` (bound by `plan_code`) — name, currency, amount, interval (+count), sort, active/default (exclusive), `is_trial`+`trial_days`, features array; delete refused when active subscriptions exist.
- `GET|POST /api/v1/admin/early-access`; `GET|PUT|DELETE /api/v1/admin/early-access/{code}`; `POST /api/v1/admin/early-access/{code}/toggle-status` — code (3–50, uppercased, unique, immutable on edit), max_uses (blank=unlimited), description; delete refused when used; detail includes usage history (business, store, used_at).

**Controllers**
- New `Api\V1\Admin\SubscriptionController`, `SubscriptionPlanController`, `EarlyPassController`.

**SPA**
- `/subscriptions`; `/settings/subscription-plans` (or Commerce); `/early-access` + `/early-access/:code` usage history; nav under Businesses › Early Access.

**Acceptance criteria**
- Plans CRUD with exclusive default, guarded delete, and trial fields that **actually persist**; subscription list filters by status and searches business/plan; pass lifecycle (create → redeem → auto-deactivate at limit → toggle → guarded delete) matches legacy semantics; usage history shows who redeemed, on which store, when.

**Improve on legacy**
- Wire `is_trial`/`trial_days` properly — legacy rendered the fields but the validator dropped them.
- Disable Delete for used passes in the UI instead of round-tripping the error; encode "exhausted pass toggle" semantics explicitly.
- Escape the review/modal JS injection quirk (legacy injected descriptions into onclick strings).

---

## 15. Workstream 12 — Money configuration: VAT, payment methods, bank accounts — M

**Delivers (6):** `OF-6.1` VAT rates; `OF-6.2` VAT zero-rate disable; `OF-7.1` payment method toggle; `OF-8.1` bank account list; `OF-8.2` bank account create/edit with logo; `OF-4.2` coupon code editing (small parity gap on the existing coupon screens).

**Depends on:** WS1.

**API**
- `GET|POST /api/v1/admin/vats`; `PUT|DELETE /api/v1/admin/vats/{vat}`; `POST /api/v1/admin/vats/{vat}/toggle` (creates a 0% active superseding record, deactivating the previous rate **on every path**).
- `GET /api/v1/admin/payment-methods`; `POST /api/v1/admin/payment-methods/{paymentMethod}/toggle`.
- `GET|POST /api/v1/admin/bank-accounts`; `PUT|DELETE /api/v1/admin/bank-accounts/{bankAccount}`; `POST .../toggle-active`; logo upload (jpeg/png/gif ≤2 MB, old file deleted on replace/delete).
- Extend `PUT /api/v1/admin/coupons/{coupon}` to accept `code` (unique-ignoring-self) if code editing is wanted; SPA re-enables the field.

**Controllers**
- New `Api\V1\Admin\VatController`, `PaymentMethodController`, `BankAccountController`; extend `CouponController`.

**SPA**
- Routes `/settings/vat`, `/settings/payment-methods`, `/settings/bank-accounts` (Settings section).

**Acceptance criteria**
- Active VAT rate is visible and changeable; the zero-rate disable action behaves as a documented business rule; payment method toggles immediately affect checkout; bank accounts CRUD with logo upload feeds payment instructions.
- Single-active VAT invariant holds after create, update and toggle.

**Improve on legacy**
- Enforce single-active VAT on the toggle path too (legacy toggle left the previous rate active).
- Do not build the dead `bank-accounts/{id}` show route.
- Keep fee/kobo consistency with POS pricing; whitelist.

---

## 16. Workstream 13 — Platform accounting (Storify's own books) — L

**Delivers (6):** `OF-5.1` books dashboard; `OF-5.2` chart of accounts; `OF-5.3` journal list; `OF-5.4` journal entry detail; `OF-5.5` P&L / Balance Sheet / Trial Balance reports; `OF-5.6` mappings + fiscal periods + year close.

**Depends on:** WS1. Reuses the management accounting services scoped to `business_id = null` — no new accounting engine.

**API**
- `GET /api/v1/admin/accounting` — totals (assets/liabilities/revenue/expenses/net profit), recent entries, cash & clearing balances; bootstrap via `LedgerSetupService::ensureForBusiness(null)`.
- `GET /api/v1/admin/accounting/accounts` — grouped chart.
- `GET /api/v1/admin/accounting/journal` (+ `?q=`, 20/page); `GET /api/v1/admin/accounting/journal/{entry}` (non-platform entries 404).
- `GET /api/v1/admin/accounting/reports?report=pnl|balance_sheet|trial_balance&from=&to=&as_of=`.
- `GET /api/v1/admin/accounting/settings`; `PUT .../settings/mappings`; `POST .../settings/periods/{period}/close|reopen`; `POST .../settings/years/{year}/close`.

**Controllers**
- New `Api\V1\Admin\AccountingController` delegating to `LedgerSetupService`, `LedgerReportService`, `LedgerClosingService` (same as management, platform scope).

**SPA**
- `/accounting` (+ `/accounting/accounts`, `/accounting/journal`, `/accounting/journal/:id`, `/accounting/reports`, `/accounting/settings`) — Commerce or a Finance section.

**Acceptance criteria**
- Platform books readable end-to-end; reports match ledger data; mappings save; period/year close guards future entries; year close posts retained earnings; only `business_id=null` data is exposed.

**Improve on legacy**
- Optional CSV export of journal; keep the management serializer shared to avoid two ledger dialects.

---

## 17. Workstream 14 — Warehouses & stock transfers — L

**Delivers (4):** `SC-WH-1` warehouse list; `SC-WH-2` warehouse detail; `SC-WH-3` transfer list; `SC-WH-4` transfer detail + approve/reject/dispatch/receive/cancel + timeline.

**Depends on:** WS1, WS4/WS6 (links from business/store pages).

**API**
- `GET /api/v1/admin/warehouses` (status/q incl. business name, 15/page); `GET /api/v1/admin/warehouses/{warehouse}` (metrics, sections, last-15 movements). Bound by `warehouse_code`.
- `GET /api/v1/admin/transfers` (status over all 8 `TransferStatus` cases, q over code/locations); `GET /api/v1/admin/transfers/{transfer}`.
- `PATCH /api/v1/admin/transfers/{transfer}/approve` (per-line `approved_quantities`, clamp 1..requested); `/reject` (required `rejection_reason`); `/dispatch`; `/receive`; `/cancel` — all **delegating to `Management\StockTransferController`** so the stock ledger has one writer.

**Controllers**
- New `Api\V1\Admin\WarehouseController`, `Api\V1\Admin\StockTransferController` (thin delegator, mirroring legacy).

**SPA**
- `/warehouses`, `/warehouses/:warehouseCode`, `/transfers`, `/transfers/:transferCode` (timeline, per-line approve quantises with over-adjust highlight, reject modal, dispatch/receive/cancel confirms).

**Acceptance criteria**
- Transfer status matrix (`canTransitionTo`) enforced; approved quantities clamped and highlighted when reduced; rejection reason required; stock movements write through the management service only; timeline shows actors; admin cancel uses an **admin** route.

**Improve on legacy**
- Add the proper admin-scoped cancel route (legacy's admin Cancel button posted to a management route — bug).
- Show approved-vs-requested deltas clearly; keep the delegated single-writer shape.

---

## 18. Workstream 15 — Product & category catalogue — L

**Delivers (9):** `SC-CATALOG-1` platform product list; `CATALOG-2` create; `CATALOG-3` edit; `CATALOG-4` detail; `CATALOG-5` activate/deactivate; `CATALOG-6` delete; `CATALOG-7` category list; `CATALOG-8` create; `CATALOG-9` edit/delete.

**Depends on:** WS1, WS4/WS6 (store scoping links).

**API**
- `GET /api/v1/admin/products` (+ `?store_id=`, status, q over name/code/store/category, from/to, per_page 10/50/100) and `GET /api/v1/admin/stores/{store}/products`; `POST /api/v1/admin/products`; `GET|PUT|DELETE /api/v1/admin/products/{product}`; `PUT /api/v1/admin/products/{product}/status`; store-scoped detail `GET /api/v1/admin/stores/{store}/products/{product_code}`.
- `GET|POST /api/v1/admin/categories` (+ store-scoped list), `PUT|DELETE /api/v1/admin/categories/{category}`.
- Product create/edit: variant synchronisation, bulk pricing, multi-image with primary picker, image delete, ActivityLog rows; product code + slug server-generated; initial stock ledger entry.

**Controllers**
- New `Api\V1\Admin\ProductController`, `Api\V1\Admin\CategoryController` — decide one shared serializer strategy with `Api\V1\Management\ProductController` (see §21).

**SPA**
- `/products`, `/products/:productCode`, `/products/create|edit`, `/categories` (+ store-scoped variants from the store detail quick actions).

**Acceptance criteria**
- Platform-wide list filters/search/page-size work; store-scoped lists work; create/edit round-trips variants (create/update/delete-on-removal), images (primary + delete), bulk pricing; activate/deactivate and delete preserve list paging; stock ledger gets the initial entry.

**Improve on legacy**
- Do **not** build digital-file / section / warehouse fields the legacy admin UI never rendered unless product wants them (they were request-only).
- One product serializer shared with the management API (decide now — reusing management endpoints with widened scope, or an admin variant; doing both creates divergence).
- Fix upload-limit error translation to stay human-readable.

---

## 19. Workstream 16 — Support inbox — M

**Delivers (4):** `CS-4` inbox list + counters; `CS-5` detail/thread; `CS-6` reply (customer email); `CS-7` delete.

**Depends on:** WS1. **Urgent:** storefront submissions already write `pending` rows nobody can read.

**API**
- `GET /api/v1/admin/support-messages` — pending/replied counters, store context, pagination, status/store filters + search (legacy had none — add).
- `GET /api/v1/admin/support-messages/{message}` — full thread with `replied_by_type` label.
- `POST /api/v1/admin/support-messages/{message}/reply` — required reply ≤2000; sets reply/status/replied_by/`replied_at`; queue `SupportMessageReplyMail`; define re-reply semantics (block vs re-open).
- `DELETE /api/v1/admin/support-messages/{message}`.
- Also wire a **platform notification** when a new storefront message arrives (currently `AdminNewSupportMessageMail` goes only to the store).

**Controllers**
- New `Api\V1\Admin\SupportMessageController`.

**SPA**
- `/support-messages` with counters, store column, detail modal/drawer, reply modal, delete confirm; nav Content › Support Messages with pending badge.

**Acceptance criteria**
- Every message readable, filterable, replyable (customer receives the email), deletable; counters match; `status` transitions are visible.

**Improve on legacy**
- Add pagination/search/filters (legacy rendered every row); add a "Close" action so the `closed` enum value stops being dead; define admin-vs-business ownership of a thread; guard re-replies explicitly.

---

## 20. Workstream 17 — Delivery routes (platform-wide) — M

**Delivers (4):** `CS-14` route list; `CS-15` create/edit; `CS-16` enable/disable; `CS-17` delete.

**Depends on:** WS1. **Requires a scope decision** (below) before build.

**API**
- `GET|POST /api/v1/admin/delivery-routes`; `PUT|DELETE /api/v1/admin/delivery-routes/{route}`; `POST .../toggle` — country/state/area/fee (NGN input ×100 to kobo)/days 1–60/active.

**Controllers**
- New `Api\V1\Admin\DeliveryRouteController`.

**SPA**
- `/settings/delivery-routes` (Settings section per legacy nav).

**Acceptance criteria**
- CRUD + toggle round-trips kobo↔NGN correctly; disabled routes disappear from checkout.

**Scope decision (blocking):** legacy admin routes are platform-wide (`store_id = NULL`) and legacy checkout showed them; the new storefront reader filters strictly by `store_id`, and the seeder creates only global (NULL) routes — a fresh install shows **zero** delivery options. Before building, decide: (a) checkout resolves store routes first then falls back to global defaults, or (b) admin routes are retired in favour of the business store-route CRUD. Recommendation: (a), with the admin screen managing global defaults.

**Improve on legacy**
- Block (or soft-delete) routes referenced by historical orders; add a state/area picklist; note fee round-trip truncation for kobo partials.

---

## 21. Workstream 18 — Marketing content: testimonials & company services — M (P2)

**Delivers (4):** `CS-1` testimonial list; `CS-2` create/edit; `CS-3` delete; `SC-CONTENT-7` company services CRUD + toggle + reorder (the only content type with a live new-stack consumer).

**Depends on:** WS1.

**API**
- `GET|POST /api/v1/admin/testimonials`; `PUT|DELETE /api/v1/admin/testimonials/{testimonial}` — name/occupation/message/position/status + photo upload.
- `GET|POST /api/v1/admin/company-services`; `PUT|DELETE /api/v1/admin/company-services/{companyService}`; `POST .../toggle`; `POST .../reorder` — title, description, page link (stored without leading slash, unique), background image ≤10 MB; bust `nav_company_services` on every mutation.

**Controllers**
- New `Api\V1\Admin\TestimonialController`, `Api\V1\Admin\CompanyServiceController`.

**SPA**
- `/content/testimonials`, `/content/company-services` (Content section); drag-reorder for services (SortableJS or equivalent).

**Acceptance criteria**
- Testimonials CRUD reflects on `GET /api/v1/home` (≤6 active, ordered); company services CRUD reflects immediately in the home services list and public nav.

**Improve on legacy**
- Fix the live testimonial photo mismatch: legacy stored base64 `data:` URIs, the new home API builds `storage/` URLs → broken images. Store real uploads and patch the serializer/backfill for existing rows. Decide the public cap (legacy admin managed unlimited, home caps at 6).
- Paginate/testimonial ordering explicitly; escape any injected JS (legacy quirk).

---

## 22. Deferred features (explicit, with reasons)

| Feature (audit ref) | Reason for deferral |
|---|---|
| Platform API keys vault (`DC-2.7`) | Legacy UI renders no inputs (unreachable by UI); every ordinary save nulls the column; nothing reads it. Retire; if third-party keys are ever needed, design a separate masked key-management feature. |
| Legacy vendor bookmark redirects (`AB-17`) | Obsolete for a SPA on a new host; only relevant if the legacy `/office/*` domain must keep serving old bookmarks. |
| Delivery intervals manager (`DC-4.1` / `CS-18`) | No consumer in legacy or new stack; legacy delete guard calls a non-existent relation (500s every delete). Revive only with a recurring-delivery/family-pack feature and a real usage guard. |
| Page styling CRUD (`SC-CONTENT-2/3/4` / `CS-19/20`) | No renderer consumes `PageStyling` anywhere; building the admin CRUD alone changes nothing visible. Build together with a storefront render hook, or retire. |
| Storefront slides (`SC-CONTENT-1`) | `StorefrontSlide` is not consumed by the new storefront/home apps; ship with the carousel consumer. |
| Feature CTAs (`SC-CONTENT-5/6`) | `Feature` has no new-stack consumer; confirm the home app still needs this content type before rebuilding. |
| KYC "request more info" net-new status (`AB-13` net-new half) | Legacy has no such state; reject+resubmit reproduces legacy exactly. Net-new status + notification needs a product decision. Management-side resubmission screen is tracked in the management roadmap. |
| Early-access redemption endpoint (`CS-13`) | Business-audience (management roadmap); admin issuance (WS11) can ship but end-to-end verification waits on the subscription/plans API landing. |
| Legacy dead surfaces (see §23) | Not features — do not port. |

## 23. Do NOT rebuild (dead legacy surfaces)

- `Admin\BusinessController@create/edit` + missing `admin.businesses.create/edit` views; `Admin\StoreController@create/edit` methods referencing non-existent store create/edit Blade files.
- `admin.bank-accounts.show` (renders a non-existent view → 500).
- Admin order edit form's customer/delivery inputs and the no-op `payment_status` select (silently discarded); the dead "bulk order finalized" modal.
- `/office/executive` redirect (nothing to build unless bookmark parity is wanted).
- `StorefrontSlideController::searchProducts` (routed nowhere); the `/api/superadmin/**` legacy leftover routes.
- KYC `payload`/`selfie`/document-id display is a deliberate **addition** (see WS3), not a legacy parity item.

## 24. Legacy defects we fix rather than clone (consolidated)

1. KYC reject flashes "owner notified" but sends no email → add the email (WS3).
2. KYC auto-approval persisted only status + reviewed_by (no column / not fillable) → persist properly (WS3).
3. KYC review never showed selfie, document type/id, payload → show them (WS3).
4. No status guard on approve/reject (re-approve by direct POST) → add guard (WS3).
5. Business status divergence (`Business.status` read, owner `User.status` written) → one canonical field + explicit cascade (WS4).
6. Business/store/user delete without guards would allow disabling mid-fulfilment → reproduce main-store + open-orders guards (WS2/4/6/8).
7. Admin order index passed raw `sort_by` into `orderBy` → whitelist (WS5).
8. Shop4Me payment badge compared enum to strings → derive from transactions (WS5).
9. Settings form rendered no errors; stored raw `og_type`/frequency strings → per-field errors + enums (WS2).
10. VAT toggle left the previous rate active → enforce single-active (WS12).
11. Store edit could set `deleted` bypassing delete guards → restrict statuses (WS6).
12. Store "Deleted" filter could never return rows → make it work or drop it (WS6).
13. Admin transfer Cancel posted to a management route → admin-scoped cancel (WS14).
14. Early-pass/description injected unescaped into modal JS → escaped data binding (WS11).
15. Subscription plan `is_trial`/`trial_days` UI dead (validator dropped them) → wire them (WS11).
16. Admin re-reply overwrite with no semantics; `closed` status dead → explicit re-open/close actions (WS16).
17. Testimonial base64 photos break the new home API → storage-path uploads + backfill (WS18).
18. Dashboard/settings screens were never audited (middleware blind spot) → cover them (WS1).
19. Legacy `api_keys` write-only column nulled on every save → not ported (deferred).
20. Legacy store list lost owner/type/main-badge/shop-link in the rewrite → restore scanning columns (WS6) — a regression to fix, not a legacy bug.

## 25. Execution order summary

| # | Workstream | Features | Effort | Depends on |
|---|---|---|---|---|
| 1 | Activity log & audit trail | 3 | M | — |
| 2 | Platform settings & branding | 6 | L | WS1 |
| 3 | KYC review | 5 | M | WS1 |
| 4 | Business lifecycle & directory | 10 | L | WS1, WS2, WS3 |
| 5 | Platform orders oversight | 8 | L | WS1 |
| 6 | Store moderation & lifecycle | 6 | M | WS1, WS2 |
| 7 | Dashboard parity completion | 9 | M | WS1, WS5, WS14 (panels) |
| 8 | User moderation completion | 10 | L | WS1, WS2, WS3 |
| 9 | Platform customer console | 5 | M | WS1, WS5 |
| 10 | Admin accounts & invitations | 6 | M | WS1 |
| 11 | Subscriptions, plans & early access | 7 | M | WS1, WS4 |
| 12 | Money config (VAT/payments/bank/coupon parity) | 6 | M | WS1 |
| 13 | Platform accounting | 6 | L | WS1 |
| 14 | Warehouses & stock transfers | 4 | L | WS1, WS4, WS6 |
| 15 | Product & category catalogue | 9 | L | WS1, WS4, WS6 |
| 16 | Support inbox | 4 | M | WS1 |
| 17 | Delivery routes | 4 | M | WS1 + scope decision |
| 18 | Marketing content (testimonials/services) | 4 | M | WS1 |
| | **Total** | **112** | | |

**P0 slice (first four weeks of parallel work):** WS1 → WS2 → WS3 → WS5 (orders) → WS6 (store suspend/activate) → WS4. That restores platform settings, KYC approval, order oversight and store moderation — the daily-use core an admin console is measured by.
