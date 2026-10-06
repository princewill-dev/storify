# Verification pass — admin-businesses (audience: admin)

**Verifier method:** re-read the audit, then independently re-scanned the same legacy sources: `routes/v1/admin_dashboard.php` (businesses group lines 96–118, content group 155–159), the KYC/business surface of `routes/v1/management.php` (lines 105–106) and `routes/v1/staff.php` (no business routes), all four in-scope controllers in full (`Admin\BusinessController`, `BusinessKycApplicationController`, `BusinessTypeController`, `OwnershipTypeController`), `Admin\BusinessRequest`, every in-scope Blade view (11 files: `admin/businesses/{index,show,_modals}.blade.php`, `admin/businesses/kyc/{index,show}.blade.php`, `admin/business_types/{index,create,edit}.blade.php`, `admin/ownership_types/{index,create,edit}.blade.php`), the sidebar and dashboard widgets, the `KycApplication`/`Business`/`Store` models, the `KycStatus`/`BusinessStatus`/`StoreStatus`/`StoreType` enums, the KYC migrations, the management KYC submit path (`Management\KycController@submit`, `management/kyc.blade.php`), the new admin API (`routes/api/v1/admin.php`, `Api\V1\Admin\{BusinessController,UserController,DashboardController,SearchController}`), the new management API (`routes/api/v1/management.php`, `Api\V1\Management\StoreController`) and both SPAs (`storify-admin`: `router/index.ts`, `BusinessesView.vue`, `BusinessDetailView.vue`, `DashboardView.vue`, `UsersView.vue`, `endpoints.ts`, `useDataTable.ts`, `TableFooter.vue`; `storify-management`: grep for KYC/type consumption).

**Coverage verdict:** every in-scope legacy route (12 in the businesses/KYC group + 5 business-type + 5 ownership-type routes + 4 vendor redirects), every controller method, and all 11 in-scope view files map to a feature entry — no whole feature area is omitted (early-access is in scope of `admin-content-support.md` §C and was correctly excluded here; the `/office/subscriptions` surface is a different permission domain). All twelve "missing" statuses re-verified against the new stack and hold (no `POST`/`DELETE /admin/businesses`, no KYC route anywhere in `routes/api/**`, no type routes, no KYC string in `storify-management/src`, no business-type/ownership-type string in either SPA). All "exists/partial" statuses also hold — the verify/unverify endpoints are real implementations, the SPA suspend/activate modals are functional, and the dashboard `kyc_pending` tile is indeed an inert div. The errors found are four factual details, three of which would misdirect a parity rebuild of activate/KYC.

---

## Corrections

### 1. Feature 6 — the activate UI lives only on the index, not the detail page

Audit: "activate from the list kebab or **detail modal**", legacy views "`admin/businesses/index.blade.php`, `show.blade.php` (+ `_modals`)". Only `index.blade.php` has it: the row kebab's "Activate" and the `activateBusinessModal` (with the "will also approve their KYC submission" warning) are in `admin/businesses/index.blade.php` lines 80–85 and 275–301. `show.blade.php` header renders exactly three buttons — Edit Owner / Suspend / Delete (lines 13–23) — and `_modals.blade.php` (90 lines, read in full) contains only delete, edit and suspend modals. So in legacy a suspended business could **not** be reactivated from its detail page; the admin had to return to the list. The new SPA is *better* here (BusinessDetailView has an Activate button), so the audit should not attribute the detail-page affordance to legacy.

### 2. Feature 6 — legacy auto-approval persists less than the audit claims

Audit: the KYC application "is auto-approved with `reviewed_by`, `reviewed_at` and reviewer note 'Auto-approved during business activation: {reason}'". In `Admin\BusinessController@activate` (lines 270–283) the update is `update(['status','reviewed_by','reviewed_at','reviewer_notes'])`, but:

- there is **no `reviewed_at` column** anywhere — `vendor_kyc_applications` is created without it (`2025_11_12_190000_create_vendor_kyc_applications_table.php`) and no later migration adds it;
- `reviewer_notes` is **not in `KycApplication::$fillable`** (the model field is `review_notes`), and with no `preventSilentlyDiscardingAttributes` anywhere in `app/Providers/`, the key is silently dropped.

So the auto-approval really persists only `status = approved` and `reviewed_by`; no reviewer note, no review timestamp — and unlike `approve()`, it never sets `approved_at` (auto-approved applications keep `approved_at = null`). A rebuild aiming at parity should copy status + reviewed_by only, and decide deliberately (as gap #7 does for the other two legacy bugs) whether to fix the note/`approved_at` on port.

### 3. Feature 3 — the KYC panel's "review date" never renders

Audit: the detail panel shows "reviewer + review date". `show.blade.php` (lines 256–261) prints `Admin #{reviewed_by} on {{ optional($kyc->reviewed_at)->format('d M Y') }}`, and per correction 2 `reviewed_at` is not a column, so the date is always blank. Only the reviewer id is real.

### 4. Feature 10 / gap 7(b) — `kyc_document_id` is a fourth stored-but-undisplayed field

The audit lists `selfie_image_path`, `kyc_document_type_id` and `payload` as stored by the model but never shown on the review screen. It omits **`kyc_document_id`**, added by `2025_11_13_183000_add_kyc_document_id_columns.php`, captured as a **required** field on the business-side form ("NIN, BVN, Passport number" — `management/kyc.blade.php` line 117, written by `Management\KycController@submit` line 128), and never displayed by `admin/businesses/kyc/show.blade.php` either. The review screen is precisely where a reviewer needs the document number when judging the uploaded ID; add it to the list of fields a new review screen should surface (or explicitly drop).

---

## Smaller notes (no audit text change demanded)

- Feature 8 says the new SPA exposes verify in "row + bulk + drawer". `UsersView.vue` has **bulk** (lines 177–178) and **drawer/detail** (lines 285–286) actions plus a row-click into the drawer, but no row-level verify button. Cosmetic; the entry's "partial" verdict (missing on business screens) stands.
- The new SPA's single-row Activate is rendered only when `status === 'suspended'` (`BusinessesView.vue` lines 169–181; `BusinessDetailView.vue` lines 77–90), whereas the legacy kebab showed Activate/Suspend unconditionally — so a `pending`/`deleted` business has no single-row activate in the new list, though bulk Activate covers any selected status. No parity work needed; "SPA exists" for feature 6 remains fair.
- The new detail payload's `store_type` is the `StoreType` channel enum (`online`/`physical`/`both`, migration default `online`), which confirms the audit's point that it is not the ownership/business type name.
- Legacy `approve()`/`reject()` do **not** guard that the application is `submitted` — only the view hides the Take Action card. A rejected application can be re-approved by direct POST. Decide deliberately whether the port adds a status guard (the audit's feature 10 implies view-gating only).
- Re-confirmed correct while scanning: only `Api\V1\Admin\DashboardController` reads `KycApplication` in the whole `app/Http/Controllers/Api` tree (the count), so gap #1's "no code path can approve" stands; `Business::getRouteKeyName() = business_code` and `User::getRouteKeyName() = account_code`, so the SPA's URL scheme binds correctly; legacy `activate` never had the main-store guard that `suspend`/`destroy` had (gap #5 wording is exact); no request-more-info status exists anywhere (feature 13's judgement confirmed via `KycStatus` and a repo-wide grep).
