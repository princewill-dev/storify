# Verification pass — admin-content-support (audience: admin)

**Verifier method:** independently re-read the full legacy surface in scope — all six controllers in full (`Admin\{Testimonial,SupportMessage,AdminEarlyPass,DeliveryRoute,DeliveryInterval,PageStyling}Controller`), `App\Http\Requests\Admin\DeliveryRouteRequest`, models `{Testimonial,SupportMessage,EarlyPass,EarlyPassUsage,DeliveryRoute,DeliveryInterval,PageStyling}`, the routing in `routes/v1/admin_dashboard.php` (every route registration), `routes/v1/management.php` (early-pass + store delivery-route boundaries), the `AdminRouteActivityLogger` middleware wiring, `Admin` middleware, `SpatiePermissionSeeder`, and a file-by-file pass over every in-scope Blade view (`testimonials/index`, `support-messages/index`, `Earlyaccess/{index,show}`, `delivery_routes/index`, `styling/{index,create,edit}`, `settings/delivery_intervals`, plus `components/sidebar` and the admin layout/header/footer for nav and shell references). New stack: the complete `routes/api/v1/admin.php` (all 23 registrations), `routes/api/v1/{home,storefront,management}.php`, every `Api/V1/Admin/*` controller, `Api/V1/{Home,Storefront}/*` consumers, mail classes, migrations and seeders for the domain tables, and the admin SPA (`src/router/index.ts`, all `src/views/**`, `src/api/endpoints.ts`, `src/layouts/AdminLayout.vue`).

**Verdict:** the audit is substantively complete — every in-scope view file (9 including `settings/delivery_intervals`), every controller method and every in-domain route registration maps to one of the 20 feature entries, and all 40 status pairs were re-checked by reading the new routes/controllers/SPA: no `exists` claim exists, and **no `missing` claim hides an existing endpoint or screen** (case-insensitive grep of `routes/api/` plus the full `Api/V1` controller tree and the admin SPA router/views/endpoints confirm there is no testimonial, support, early-access, delivery-route, delivery-interval or styling endpoint/UI anywhere in the new stack). The findings below are claim-level errors and missed new-stack facts that would misdirect the rebuild; no genuinely omitted legacy feature was found.

---

## Missed features

**None found.** Near-misses checked and dismissed:

- Every interactive element in all 9 in-scope Blade files is accounted for by features 1–20 (modals, kebab menus, counters, pagination, empty states, validation hints).
- `admin/components/{header,footer}.blade.php` and `admin/layout.blade.php` are shared chrome; they contain no domain counters, badges or links (the only sidebar badges were users/KYC/orders/customers, all other domains).
- No cross-domain admin controller reads or writes these entities (grepped all of `Admin/*`: only `CustomerController` joins `delivery_routes` to render saved addresses — a read-side consumer already tracked by `admin-users-admins`/`admin-orders-finance`).
- `AdminEarlyPassController@show` and the store-side early-pass redemption were already covered (features 12 and 13).

---

## Corrections

### C1 — §14 / gap 7: the new stack's own delivery-route seed data is global-only, and the store_id migration wiped legacy global routes

- **Feature:** Delivery route list · Create/edit (Feature 14 / Gap 7)
- **Field:** Missing in the new stack (was: *"…the new stack is store-only end to end."*)
- **Should be:** the new stack's **reader** is store-only, but its **seeder is global-only** — `DatabaseSeeder` calls `DeliveryRouteSeeder` (`database/seeders/DatabaseSeeder.php:36`), which `firstOrCreate`s five platform routes (Lekki/Ikeja/Wuse/Port Harcourt/Ibadan, fee ×100 kobo) with **no `store_id`** → NULL rows, while `Api\V1\Storefront\CatalogController@deliveryRoutes` filters `where('store_id', $store->id)`. A freshly seeded new-stack DB therefore shows **zero delivery options at checkout**, with no management UI able to create store-scoped replacements. Also relevant: `2026_01_02_153903_add_store_id_to_delivery_routes_table.php` **deletes all pre-existing routes** before adding the nullable `store_id` column — i.e. the migration author already decided legacy platform-wide routes do not survive. The scope decision in gap 7 is therefore not optional; it is load-bearing for a fresh install.

### C2 — Executive summary: new-stack counts are wrong

- **Feature:** Executive summary
- **Field:** counts — was: *"The new admin API (`routes/api/v1/admin.php`, 30 route registrations)"* and *"six child routes (dashboard, businesses, business detail, stores, users, transactions, coupons)"* / gap 2 *"six screens deep"*.
- **Should be:** the admin API file contains **23** route registrations (dashboard 1 + search 1 + businesses 4 + stores 2 + users 7 + transactions 2 + coupons 6), and the admin SPA router has **seven** child routes — the audit lists seven itself; only the numeral is wrong (six list screens plus `businesses/:businessCode` detail). The *contents* of both lists are accurate.

### C3 — §14, §18, §19: legacy nav sections are mislabelled

- **Feature:** Delivery routes (14), Delivery intervals (18), Page styling (19)
- **Field:** legacy nav — was: *"Delivery → Delivery Routes"*, *"Delivery → Delivery Intervals"*, *"Content → Page Styling"*.
- **Should be:** the admin sidebar has four sections (Navigation / Commerce / Content / Settings) and **no "Delivery" section**. All three links live in **Settings**: Delivery Routes (`sidebar.blade.php:202`), Delivery Intervals (`:207`), Page Styling (`:227`) — matching the line refs already cited. The **Content** section holds only Support Messages, Testimonials and Features. Permissions are unchanged (`admin.delivery` / `admin.content`).

### C4 — Boundary note / gap 10: not every `/office/*` route ran `AdminRouteActivityLogger`

- **Feature:** Boundary note ("Legacy every-request audit") / Gap 10
- **Field:** side effects claim — was: *"all `/office/*` routes run `AdminRouteActivityLogger`, which writes an `ActivityLog` row … for every admin screen view and action."*
- **Should be:** `/office/dashboard`, `/office/executive` and `GET/POST /office/settings` are registered **outside** the middleware group (`admin_dashboard.php:44-48`; the logged `Route::prefix('office')->middleware([AdminRouteActivityLogger::class])` group starts at `:50`) — legacy never logged dashboard visits or settings saves (the sibling `admin-dashboard-config.md` gap 2 documents this same blind spot). All **six in-scope features' routes are inside the logged group** (lines 108-227), so gap 10's recommendation to add equivalent middleware before rebuilding stands unchanged; only the "all routes" generalisation is false.

### C5 — §18: `DeliveryInterval` is referenced by an (uninvoked) seeder as well as its controller

- **Feature:** Delivery intervals manager (Feature 18)
- **Field:** New API — was: *"`DeliveryInterval` model + migration (`2025_11_30_103459`) exist but are referenced by nothing."*
- **Should be:** additionally referenced by `database/seeders/DeliveryIntervalSeeder.php` (10 interval rows, e.g. Weekly … Annually) — but that seeder is **not called by `DatabaseSeeder`**, so no runtime path consumes the table. The retire-or-revive judgement (delete guard 500s; no consumer in legacy or new) stands.

---

## Smaller notes (no audit text change demanded)

- The legacy live store-support form (`Storefront\StoreSupportController@store`, subdomain `/support`, writes `pending` rows) already routed `AdminNewSupportMessageMail` to `$store->support_email ?? mail.from` — matching the new API; only the older dev-only `Home\SupportController@store` (`local.support.*`) used `mail.from` alone. Feature 4's "storefront submissions only email the store" is exact for the live path; no parity work implied.
- Feature 2: on the create modal the Status select has no pre-selected option (both `old()` checks false) although `required` — cosmetic, not a validation gap.
- Feature 10: legacy's "Activate" on an exhausted pass flips `is_active` but leaves `isAvailable()` false because usage ≥ `max_uses` — worth encoding deliberately in the rebuilt toggle.
- Feature 15: the edit modal pre-fills fee as `(int) ($fee/100)`, so kobo-remainder fees truncate in the form; the audit's "keep showing NGN" guidance should note the round-trip.
- The early-access edit modal injects `description`/`max_uses` into `onclick` JS strings unescaped — legacy quirk to fix rather than port (quotes/newlines in a description break the modal).
