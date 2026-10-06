# Legacy gap audit — admin-businesses (audience: admin)

Scope: business lifecycle and KYC review. Business directory with filters and row actions, business create /
edit / delete, business detail (stores, warehouses, team, subscription, KYC), suspend / activate, owner
email verification, the KYC application queue and review screen (approve, reject with reason, resubmit), and
CRUD for business types and ownership types.

Legacy source of truth: `storify-api` Blade app —
- routes: `routes/v1/admin_dashboard.php` (superadmin, `/office/*` prefix), `routes/v1/management.php` (business-side KYC submission, for lifecycle context)
- controllers: `app/Http/Controllers/Admin/{BusinessController,BusinessKycApplicationController,BusinessTypeController,OwnershipTypeController}.php`
- views: `resources/views/admin/businesses/{index,show,_modals}.blade.php`, `resources/views/admin/businesses/kyc/{index,show}.blade.php`, `resources/views/admin/business_types/*`, `resources/views/admin/ownership_types/*`, dashboard widgets in `resources/views/admin/index.blade.php`

New stack: `routes/api/v1/admin.php`, `app/Http/Controllers/Api/V1/Admin/BusinessController.php`, and the
admin SPA `storify-admin` (`src/router/index.ts`, `src/views/BusinessesView.vue`,
`src/views/BusinessDetailView.vue`, `src/api/endpoints.ts`).

Status vocabulary: **exists** = fully usable in the new stack; **partial** = endpoint or screen present but
missing actions/fields/validations from legacy; **missing** = absent from the new stack.

Key context: the legacy Blade admin (`/office/*`) is still mounted in the same repo, so these screens remain
reachable from the old app while the new admin SPA exposes only Businesses (list + detail with
suspend/activate). The new admin API for this domain is four endpoints: `GET /admin/businesses`,
`GET /admin/businesses/{business_code}`, `POST .../suspend`, `POST .../activate`. **There is no KYC
endpoint or screen anywhere in the new stack** (and no KYC submission path in the new management API
either), no business create/edit/delete, and no business-type/ownership-type API or screen.

Permission gates in legacy: businesses + KYC queue/detail sit behind `permission:admin.businesses`;
business-types and ownership-types sit behind `permission:admin.content` and appear in the sidebar under
Settings.

---

## A. Business directory and account lifecycle

### 1. Business directory (search, filters, sorting, pagination)
- **What the admin could do (legacy):** open `GET /office/businesses` and see the business list with columns
  Business (name + `business_code`), Owner (name + email), Stores (count, with a "view" link into the store
  list pre-filtered by business name), Warehouses (count), Subscription (active plan name or "None"), Status
  badge (Active/Suspended/Deleted), and a per-row kebab menu. Filters (in a Filter modal): status
  (All / Active / Suspended / Deleted — with `deleted` excluded by default), free-text `q` (name,
  `business_code`, owner name, owner email; placeholder also advertises phone), and created date range
  `from`/`to`. Paginated 15 per page with the query string preserved; empty state.
- **Legacy:** `GET /office/businesses` (route `admin.businesses.index`) — `Admin\BusinessController@index` —
  `resources/views/admin/businesses/index.blade.php` (+ filter modal)
- **New:** API `GET /api/v1/admin/businesses` (`Api\V1\Admin\BusinessController@index`) → SPA `/businesses`
  (`storify-admin/src/views/BusinessesView.vue`)
- **Status:** API **partial**, SPA **partial**
- **Missing:** the created-date `from`/`to` filter; the "deleted" option in the status filter and the legacy
  default of hiding deleted rows (the new API returns everything unless a status is passed); the Warehouses
  count column; the Stores "view" link into `/stores?q=<business>`; the subscription blank state is a badge
  rather than a plan name is fine. The new SPA **adds** server-side sorting (name/status/created), a
  per-page control and multi-select bulk suspend/activate — improvements over legacy.
- **Effort:** S — **Priority:** P0

### 2. Create business (admin-provisioned owner account)
- **What the admin could do (legacy):** click "Add Business" on the index to open a modal that creates the
  business owner account: name (required), slug (hidden, auto-generated from name), email, phone, status
  (active/inactive). Guarded: if a superadmin-business already exists and `ALLOW_MS_SETUP != 1`, creation is
  refused with "multi-business crontrols is incomplete" (either from the modal POST or the view). On success
  the slug is normalised, a `business_created` log is written and `AdminBusinessCreated` is queued to all
  superadmin emails (fallback `mail.from.address`). Note `create()`/`edit()` controller methods and the
  `admin.businesses.create/edit` views are dead code — those routes are excluded from the resource; the
  modals are the live create/edit paths.
- **Legacy:** `POST /office/businesses` (route `admin.businesses.store`) — `Admin\BusinessController@store` —
  `resources/views/admin/businesses/index.blade.php` (create modal); form request `Admin\BusinessRequest`
- **New:** none — no `POST /api/v1/admin/businesses`, no "Add Business" button in the SPA
- **Status:** API **missing**, SPA **missing**
- **Missing:** the whole feature. Decide whether the new stack still wants admin-provisioned businesses (the
  guard text hints legacy considered multi-business provisioning incomplete); if built, it needs a
  password/invite flow and the admin notification mail.
- **Effort:** M — **Priority:** P1

### 3. Business detail page
- **What the admin could do (legacy):** open `GET /office/businesses/{owner}` for a full business console:
  - Header: business name + `business_code`, `prefix`, and action buttons Edit Owner / Suspend / Delete.
  - Summary cards: Stores count, Warehouses count, Team Members count, Subscription (active plan name and
    "Until {ends_at}" or "No Plan").
  - Business Details card: name, code, status badge, created date; then Owner block: name, email, phone,
    owner status, **Email Verified badge**.
  - Team table: every user of the business with name, email, Spatie roles, status.
  - Stores table: store name + `store_id` (links to the admin store page), **ownership type**, **business
    type**, status, plus a "Manage Stores" button.
  - Warehouses table: name, `warehouse_code`, **stock items count** (stock locations), status.
  - KYC Verification panel: status, submitted date, reviewer + review date, and "View KYC Details" link into
    the review screen (or "No KYC application submitted yet").
  - Fallback layout for an owner `User` without a `Business` record: account card, stores list, and a
    deprecation warning that the old business flow is deprecated.
- **Legacy:** `GET /office/businesses/{user}` (route `admin.businesses.show`) — `Admin\BusinessController@show` —
  `resources/views/admin/businesses/show.blade.php` + `_modals.blade.php` (edit/suspend/delete modals)
- **New:** API `GET /api/v1/admin/businesses/{business_code}` (`show`) → SPA `/businesses/:businessCode`
  (`storify-admin/src/views/BusinessDetailView.vue`)
- **Status:** API **partial**, SPA **partial**
- **Missing:** warehouses list (API payload has no warehouses at all); team-members list and roles; owner
  phone / owner status / email-verified badge; subscription end date and status; `prefix`; the KYC panel and
  link; store ownership type and business type names (the new payload only exposes a free-text `store_type`
  and a bare slug, and its store status uses a different vocabulary); the "Manage Stores" affordance; the
  no-Business-record fallback (the new detail is keyed on `business_code`, so such accounts are unreachable).
  The new SPA does add an owner card with account code and a subscription/currency card that legacy spread
  across cards, but it is materially thinner overall.
- **Effort:** M — **Priority:** P0

### 4. Edit business / owner details
- **What the admin could do (legacy):** from the list kebab or the detail page, open an Edit Business modal
  that updates the owner `User`: name (required), slug (hidden, auto-generated), email, phone, and status via
  a select with active / inactive / suspended / deleted. The detail-page variant appends `?redirect=show` so
  the admin returns to the detail page after saving; both versions log `business_update_requested` /
  `business_updated`.
- **Legacy:** `PUT /office/businesses/{user}` (route `admin.businesses.update`) — `Admin\BusinessController@update` —
  `admin/businesses/index.blade.php`, `admin/businesses/show.blade.php`, `admin/businesses/_modals.blade.php`
- **New:** no business-level update endpoint. Partially reachable via the users domain: `PUT /api/v1/admin/users/{account_code}`
  (`Api\V1\Admin\UserController@update`, name/email/phone only) in `UsersView.vue` — business owners are in
  scope (`role IN (business_owner, staff)`), but there is no entry point from the business screens, no status
  select, and no slug handling.
- **Status:** API **partial**, SPA **partial**
- **Missing:** an edit affordance on the business list/detail; status change with the legacy value set; slug
  normalisation; the `redirect=show` return flow; audit log. Decide whether editing an owner stays a users-domain
  action or gets a business-domain endpoint.
- **Effort:** S — **Priority:** P1

### 5. Suspend business (reason required, notification)
- **What the admin could do (legacy):** suspend from the list kebab or detail page via a modal with a
  **required reason** (max 2000). Refused with an error when the owner owns the platform main store
  (`Setting::value('main_store_id')`). Sets the owner `User.status = 'suspended'`, queues `BusinessSuspended`
  to the owner and logs `business_suspend_requested` / `business_suspended`.
- **Legacy:** `POST /office/businesses/{user}/suspend` (route `admin.businesses.suspend`) —
  `Admin\BusinessController@suspend` — `admin/businesses/index.blade.php`, `show.blade.php` (+ `_modals`)
- **New:** API `POST /api/v1/admin/businesses/{business_code}/suspend` (`suspend`) → SPA single-row action in
  `BusinessesView.vue` and header button in `BusinessDetailView.vue`, plus multi-select bulk suspend
- **Status:** API **partial**, SPA **exists**
- **Missing:** the main-store-owner guard; the suspension email; setting the owner `User.status` (legacy moved
  the owner account, the new API only flips `Business.status`); legacy log granularity (new logs
  `api.admin.business_suspended` only). Note the legacy inconsistency to resolve while porting: the list
  filter/status column reads `Business.status`, but legacy suspend/activate/delete write the owner
  `User.status`, so the two can diverge. The new API writes `Business.status` only — pick one canonical
  field and cascade deliberately.
- **Effort:** S — **Priority:** P0

### 6. Activate / reactivate business (auto KYC approval, notification)
- **What the admin could do (legacy):** activate from the list kebab or detail modal with a **required
  reason**; the modal warns "Activating this business will also approve their KYC submission". Sets the
  owner `User.status = 'active'`; **if a KYC application exists and is not approved, it is auto-approved**
  with `reviewed_by`, `reviewed_at` and reviewer note "Auto-approved during business activation: {reason}";
  queues `BusinessReactivated`; logs the activation and the KYC auto-approval; returns "Business activated
  and KYC approved" when it did both.
- **Legacy:** `POST /office/businesses/{user}/activate` (route `admin.businesses.activate`) —
  `Admin\BusinessController@activate` — `admin/businesses/index.blade.php`, `show.blade.php` (+ `_modals`)
- **New:** API `POST /api/v1/admin/businesses/{business_code}/activate` (`activate`) → SPA buttons in both views
  plus bulk activate
- **Status:** API **partial**, SPA **exists**
- **Missing:** the KYC auto-approval side effect (the single legacy path that could approve a KYC submission,
  now gone); the reactivation email; owner `User.status` cascade; the modal's explicit "this also approves
  KYC" warning (the new modal just says the business will be reactivated). See feature 11 for the KYC
  consequences.
- **Effort:** S — **Priority:** P0

### 7. Delete business (guarded, soft delete)
- **What the admin could do (legacy):** delete from the list kebab or detail page; confirmation modal says
  the action cannot be undone. The controller **refuses** when (a) the owner owns the main store, (b) any
  store of the business has an order that is not `completed`, or (c) any transaction belonging to those
  orders is not `confirmed`. Otherwise it marks `User.status = 'deleted'` (the model row survives) and logs
  the outcome at each guard.
- **Legacy:** `DELETE /office/businesses/{user}` (route `admin.businesses.destroy`) — `Admin\BusinessController@destroy` —
  `admin/businesses/index.blade.php` (modal), `show.blade.php` (+ `_modals`)
- **New:** none — no delete endpoint, no button
- **Status:** API **missing**, SPA **missing**
- **Missing:** the whole feature, including both guard sets (main store, incomplete orders, incomplete
  transactions) and the soft-delete semantics. Without it there is no way to remove a business from the
  directory.
- **Effort:** S — **Priority:** P1

### 8. Business owner email verification (verify / unverify)
- **What the admin could do (legacy):** on the business detail page the owner's Email Verified state is
  shown; the verification actions themselves live on the user detail page
  (`POST /office/users/{user}/verify|unverify`), which also writes an activity-log entry.
- **Legacy:** `POST /office/users/{user}/verify`, `POST /office/users/{user}/unverify` —
  `Admin\UserController@verify/unverify` — `admin/businesses/show.blade.php` (indicator),
  `admin/users/show.blade.php` (actions)
- **New:** API `POST /api/v1/admin/users/{account_code}/verify` and `/unverify`
  (`Api\V1\Admin\UserController@verify/unverify`) → SPA `UsersView.vue` (row + bulk + drawer)
- **Status:** API **exists**, SPA **partial**
- **Missing:** the Verified/Unverified indicator and any verify action on the business detail/list screens;
  the audit-log entry. Owners are reachable through `/users`, so this is a placement gap, not a capability
  gap.
- **Effort:** S — **Priority:** P2

---

## B. KYC review

### 9. KYC application queue
- **What the admin could do (legacy):** open `GET /office/business-kyc-applications` and see every
  application latest-submitted-first with status-count pills (Submitted / Approved / Rejected counts),
  a status dropdown filter (All statuses / Submitted / Approved / Rejected; the controller also accepts
  `draft`), and a table with columns #, Business (business name + code + owner name, linking to the business
  detail; falls back to owner name + email), Legal Name, Submitted date, Status badge, and a "Review" button.
  Paginated 20 with query string; empty state. A sidebar "KYC Submissions" item shows the pending count and
  the dashboard's KYC Pending card deep-links to the queue pre-filtered to `submitted`.
- **Legacy:** `GET /office/business-kyc-applications` (route `admin.business-kyc.index`) —
  `Admin\BusinessKycApplicationController@index` — `resources/views/admin/businesses/kyc/index.blade.php`;
  sidebar `resources/views/admin/components/sidebar.blade.php`; dashboard `resources/views/admin/index.blade.php`
- **New:** none — no endpoint, no SPA route/view, no sidebar entry. The admin dashboard stats include a
  `kyc_pending` count (`Api\V1\Admin\DashboardController`) but there is nothing behind the tile.
- **Status:** API **missing**, SPA **missing**
- **Missing:** the whole screen: status filter, count pills, table, pagination, deep link from the dashboard
  tile and a sidebar entry. This is the primary admin workflow for approving businesses; without it
  submitted applications cannot be seen at all.
- **Effort:** S — **Priority:** P0

### 10. KYC application review screen (applicant detail)
- **What the admin could do (legacy):** open `GET /office/business-kyc-applications/{application}` to see the
  full application: Legal name, Phone number, Date of birth, Address (line, city, state, country), Device
  (type + browser string), IP address, Identification ("View uploaded document" link to
  `storage/identification_document_path`, or "Not provided"), and Reviewer Notes when present. A Business
  Snapshot card shows owner name/email/phone and a "View business profile" link. The "Take Action" card is
  only rendered while the application status is `submitted`; otherwise the page says the application has
  already been approved/rejected. Header shows the status badge and `submitted_at` relative time.
- **Legacy:** `GET /office/business-kyc-applications/{application}` (route `admin.business-kyc.show`) —
  `Admin\BusinessKycApplicationController@show` — `resources/views/admin/businesses/kyc/show.blade.php`
- **New:** none
- **Status:** API **missing**, SPA **missing**
- **Missing:** everything. Note two legacy omissions worth fixing while building: the model stores
  `selfie_image_path`, `kyc_document_type_id` (KycDocumentType) and a `payload` array, but the review screen
  never displays them — a new review screen should show the selfie and document type, and surface `payload`
  or drop it deliberately.
- **Effort:** M — **Priority:** P0

### 11. KYC approve
- **What the admin could do (legacy):** submit the Approve form on the review page with optional
  `review_notes` (max 2000). Inside a DB transaction: status → `approved`, `approved_at = now()`,
  `rejected_at` cleared, `reviewed_by = admin`, owner `User.status → 'active'`; queues `KycApproved` to the
  owner in the same transaction (failures logged, not fatal); logs `admin.business_kyc.approved`; redirects
  back to the review page with a success flash.
- **Legacy:** `POST /office/business-kyc-applications/{application}/approve` (route `admin.business-kyc.approve`) —
  `Admin\BusinessKycApplicationController@approve` — `admin/businesses/kyc/show.blade.php`
- **New:** none. The only remaining approval-ish path in legacy (activate → auto-approve) is also absent
  from the new activate endpoint, so **no code path in the new stack can ever set a KYC application to
  approved**.
- **Status:** API **missing**, SPA **missing**
- **Missing:** the action, the atomic owner activation, the approved email, the review metadata.
- **Effort:** S (once 9/10 exist) — **Priority:** P0

### 12. KYC reject with reason
- **What the admin could do (legacy):** submit the Reject form with a **required** `review_notes` reason
  (max 2000, field-level error display). In a DB transaction: status → `rejected`, `rejected_at = now()`,
  `approved_at` cleared, `reviewed_by`, `review_notes` saved; owner `User.status → 'pending'`; logs
  `admin.business_kyc.rejected`; redirects back with a warning flash reading "KYC application rejected and
  the business owner notified". **No email is actually sent** — the flash over-promises (legacy bug to fix
  when porting).
- **Legacy:** `POST /office/business-kyc-applications/{application}/reject` (route `admin.business-kyc.reject`) —
  `Admin\BusinessKycApplicationController@reject` — `admin/businesses/kyc/show.blade.php`
- **New:** none
- **Status:** API **missing**, SPA **missing**
- **Missing:** the action, the owner-status change, and the notification the legacy message claimed
  (add a real rejection email in the new build).
- **Effort:** S (once 9/10 exist) — **Priority:** P0

### 13. "Request more info" / resubmission loop
- Judgement: **this transition does not exist in legacy.** `KycStatus` only has `draft`, `submitted`,
  `approved`, `rejected`; there is no info-requested state, endpoint or UI. The closest legacy behaviour is:
  the admin rejects with a reason → the owner sees the rejection and reason on `GET /management/kyc`
  (`Management\KycController@show` / `submit`, view `management/kyc.blade.php`, "Resubmit KYC" button) →
  resubmission resets the application to `submitted` and owner to `pending` again. So building reject
  (feature 12) plus a business-side resubmission path reproduces legacy exactly; a true "request more info"
  status (new `KycStatus` case + notification + business-side edit-without-rejection) would be net-new scope
  and should be confirmed with the product owner.
- **Legacy:** none directly — the resubmission half lives at `GET|POST /management/kyc`
  (`Management\KycController@show/submit`, view `resources/views/management/kyc.blade.php`)
- **New:** none — the new management API/SPA has no KYC submission or resubmission screen either, so even
  the reject→resubmit loop has no business-side half yet.
- **Status:** API **missing**, SPA **missing**
- **Missing:** reject-with-reason (12) is a prerequisite; then decide between "reject implies resubmit"
  (legacy parity) and a real `info_requested` status (net-new). Effort assumes reject exists.
- **Effort:** S for legacy parity (reject + resubmission), M for a net-new info-requested status —
  **Priority:** P2

### 14. Dashboard KYC/business widgets
- **What the admin could do (legacy):** the admin dashboard showed a "Businesses" KPI card (total + active
  count) and a "KYC Pending" card whose "Review →" link opened the queue filtered to `status=submitted`.
- **Legacy:** `GET /office/dashboard` — `Admin\AdminDashboardController@index` —
  `resources/views/admin/index.blade.php`
- **New:** `GET /api/v1/admin/dashboard` (`Api\V1\Admin\DashboardController@index`) returns `businesses`,
  `active_businesses`, `kyc_pending` and `recent_businesses`; `storify-admin/src/views/DashboardView.vue`
  renders the Businesses tiles, a Recent Businesses list linking to detail pages, and a KYC Pending tile.
- **Status:** API **partial**, SPA **partial**
- **Missing:** the KYC Pending tile is a plain div — not clickable, and there is no queue to link to
  (feature 9). Everything else matches or exceeds legacy (legacy had no recent-businesses list).
- **Effort:** S (after 9) — **Priority:** P2

---

## C. Business configuration lists

### 15. Business types CRUD
- **What the admin could do (legacy):** list business types alphabetically (paginated 20) with Edit and
  Delete (confirm modal) per row; create a type (name required, ≤255, unique, max-length validation); edit a
  type (name required, unique ignoring self); delete a type. Every action writes an info log. Linked from the
  Settings sidebar section; gated by `permission:admin.content`. These values feed the business
  setup/store forms (`businesses.business_type_id`, `stores.business_type_id`).
- **Legacy:** `GET|POST /office/business-types`, `GET /office/business-types/create`,
  `GET|PUT /office/business-types/{businessType}/edit`, `DELETE /office/business-types/{businessType}` —
  `Admin\BusinessTypeController` — `resources/views/admin/business_types/{index,create,edit}.blade.php`
- **New:** none — no API routes and no SPA screen; only the `BusinessType` model survives (loaded by
  `Api\V1\Management\StoreController@show` for store payloads).
- **Status:** API **missing**, SPA **missing**
- **Missing:** the whole CRUD. Cheap to build (single name field) and needed before any business/store setup
  UI can offer curated type lists.
- **Effort:** S — **Priority:** P1

### 16. Ownership types CRUD
- **What the admin could do (legacy):** exactly the same list / create / edit / delete flow as business types,
  for ownership types (name required, ≤255, unique), paginated 20, with info logs. Feeds
  `businesses.ownership_type_id` / `stores.ownership_type_id`.
- **Legacy:** `GET|POST /office/ownership-types`, `GET /office/ownership-types/create`,
  `GET|PUT /office/ownership-types/{ownershipType}/edit`, `DELETE /office/ownership-types/{ownershipType}` —
  `Admin\OwnershipTypeController` — `resources/views/admin/ownership_types/{index,create,edit}.blade.php`
- **New:** none — no API routes and no SPA screen; only the `OwnershipType` model survives.
- **Status:** API **missing**, SPA **missing**
- **Missing:** the whole CRUD (identical to feature 15).
- **Effort:** S — **Priority:** P1

### 17. Legacy `/office/vendors` bookmark redirects
- **What the admin could do (legacy):** old vendor-era bookmarks 301-redirected:
  `/office/vendors → businesses.index`, `/office/vendors/{user} → businesses.show`,
  `/office/vendor-kyc-applications[...] → business-kyc.*`.
- **Legacy:** `GET /office/vendors`, `GET /office/vendors/{user}`, `GET /office/vendor-kyc-applications[...]` —
  closures in `routes/v1/admin_dashboard.php`
- **New:** none (the SPA has its own routes).
- **Status:** API **missing**, SPA **missing** — judgement: **obsolete** for an SPA on a new host; only worth
  building if the legacy `/office/*` domain must keep serving old links. Kept in the inventory for
  completeness.
- **Effort:** S — **Priority:** P2

---

## Gaps worth calling out

1. **KYC is completely absent from the new stack.** There is no submission endpoint in the new management
   API, no review queue, no review screen, no approve/reject, and no admin UI at all. The admin dashboard
   happily renders a `kyc_pending` count that will only ever count rows nobody can act on. Since legacy
   `activate` was itself the auto-approve path and the new `activate` dropped that side effect, **no code
   path in the new stack can transition a KYC application to `approved`** — registered business owners can
   never complete verification. This is the single biggest hole in this domain.
2. **The business detail screen lost most of its console.** Warehouses, team members with roles, owner
   phone/status/email-verified, subscription end date, KYC panel, and ownership/business type per store are
   all missing from the new API payload and SPA. An admin cannot answer "who works here, what do they run,
   and are they verified" from the new detail page without leaving for `/users` and `/stores`.
3. **Write actions are thin.** Legacy create / edit / delete businesses are gone; only suspend/activate
   remain. Delete carried three safety guards (main store owner, incomplete orders, unconfirmed
   transactions) that must be reproduced (or consciously dropped) when it is ported.
4. **Side-effect parity is missing across the board.** Legacy queued `AdminBusinessCreated`,
   `BusinessSuspended`, `BusinessReactivated`, `KycApproved` (plus KYC-submitted mails to admins and
   business); the new API queues nothing and writes only terse `api.admin.*` log lines, where legacy wrote
   audit-style log entries for every action.
5. **Main-store protection is gone.** Legacy refused to suspend/delete (and create around) the platform main
   store owner via `Setting::value('main_store_id')`; the new suspend/activate has no such guard.
6. **Status semantics are inconsistent in legacy and must be settled deliberately.** Legacy list filter and
   status badge read `Business.status`, but legacy suspend/activate/delete mutate the owner `User.status`;
   the new API mutates `Business.status` only and never touches the owner. Whichever field is canonical, the
   cascade to the owner account (and to what the business can actually do at login) needs to be explicit.
7. **Two legacy bugs to fix, not port.** (a) KYC reject flashes "the business owner notified" but no
   notification is sent. (b) The review screen stores but never shows `selfie_image_path`,
   `kyc_document_type_id` and `payload`.
8. **Business types / ownership types have no consumer yet.** The new stack has neither CRUD for them nor a
   business-setup UI that uses them, so building the two small CRUDs first (S each) is cheap and unblocks
   store setup later.
9. **Legacy dead code to ignore:** `Admin\BusinessController@create/edit` and the referenced
   `admin.businesses.create/edit` views are unreachable (resource excludes those routes) — the modals are
   the real create/edit UI. The new build should not reproduce that structure.
10. **New-stack improvements to keep:** the SPA adds bulk suspend/activate with a single reason, server-side
    sorting and per-page control on the business list; the API adds a global search entry for businesses and
    a Recent Businesses dashboard widget legacy never had.
