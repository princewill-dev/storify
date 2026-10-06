# Legacy Gap Audit — Admin Content, Support & Access Programmes

**Domain:** `admin-content-support` · **Audience:** admin (platform office / superadmin panel)
**Legacy source of truth:** `/Users/mac/Desktop/my_files/work/storify/storify-api` — `routes/v1/admin_dashboard.php` (superadmin), `routes/v1/management.php` (business-side consumers), controllers `app/Http/Controllers/Admin/{TestimonialController,SupportMessageController,AdminEarlyPassController,DeliveryRouteController,DeliveryIntervalController,PageStylingController}.php`, views `resources/views/admin/{testimonials,support-messages,Earlyaccess,delivery_routes,styling}/**` + `resources/views/admin/settings/delivery_intervals.blade.php`
**New API:** `/Users/mac/Desktop/my_files/work/storify/storify-api` — `routes/api/v1/admin.php`, `routes/api/v1/storefront.php`, `routes/api/v1/home.php`, `app/Http/Controllers/Api/V1/Admin/**`, `app/Http/Controllers/Api/V1/Storefront/{CatalogController,SupportController}.php`, `app/Http/Controllers/Api/V1/Home/HomeController.php`
**New SPAs:** `/Users/mac/Desktop/my_files/work/storify/storify-admin` (`src/router/index.ts`, `src/views/**`, `src/layouts/AdminLayout.vue`, `src/api/endpoints.ts`); consumer apps `/Users/mac/Desktop/my_files/work/storify/storify-storefront`, `/Users/mac/Desktop/my_files/work/storify/storify-home`
**Status vocabulary:** `exists` = fully usable in the new stack; `partial` = endpoint or screen present but missing actions/fields/validations from legacy; `missing` = absent from the new stack.

---

## Executive summary

**None of the six admin screens in this domain exists in the new stack.** The new admin API (`routes/api/v1/admin.php`, 30 route registrations) contains only dashboard, search, businesses, stores, users, transactions and coupons — there is **no** testimonial, support-message, early-access, delivery-route, delivery-interval or page-styling endpoint, and no corresponding controller. The admin SPA (`storify-admin`) has six child routes (dashboard, businesses, business detail, stores, users, transactions, coupons) and a sidebar with three sections (Navigation / Commerce / Settings) — no nav entry, view or table for any of these features.

This is a **write-side hole with live consequences**: the new public stack already reads or writes three of these datasets.

- `POST /api/v1/storefront/{store}/support` (new) creates rows in `support_messages` with `status: pending` and emails the *store's* support address — but there is no admin (or business) inbox anywhere in the new stack to read or answer them. Messages accumulate unanswerable.
- `GET /api/v1/home` (new home app) serves `Testimonial::where('status','active')` — existing rows display, but no new screen can add, edit, reorder or deactivate them.
- `GET /api/v1/storefront/{store}/delivery-routes` (new checkout) reads delivery routes **scoped to `store_id`** — and neither the admin platform-wide route CRUD nor the business store-route CRUD exists in the new stack, so the delivery-route selector has no configuration surface at all.

The supporting backend pieces that already survived the migration are the good news: `admin.support`, `admin.content`, `admin.delivery` and `admin.businesses` permissions are seeded (`database/seeders/SpatiePermissionSeeder.php`), `SupportMessageReplyMail` exists, and the early-pass action `ActivateSubscriptionWithEarlyPass` + `StoreActivationNotifier` + idempotent-usage migration are in place. Most screens are serialisation + form work, not new business logic.

| Area | Legacy features | exists | partial | missing |
|---|---|---|---|---|
| Testimonials | 3 | 0 | 0 | 3 |
| Support messages (admin inbox) | 4 | 0 | 0 | 4 |
| Early-access programme | 6 | 0 | 0 | 6 |
| Delivery routes (admin) | 4 | 0 | 0 | 4 |
| Delivery intervals | 1 | 0 | 0 | 1 |
| Page styling | 2 | 0 | 0 | 2 |
| **Total** | **20** | **0** | **0** | **20** |

**Boundary / de-duplication notes (read before planning):**

- **Early-access redemption (feature 13)** is business-audience and is audited in depth in `mgmt-subscription-kyc.md` §4.1. It is listed here only because it is the consumer that makes admin-issued passes meaningful; do not double-count the effort.
- **Business-side support inbox** (`Management\SupportMessageController`, `resources/views/management/support-messages/index.blade.php`) is audited in `mgmt-account-misc.md` §4.1–4.2. This report covers only the **admin** inbox. Note the same `support_messages` table serves both.
- **Delivery intervals (feature 18)** are also listed in `admin-dashboard-config.md` §4.1 (as an M/P1 item). This report reconciles that entry with the delivery-domain view and flags that legacy's delete guard is broken; keep one implementation, not two.
- **Page styling (features 19–20)** is also covered in `mgmt-storefront-styling.md` §9 with the same conclusion (inert in legacy rendering, no new-stack consumer).
- **Business store-scoped delivery routes** (`Management\StoreDeliveryRouteController` + `management/stores/delivery-routes.blade.php`, routes `management.stores.delivery-routes.*`) are store-settings scope, not this admin domain, but they are named in the gaps because checkout cannot be configured without them either.
- Legacy every-request audit: all `/office/*` routes run `AdminRouteActivityLogger`, which writes an `ActivityLog` row (`admin_route_accessed`) for every admin screen view and action. The new admin API has no equivalent middleware, so rebuilding these screens without it loses the audit trail (activity-log reads are `admin-dashboard-config.md` scope).

---

# A. Testimonials (public marketing content)

Legacy nav: **Content → Testimonials**. Routes: `Route::resource('testimonials', TestimonialController::class)->except(['create','show','edit'])` under `permission:admin.content` (`admin_dashboard.php:172`). One Blade file holds the entire CRUD (list + create modal + per-row edit modal + per-row delete modal), Alpine-powered via global `openModal()/closeModal()` helpers in `admin/layout.blade.php`.

## 1. Testimonials list (status + position ordering) — `missing` / `missing`

- **What the admin could do:** view all testimonials in one table ordered by `position` ascending then newest first; columns **Photo** (50×50 rounded thumbnail rendered from the stored image), **Name**, **Occupation**, **Message** (truncated to 100 chars), **Position**, **Status** badge (Active emerald / Inactive slate); per-row Edit and Delete icon buttons; success/error flash banners; empty state ("No testimonials found. Click 'Add Testimonial' to create one.") with a CTA. **No pagination, no search, no filters, no sorting controls, no bulk actions, no export or print** existed — the entire set rendered on one page.
- **Legacy route:** `GET /office/testimonials` (`admin.testimonials.index`) → `Admin\TestimonialController@index` · `resources/views/admin/testimonials/index.blade.php` · nav link `admin/components/sidebar.blade.php:155`
- **New API:** none for admin. Read-only public consumer exists: `GET /api/v1/home` (`Api\V1\Home\HomeController@index` → `testimonials()`), which serves active rows to the home app, ordered by position then newest, **capped at 6**.
- **New SPA:** none — no route/view in `storify-admin`; sidebar has no Content section.
- **Status:** api **missing**, spa **missing**.
- **Missing in the new stack:** the whole screen. Note the new consumer reads rows but nothing produces them; also the public API builds `photo_url` as `asset('storage/'.$photo)` while legacy stores a **base64 `data:` URI** in `testimonials.photo` (longText) — existing rows will render a broken URL on the new home app until the photo convention is aligned (see gaps).
- **Side effects:** `AdminRouteActivityLogger` audit row per view.
- **Effort:** S · **Priority:** P2 (marketing content, not daily ops; but the home app is already live and consuming it).

## 2. Create / edit testimonial form (photo upload, status, position) — `missing` / `missing`

- **What the admin could do:** open "Add Testimonial" modal — **Name** (required, ≤255), **Occupation** (required, ≤255), **Message** (required textarea, ≤1000), **Photo** (required on create; image, jpeg/png/jpg/gif, ≤2 MB; upload hint and inline errors), **Position** (optional integer ≥0, "lower numbers appear first", default 0), **Status** (required select active/inactive); submit posts multipart and the controller base64-encodes the file into `photo` as a `data:{mime};base64,…` string. Each row's **Edit** modal opens the same fields pre-filled with a current-photo preview; on edit **Photo is optional** ("Leave empty to keep current photo") and status/position are editable. Validation errors render inline; success redirects with a flash; failures are caught, logged (`admin.testimonial.create_failed` / `update_failed`) and flashed as an error with old input preserved.
- **Legacy route:** `POST /office/testimonials` (`admin.testimonials.store`), `PUT /office/testimonials/{testimonial}` (`admin.testimonials.update`) → `Admin\TestimonialController@{store,update}` · same `index.blade.php` modals
- **New API:** none. **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing in the new stack:** the whole form and validation rules. Decide the photo storage contract first — legacy base64-in-DB vs the new public API's `storage/` path expectation; the rebuild should pick storage-path uploads (or patch the public serializer) so images render in the home app.
- **Side effects:** `Log::info('admin.testimonial.created' | 'admin.testimonial.updated')` with admin id; global activity-log row.
- **Effort:** S · **Priority:** P2.

## 3. Delete testimonial (confirm modal) — `missing` / `missing`

- **What the admin could do:** per-row Delete opens a confirmation modal restating **Name — Occupation** and "This action cannot be undone."; confirming hard-deletes the row and flashes success; failures are caught and logged (`admin.testimonial.delete_failed`).
- **Legacy route:** `DELETE /office/testimonials/{testimonial}` (`admin.testimonials.destroy`) → `Admin\TestimonialController@destroy` · same view
- **New API:** none. **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** the endpoint + confirm modal. No soft delete/restore existed in legacy; none is required.
- **Side effects:** `Log::info('admin.testimonial.deleted')`; global activity-log row.
- **Effort:** S · **Priority:** P2.

---

# B. Support messages (admin inbox)

Legacy nav: **Content → Support Messages**. Routes under `permission:admin.support` (`admin_dashboard.php:218-222`): index / reply / destroy only — no show, no status-change, no assignment. The view renders all three modals inline per row. Messages originate from the public storefront support forms (`store_id` always set; `store?->name ?? '—'` in the table) and carry `status` ∈ {pending, replied, closed} — **`closed` is rendered by the UI but no code path in either admin or management ever sets it** (dead enum value; see gaps).

## 4. Support inbox list with pending/replied counters — `missing` / `missing`

- **What the admin could do:** one table of **every message across all stores**, ordered by `status` asc (pending before replied) then newest first, with header chips showing live counts: "**N Pending**" (amber) and "**N Replied**" (emerald). Columns: **ID** (#id), **Customer** (name bold, email and optional phone underneath), **Store** (name or "—"), **Message** (truncated to 80 chars), **Status** badge (Pending amber / Replied emerald / Closed slate), **Date** (`M d, Y`), **Actions** (view, reply — hidden once replied, delete). Pending rows are highlighted amber. Empty state "No support messages found." **No pagination, search, status/store filters, bulk actions, export or print** existed.
- **Legacy route:** `GET /office/support-messages` (`admin.support-messages.index`) → `Admin\SupportMessageController@index` (`SupportMessage::with('store')->orderBy('status')->orderBy('created_at','desc')->get()`) · `resources/views/admin/support-messages/index.blade.php` · sidebar `admin/components/sidebar.blade.php:150`
- **New API:** none. (Public write path exists: `POST /api/v1/storefront/{store}/support` → `Api\V1\Storefront\SupportController@store`, which inserts `status: pending` and queues `SupportMessageReceivedMail` to the customer + `AdminNewSupportMessageMail` to `store.support_email`.) Note the admin has no notification email equivalent — legacy's platform support form emailed the platform address, but storefront submissions only email the store.
- **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing in the new stack:** the whole inbox, the pending/replied counters, store context, and any admin-side notification that a message arrived. This is the highest-urgency gap in the domain: customer messages are being written to the DB right now with no reader.
- **Side effects:** global activity-log row per view.
- **Effort:** S-M (list + detail + reply + delete are four small surfaces sharing one endpoint family) · **Priority:** P1.

## 5. Support message detail view (full thread) — `missing` / `missing`

- **What the admin could do:** open a per-row **View** modal showing the full record: customer name, email, phone (or "N/A"), store (or "—"), status badge, "Date Submitted" (`M d, Y at h:i A`), the full message body in a quoted panel, and — when present — the **reply block labelled by replier type** (`Reply (Admin)` / `Reply (Business)` from `replied_by_type`) with the reply text and "Replied on: {replied_at}". A "Reply to Customer" footer button (hidden when already replied) closes the view and opens the reply modal. (Data was preloaded by the index query; there was no `show` route.)
- **Legacy route:** no dedicated route — data comes from `GET /office/support-messages` (`admin.support-messages.index`) → `Admin\SupportMessageController@index` · same view
- **New API:** none. **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** a detail payload/endpoint (the list must expose message body, reply, replied_by_type/id, replied_at) and the detail UI.
- **Side effects:** none beyond the activity-log row.
- **Effort:** S · **Priority:** P1.

## 6. Reply to a support message (emails the customer) — `missing` / `missing`

- **What the admin could do:** in the reply modal, which re-shows the customer's message, type a **required reply (≤2000 chars)** and send. Server sets `reply`, `status = 'replied'`, `replied_by_type = 'admin'`, `replied_by_id = auth()->id()`, `replied_at = now()`, writes an audit log, then queues **`SupportMessageReplyMail` to the customer's email** (failure to queue is caught and logged, the reply is still saved) and redirects with "Reply sent successfully to the customer."; errors redirect back with an error flash. The UI hides the reply button once `status === 'replied'`, but the server accepts a re-reply (overwrite) — worth tightening to an explicit "already replied" guard or an intentional re-open flow. There was no canned-response library, no attachment, no internal note.
- **Legacy route:** `POST /office/support-messages/{supportMessage}/reply` (`admin.support-messages.reply`) → `Admin\SupportMessageController@reply` · reply modal in the same view
- **New API:** none. **Supporting pieces already exist:** `app/Mail/SupportMessageReplyMail.php` (ShouldQueue) and the same status/columns on the model.
- **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** endpoint + modal, the replied-state semantics, and reconciliation with the business-side reply (both admin and business can answer the same message; last writer wins in legacy — decide the intended ownership).
- **Side effects:** status transition pending → replied; reply email queued to customer; `Log::info('support.message.replied' | 'reply_email_queued' | 'reply_failed')`; activity-log row.
- **Effort:** S-M · **Priority:** P1.

## 7. Delete support message — `missing` / `missing`

- **What the admin could do:** per-row **Delete** opens a confirmation modal recapping From (name), Store, and the message (100 chars) with "This action cannot be undone."; confirming hard-deletes; failure flashes an error. No soft delete/restore, no bulk delete.
- **Legacy route:** `DELETE /office/support-messages/{supportMessage}` (`admin.support-messages.destroy`) → `Admin\SupportMessageController@destroy` · same view
- **New API:** none. **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** endpoint + confirm modal.
- **Side effects:** `Log::info('support.message.deleted')`; activity-log row.
- **Effort:** S · **Priority:** P2 (deleting evidence is rarer than replying).

---

# C. Early-access programme (admin issuance + tracking)

Legacy nav: **Businesses → Access Codes**. Routes under `permission:admin.businesses` (`admin_dashboard.php:108-111`): `Route::resource('early-access', AdminEarlyPassController::class)->parameters(['early-access' => 'earlyPass'])->except(['create','edit'])` plus `POST early-access/{earlyPass}/toggle-status`. Route key is the **code** (`EarlyPass::getRouteKeyName() = 'code'`), so URLs are `/office/early-access/{CODE}`. The model auto-deactivates a pass when usage reaches `max_uses` (`markAsUsed()`), so the "toggle" and "delete" guards below interact with redemption.

## 8. Early-access pass list — `missing` / `missing`

- **What the admin could do:** table of passes (newest first, **paginated 20/page** with links): **Code** (bold, uppercase), **Description** (or "-"), **Status** badge (Active emerald / Inactive red), **Usage Count** rendered `used / max` with "∞" when `max_uses` is null and a "View Details" link, **Created At** (`d M Y H:i`), and a per-row kebab menu with **View Usages**, **Edit**, **Activate/Deactivate** (label flips with state) and **Delete**. Empty state "No early access codes found" with "Create First Code" CTA. No search, filters, bulk actions or export.
- **Legacy route:** `GET /office/early-access` (`admin.early-access.index`) → `Admin\AdminEarlyPassController@index` (`EarlyPass::withCount('usages')->latest()->paginate(20)`) · `resources/views/admin/Earlyaccess/index.blade.php` · sidebar `admin/components/sidebar.blade.php:46`
- **New API:** none — no `early-access` route in `routes/api/v1/admin.php`; no `Api\V1\Admin\EarlyPassController`.
- **New SPA:** none — no route/view/nav in `storify-admin`.
- **Status:** api **missing**, spa **missing**.
- **Missing in the new stack:** the whole screen. Console hint: passes auto-deactivate at the limit; the list must surface `usage_count` vs `max_uses` for the operator to know when to issue more.
- **Side effects:** global activity-log row.
- **Effort:** S · **Priority:** P1 if the early-access programme is live (it gates subscription activation); P2 if retired.

## 9. Create / edit pass (code, description, max uses) — `missing` / `missing`

- **What the admin could do:** **Create** modal — **Code** (required, unique in `early_passes`, 3–50 chars, "Codes are handled in uppercase", stored `strtoupper()`d; e.g. `EARLYBIRD2025`), **Max Uses** (optional integer ≥1; blank = unlimited; hint "Code passes will auto-deactivate when limit is reached."), **Description** (optional textarea ≤255, "Internal note about this code…"); a new pass is created `is_active = true`. **Edit** modal (from the row menu) shows **Code read-only/disabled**, and allows editing **Max Uses** and **Description** only — the code is immutable after creation.
- **Legacy route:** `POST /office/early-access` (`admin.early-access.store`), `PUT /office/early-access/{code}` (`admin.early-access.update`) → `Admin\AdminEarlyPassController@{store,update}` · same view modals
- **New API:** none. **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** create/update endpoints with the code/description/max_uses validation and uppercase normalisation; the code-immutability rule on edit.
- **Side effects:** created active; global activity-log row. No explicit Log calls in this controller.
- **Effort:** S · **Priority:** P1 (with feature 8).

## 10. Activate / deactivate pass (toggle-status) — `missing` / `missing`

- **What the admin could do:** one click in the row menu flips `is_active`; the flash reads "Pass has been activated." / "Pass has been deactivated." Deactivation is the sanctioned alternative to deleting a used pass, and immediately blocks further redemption (`isAvailable()` checks `is_active` + usage count).
- **Legacy route:** `POST /office/early-access/{earlyPass}/toggle-status` (`admin.early-access.toggle-status`) → `Admin\AdminEarlyPassController@toggleStatus` · same view
- **New API:** none. **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** the endpoint + optimistic menu UI.
- **Side effects:** status transition active ⇄ inactive, which gates redemption and the `is_available` calculation.
- **Effort:** S · **Priority:** P1 (with feature 8).

## 11. Delete pass with used-guard — `missing` / `missing`

- **What the admin could do:** row-menu **Delete** opens a confirm modal ("Are you sure you want to delete this code? This action cannot be undone."). The server refuses deletion when the pass has any usages, returning back with the error "**Cannot delete a used pass. Deactivate it instead.**"; unused passes are hard-deleted. (Legacy's modal does not surface this rule up front — a rebuilt UI should disable Delete for used passes rather than round-trip an error.)
- **Legacy route:** `DELETE /office/early-access/{code}` (`admin.early-access.destroy`) → `Admin\AdminEarlyPassController@destroy` · same view
- **New API:** none. **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** endpoint with the `usages()->exists()` guard (preserve the cascade considerations for `early_pass_usages.early_pass_id` — FK is `cascadeOnDelete`, so the guard is the only protection for audit history).
- **Side effects:** hard delete when unused; refused otherwise.
- **Effort:** S · **Priority:** P2.

## 12. Pass detail + usage history — `missing` / `missing`

- **What the admin could do:** open a pass detail page (`Early Access / {CODE}` breadcrumb) showing, in one summary card: **Status** badge, **Usage Count**, **Max Uses** (∞), **Created At**, **Description**; below it a **Usage History** table — one row per redemption: **Business** (avatar initial + linked name → admin business detail, email underneath; "Unknown Business" fallback), **Store Used On** (linked store name + `store_id` "select-all" code, or "-"), **Used At** (`d M Y, H:i`). Empty state "No usages recorded yet." This is the only tracking view for who consumed a code.
- **Legacy route:** `GET /office/early-access/{earlyPass}` (`admin.early-access.show`) → `Admin\AdminEarlyPassController@show` (`$earlyPass->load(['usages.user','usages.store'])`) · `resources/views/admin/Earlyaccess/show.blade.php`
- **New API:** none. (The linked targets exist: `GET /api/v1/admin/businesses/{business}` and `GET /api/v1/admin/stores/{store}`.)
- **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** detail + usages endpoint (business name/email, store name/store_id, used_at) and the screen; store the **user_id** in `early_pass_usages` — verified present (`user_id`, `store_id`, `used_at`, idempotent per migration `2026_09_01_000006_make_early_pass_usage_idempotent.php`).
- **Side effects:** global activity-log row.
- **Effort:** S · **Priority:** P1 (without it the programme cannot be measured).

## 13. Redeem early-access code (business consumer of passes) — `missing` / `missing` · *business audience*

- **What the business user could do:** POST a code from the subscription/plans flow and receive JSON. Server: look up trimmed `early_passes.code`; take the active default plan; invalid code / no plan / no business / pass inactive or over limit / already used by this user / already-subscribed each return a specific message; on success `ActivateSubscriptionWithEarlyPass` creates a 1-year active subscription with `payment_skipped` metadata, clears trial, activates the user and pending/suspended stores, writes an `EarlyPassUsage`, deactivates the pass at max uses, and queues activation emails (`StoreActivationNotifier`).
- **Legacy route:** `POST /management/subscription/check-early-pass` (`management.subscription.check-early-pass`) → `Management\EarlyPassController@apply` · views: **none found** (orphaned endpoint; only middleware exempt lists and `tests/Feature/Management/EarlyPassTest.php` reference it)
- **New API:** none — `routes/api/v1/management.php` has no subscription endpoints at all; the action `App\Actions\Subscriptions\ActivateSubscriptionWithEarlyPass` and `App\Services\StoreActivationNotifier` **do exist** in the new codebase, as do the model, migrations and idempotency test scaffolding.
- **New SPA:** none in `storify-management` (no plans/subscription route exists yet).
- **Status:** api **missing**, spa **missing**.
- **Missing:** the management API endpoint + the business UI entry point. Cross-reference: full spec and effort in `mgmt-subscription-kyc.md` §4.1; listed here because admin issuance (features 8–12) cannot be end-to-end verified until redemption ships.
- **Side effects:** subscription activated (no payment), store activated, usage recorded, pass auto-deactivated at limit, activation email/notification sent.
- **Effort:** M (mostly dependent on the subscription API landing first) · **Priority:** P1 if the programme is live.

---

# D. Delivery routes (platform-wide, checkout configuration)

Legacy nav: **Delivery → Delivery Routes**. Routes under `permission:admin.delivery` (`admin_dashboard.php:188-193`): `Route::resource('delivery-routes')->except(['show','create'])` + `POST delivery-routes/{deliveryRoute}/toggle`. Admin-created rows have `store_id = NULL` — legacy treats them as platform-wide defaults, and the legacy storefront product page surfaced **all** active routes (global + store) grouped by state (`Home\ProductController`, line ~309). Fee is stored in **kobo** (`(int) $fee * 100` on write, `/100` formatted `₦` on display); `delivery_days` is 1–60. Per-store routes are a separate business surface (`Management\StoreDeliveryRouteController` + `resources/views/management/stores/delivery-routes.blade.php`) — out of this admin domain but part of the same checkout story (see gaps).

## 14. Delivery route list — `missing` / `missing`

- **What the admin could do:** table of routes ordered by country → state → area, **paginated 20/page**: **ID**, **Country**, **State**, **Area**, **Fee** (`₦123.00` formatted from kobo, mono), **Delivery Days**, **Status** badge (Active/Inactive), **Updated** (`diffForHumans`), kebab action menu (Edit / Enable-Disable / Delete). Empty state "No routes". No search, filters, bulk actions, map view or export.
- **Legacy route:** `GET /office/delivery-routes` (`admin.delivery-routes.index`) → `Admin\DeliveryRouteController@index` · `resources/views/admin/delivery_routes/index.blade.php` · sidebar `admin/components/sidebar.blade.php:202`
- **New API:** none. Read consumer exists: `GET /api/v1/storefront/{store}/delivery-routes` (`Api\V1\Storefront\CatalogController@deliveryRoutes`), which returns **only `where('store_id', $store->id)`** active routes — so platform-wide (admin) routes are not served at all in the new stack.
- **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing in the new stack:** the whole screen; plus a decision on whether platform-wide routes survive the rewrite (see gaps — legacy checkout `Checkout\PageController` and `Cart\CartController` validate route ids against `store_id`, while the legacy product page showed global routes too; the new stack is store-only end to end).
- **Side effects:** global activity-log row.
- **Effort:** S · **Priority:** P1 — checkout shipping fees are configured here.

## 15. Create / edit delivery route — `missing` / `missing`

- **What the admin could do:** **Add Route** modal and per-row **Edit** modal with the same fields: **Country** (required, ≤100, defaults to "Nigeria"), **State** (required, ≤100), **Area** (required, ≤150), **Fee (NGN)** (required integer ≥0, converted ×100 to kobo on save), **Delivery Days** (required integer 1–60, default 3), **Active** checkbox. `DeliveryRouteRequest` enforces the rules; create/update each write an audit log (`delivery_route_created` / `delivery_route_updated` with user id + route id) and redirect with a flash. No bulk import, no copy/duplicate, no state/area picklist (free text only — a rebuilt UI should consider a picklist to keep area names consistent).
- **Legacy route:** `POST /office/delivery-routes` (`admin.delivery-routes.store`), `PUT /office/delivery-routes/{deliveryRoute}` (`admin.delivery-routes.update`); `GET /office/delivery-routes/{deliveryRoute}/edit` (`admin.delivery-routes.edit`) re-renders the index with the modal data · `App\Http\Requests\Admin\DeliveryRouteRequest`
- **New API:** none. **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** both form endpoints with kobo conversion and validation; careful serialisation contract — the new storefront checkout expects `fee` in **kobo** (integer cents), matching storage; the admin UI should keep showing NGN.
- **Side effects:** `Log::info('delivery_route_created' | 'delivery_route_updated')` with `user_id`/`route_id`; global activity-log row.
- **Effort:** S · **Priority:** P1.

## 16. Enable / disable delivery route — `missing` / `missing`

- **What the admin could do:** kebab-menu action opens a confirm modal ("Disable this route?" / "Enable this route?") and flips `active`; disabled routes disappear from checkout/product delivery options (consumers filter `active = 1`). Flash "Delivery route status updated".
- **Legacy route:** `POST /office/delivery-routes/{deliveryRoute}/toggle` (`admin.delivery-routes.toggle`) → `Admin\DeliveryRouteController@toggle` · same view
- **New API:** none. **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** the endpoint + optimistic menu state.
- **Side effects:** `Log::info('delivery_route_toggled', [...,'active' => …])`; global activity-log row.
- **Effort:** S · **Priority:** P1.

## 17. Delete delivery route — `missing` / `missing`

- **What the admin could do:** kebab **Delete** opens a confirmation modal ("Are you sure you want to delete this delivery route?"); confirming hard-deletes and flashes "Delivery route deleted". No usage guard existed — deleting a route customers selected previously leaves historical orders with a dangling `delivery_route_id` (order rows keep the id; the new stack's `delivery_route_id` on orders/carts is nullable/foreign — decide whether delete should be blocked when referenced, or soft-deleted).
- **Legacy route:** `DELETE /office/delivery-routes/{deliveryRoute}` (`admin.delivery-routes.destroy`) → `Admin\DeliveryRouteController@destroy` · same view
- **New API:** none. **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** endpoint + confirm modal; a reference check is a recommended improvement over legacy.
- **Side effects:** `Log::info('delivery_route_deleted')`; global activity-log row.
- **Effort:** S · **Priority:** P2.

---

# E. Delivery intervals (settings screen)

Legacy nav: **Delivery → Delivery Intervals** (`permission:admin.delivery`, `admin_dashboard.php:191-192`), although the view lives under `settings/`. Also inventoried in `admin-dashboard-config.md` §4.1. **One feature entry** — it is a single small screen with create form + list + modals.

## 18. Delivery intervals manager (create, edit, toggle, delete) — `missing` / `missing`

- **What the admin could do:** a two-column screen — left "**Add New Interval**" inline form (**Name** required, e.g. "Weekly"; **Days Count** required integer ≥1, "number of days between deliveries"; **Sort Order** optional ≥0, default 0) posting to a `Create Interval` button; right "**Existing Intervals**" table (**Name**, **Days** "N days", **Order**, **Status** pill, actions) with per-row **Edit** modal (name/days/sort; slug regenerated on rename), **Activate/Deactivate** toggle, and **Delete** confirm modal. Name → `Str::slug` uniqueness is enforced on create and update (duplicate → error flash "An interval with this name already exists."); delete is intended to be blocked when the interval is used by "family pack orders".
- **Legacy route:** `GET /office/delivery-intervals` (`admin.delivery-intervals.index`), `POST` (`store`), `PUT /{id}` (`update`), `POST /{id}/toggle` (`toggle`), `DELETE /{id}` (`destroy`) → `Admin\DeliveryIntervalController` · `resources/views/admin/settings/delivery_intervals.blade.php` · sidebar `admin/components/sidebar.blade.php:207`
- **New API:** none — no interval route/controller in `routes/api/v1/**`; `DeliveryInterval` model + migration (`2025_11_30_103459`) exist but are referenced by nothing.
- **New SPA:** none — no reference to intervals in `storify-admin` or `storify-management`.
- **Status:** api **missing**, spa **missing**.
- **Missing:** the entire CRUD screen. **Obsolescence judgement (differs from `admin-dashboard-config.md` §4.1):** the delete guard calls `$interval->familyPackOrders()->exists()`, but the `DeliveryInterval` model (30 lines) defines **no such relation** — in legacy, deleting any interval throws `BadMethodCallException` (500), so the guard is fictional and the screen is effectively write-only/orphaned. No legacy controller, view, checkout or order path consumes `delivery_intervals` (grepped app/ + database/), and the new stack has no consumer either. Recommendation: **confirm the recurring-delivery/family-pack feature is still on the roadmap before rebuilding**; if it is not, retire this screen rather than port it. If it is wanted, build it as part of that feature with a real usage guard.
- **Side effects:** none (no logs; global activity-log row).
- **Effort:** S (screen only; M if the family-pack consumer is built too — matching the sibling audit) · **Priority:** P2 (obsolete-or-revive decision; not required for checkout as it stands).

---

# F. Page styling (platform storefront templates)

Legacy nav: **Content → Page Styling** (`permission:admin.content`, `admin_dashboard.php:157`). Also covered in `mgmt-storefront-styling.md` §9 with the same conclusion. Create/edit are full pages (not modals); delete is a confirm modal on the list.

## 19. Page styling list with status, colour preview and delete — `missing` / `missing`

- **What the admin could do:** table of stylings ordered by `page_label`: **#**, **Page Label** (bold), **Page Name** (`<code>` identifier), **Background Color** (swatch + hex `<code>`, or "Not set"), **Status** badge; per-row **Edit** link and **Delete** confirm modal ("Are you sure you want to delete this styling?"). Empty state is an info banner with a "Create one now" link. No pagination, search, filters or bulk actions.
- **Legacy route:** `GET /office/styling` (`admin.styling.index`), `DELETE /office/styling/{styling}` (`admin.styling.destroy`) → `Admin\PageStylingController@{index,destroy}` · `resources/views/admin/styling/index.blade.php` · sidebar `admin/components/sidebar.blade.php:227`
- **New API:** none. **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** list + delete endpoints/UI. **Judgement:** the only legacy consumer was `Home\ProductController` loading `PageStyling::getPageStyling('product_details')` into `storefront.pages.product-details` / `service-details` — but **no Blade reads `$pageStyling`** (grepped `resources/views/**`: zero usages), so the feature is inert in legacy rendering, and the new storefront SPA has no styling hook at all. Rebuilding the admin CRUD alone produces a screen that changes nothing visible. Build only together with a render-side hook, or defer both.
- **Side effects:** model `deleted` hook clears the `page_styling_{page_name}` cache (600 s TTL); global activity-log row.
- **Effort:** S · **Priority:** P2 (build only with the renderer).

## 20. Create / edit page styling (colour picker + custom CSS) — `missing` / `missing`

- **What the admin could do:** **Create Page Styling** page and per-row **Edit** page with the same form: **Page Label** (required ≤255, human name, e.g. "Product Details Page"), **Page Name (Identifier)** (required ≤255, unique in `page_stylings` — validated on create and on update excluding self; placeholder guidance "lowercase with underscores, e.g. product_details, home, checkout"), **Background Color** (optional ≤7 chars; a `<input type="color">` picker synchronised both ways with a hex text input), **Custom CSS** (optional textarea, mono, "advanced users only"), **Active** checkbox (default checked). Inline validation errors, Cancel/back links, success flashes. Model busts the per-page cache on save.
- **Legacy route:** `GET /office/styling/create` (`admin.styling.create`), `POST /office/styling` (`admin.styling.store`), `GET /office/styling/{styling}/edit` (`admin.styling.edit`), `PUT /office/styling/{styling}` (`admin.styling.update`) → `Admin\PageStylingController@{create,store,edit,update}` · `resources/views/admin/styling/create.blade.php`, `edit.blade.php`
- **New API:** none. **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** the whole form; same renderer-consumer caveat as feature 19.
- **Side effects:** cache forget on save; global activity-log row.
- **Effort:** S · **Priority:** P2.

---

## Gaps worth calling out

1. **Data is flowing into screens that do not exist.** Storefront support submissions (`POST /api/v1/storefront/{store}/support`) already insert `pending` rows; the new home API already serves testimonials; the new storefront already serves store delivery routes. Admin can neither read the first, curate the second, nor configure the third. The support inbox is the sharpest edge — customers get an acknowledgment email while nobody can reply.
2. **The admin SPA is six screens deep, but the permissions are ready.** `admin.support`, `admin.content`, `admin.delivery` are seeded, and the admin API's `token.audience:admin` + `team.context` + `permission:*` pattern is established — these features are additive routes/controllers/views, not architecture.
3. **Testimonial photo convention mismatch (live bug).** Legacy admin stores base64 `data:` URIs in `testimonials.photo` (longText); the new home API serialises `asset('storage/'.$photo)` → broken image URLs for every existing row. Decide the contract (prefer real storage uploads) when building features 1–2, and patch the serializer for existing rows. Also note the new public endpoint caps testimonials at **6** while legacy admin could manage unlimited — set the cap deliberately.
4. **Support `closed` status is a dead enum value.** The schema allows `pending|replied|closed` and the admin UI styles Closed, but no admin or management code path sets it. Either add an explicit "Close" action in the rebuilt inbox (recommended — replying isn't always right) or drop it from the UI.
5. **Admin re-reply overwrite.** The legacy admin reply endpoint accepts a second reply on an already-replied message (UI hides the button only). The rebuild should decide: block, or re-open. Same question across admin vs business ownership of a message thread — legacy lets both reply, last writer wins.
6. **Early passes are backend-complete but UI-dead on both sides.** The admin CRUD (features 8–12) and the business redemption (feature 13) are both missing; the redemption UI was already orphaned in legacy (no Blade ever rendered a code form). Until the subscription API/plans screens land (`mgmt-subscription-kyc.md`), issuing codes in the new stack would produce passes nobody can redeem — sequence accordingly.
7. **Delivery routes need a scope decision before building.** Legacy admin routes were platform-wide (`store_id = NULL`) and the legacy storefront product page showed all active routes; the new storefront endpoint filters strictly by `store_id`, and store-scoped route management doesn't exist in the new management SPA either. Build all three consistently (admin global defaults + business store routes + storefront/checkout resolution), or checkout delivery selection stays empty for stores that relied on global routes.
8. **Delivery intervals are probably obsolete.** No consumer in legacy or new; the legacy delete guard references a `familyPackOrders()` relation that does not exist on the model (delete would 500). Confirm the family-pack/recurring-delivery roadmap before spending effort — otherwise skip this screen and mark it retired.
9. **Page styling has no renderer.** Same pattern: building the admin CRUD without wiring `background_color`/`custom_css` into the new storefront yields a no-op screen. Build the render hook first (or drop the feature consciously).
10. **Admin audit trail.** Legacy logged every `/office` request to `ActivityLog` via `AdminRouteActivityLogger`; the new admin API has no such middleware, so these rebuilt screens (and all others) will be invisible to the activity-log review screen unless the equivalent is added — ideally with subject/old-new values for destructive actions (testimonial delete, pass delete, route delete, reply).
11. **No export/print/bulk existed in this domain.** For parity, nothing to rebuild; if the operator wants bulk deactivation of passes or a CSV of early-access usages, that is new scope (a CSV of feature 12 usages is a cheap, high-value add).
12. **Cross-report de-duplication:** early-access redemption → `mgmt-subscription-kyc.md` §4.1; business support inbox → `mgmt-account-misc.md` §4.1–4.2; delivery intervals → `admin-dashboard-config.md` §4.1; page styling → `mgmt-storefront-styling.md` §9; order/checkout consumption of delivery routes → `admin-orders-finance.md` and the storefront audits. Count each once in the build plan.
