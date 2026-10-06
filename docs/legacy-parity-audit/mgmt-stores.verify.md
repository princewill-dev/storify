# Verification pass — mgmt-stores (audience: business)

**Verifier method:** independently re-read the full legacy store surface — `routes/v1/management.php` (store block lines 91–150, POS 494–512, `stores/{store}/products|orders` closures 205/250), `routes/v1/admin_dashboard.php` (store resource + suspend/activate), all seven in-scope controllers in full (`Management\{Store,StoreTab,StoreLifecycle,StoreSettings,StoreDashboard,StoreDeliveryRoute,Location}Controller` plus the store-adjacent `StoreBankController` and `StorefrontController`), `App\Actions\Stores\CreateStore`, `App\Http\Requests\Management\CreateStoreRequest`, `App\Services\StoreAnalyticsService`, `App\Mail\{StoreSuspended,StoreReactivated}`, all 17 store Blade views and 4 location views (enumerated file-by-file), and every `route()` reference in those views. New stack: `routes/api/v1/management.php`, `routes/api/v1/admin.php`, `routes/api/v1/pos.php`, `Api\V1\Management\{Store,Dashboard,Product,Order,Transaction,Customer,Staff}Controller`, `storify-management` (`router/index.ts`, `endpoints.ts`, `StoresView/OrdersView/ProductsView/StaffView/TransactionsView/DashboardView`) and `storify-admin` (`endpoints.ts`, `StoresView.vue`).

**Coverage verdict:** the audit is substantively complete. Every in-scope view file (21/21), controller method and store-scoped route maps to a feature entry; all 26 feature statuses were spot-checked against the new stack, and **no "exists" claim is stubbed and no "missing" claim hides a management endpoint** — except one understated API (§2.2 below, corrected to `partial`). The old "landmine" checks (no POST/PUT stores, no lifecycle, no settings, no delivery-route or location API, `pos_enabled` read-only) all confirmed by grep. Findings below are one cross-domain route omission and four concrete corrections; nothing else was invented.

---

## Missed feature (cross-domain — dedupe with mgmt-finance §3.4)

### A. Per-store bank accounts CRUD (`StoreBankController`, `stores.banks.*` routes) — `missing`

- **What the user could do (legacy):** Add bank accounts bound to a store — `bank_name`, `bank_code`, `account_number`, `account_name`, optional `is_primary` (first bank is forced primary, `is_verified` set true), update details, **Set Primary** (clears the others), delete with the hard guard that the primary account cannot be deleted ("Set another account as primary first"). All mutations are heavily logged. Caveat: no current Blade form posts to these routes (the only view that once did, `payment-methods.blade.php`, references removed route names), so in the legacy UI the card was read-only; the endpoints were live but UI-unreachable.
- **Legacy route:** `POST /management/stores/{store}/banks`; `PUT /management/stores/{store}/banks/{bank}`; `PATCH /management/stores/{store}/banks/{bank}/primary`; `DELETE /management/stores/{store}/banks/{bank}` — all under `permission:stores settings` (`routes/v1/management.php` 143–146)
- **Legacy controller:** `Management\StoreBankController@store/update/setPrimary/destroy`
- **Legacy views:** `resources/views/management/stores/settings.blade.php` (read-only Bank Accounts card); `resources/views/management/stores/payment-methods.blade.php` (dead onboarding view)
- **Key UI:** Bank Accounts card only; no live form
- **Side effects:** `store_banks` rows; auto-primary on first insert; primary-delete guard
- **API status:** `missing` — **New API:** none
- **SPA status:** `missing` — **New SPA:** none
- **Effort:** M — **Priority:** P1 (bank accounts decide payout routing; mgmt-finance already rates it P1)
- **Dedupe:** `mgmt-finance.md` §3.4 inventories this same controller/routes (its declared scope includes `StoreBankController`). Do not double-count — this entry only records that `mgmt-stores.md` has no route/feature entry for `stores.banks.*`.

---

## Corrections

### 1. §2.2 per-store dashboard — `api_status` is **`partial`**, not `missing`

The audit says "no per-store analytics/stats endpoint … there is no chart/series/list payload for a single store". That is wrong. `GET /api/v1/management/dashboard` accepts `store_id` (intersected with the caller's accessible stores) and returns **store-scoped** `stats` (revenue `total`/`this_month`; orders `total`/`pending`; products `total`/`low_stock`), `recent_orders` (latest 8 with customer/store/status/total) and `revenue_series` (6 months) — i.e. the revenue chart, metric-card and recent-sales **data** already exists for a single store (`Api\V1\Management\DashboardController@index`, lines 27–112). What is genuinely absent: revenue %-change vs last month, completed split, active-product/stock totals, a store-scoped unique-customer count (payload `customers.total` is business-wide), the low-stock **item list** (only a count, and 1–5 not 1–10), and the POS/web-storefront card data. SPA status stays `missing` (DashboardView never sends `store_id`). Knock-on: the summary table and headline become **9 partial / 17 missing**.

### 2. §1.1 — "Create Store button hidden from staff" is not true in the legacy view

`index.blade.php` renders the Create Store button (and the empty-state CTA) unconditionally; the `canCreate` flag the controller computes is never consumed by the view. Staff see the button and only hit the `stores create` permission gate (403) on click. Correct the legacy description so nobody ports a gating pattern that legacy did not actually render.

### 3. §4.2 reactivate — the KYC gate checks the **acting user**, not the store owner

`StoreLifecycleController@activate` reads `$request->user()->kycApplication`. The audit's "blocked unless the owner has an approved KYC application" implies the store's owner; in practice a staff member with `stores settings` operating the store settings page can never reactivate (their own KYC application is absent/not approved). Decide deliberately whether the port keeps the acting-user gate or moves it to the owner.

### 4. §4.1/§4.2 — lifecycle emails go to the **acting user**, not the owner

`StoreLifecycleController@sendStatusMail` queues to `$request->user()->email` (and `StoreSuspended`/`StoreReactivated` are addressed by the caller). The audit's "the owner is emailed StoreSuspended" / "queues StoreReactivated email with reason" overstates the audience — same acting-user quirk as correction 3.

---

## Smaller notes (no audit text change demanded)

- §2.3 "section (with warehouse)": the products tab shows the section name only — the eager-loaded warehouse is never rendered. Cosmetic.
- §2.2 "Low Stock card (products qty 1–10, latest 6)": `StoreAnalyticsService@dashboard` takes 6 with no ordering ("latest" is not applied) and filters `status = active`. Cosmetic.
- §2.8 "Add Staff … scrolls to the assigned-staff card": the dispatched `scrollTo:'assigned-staff-card'` anchor id does not exist anywhere in the legacy views, so the scroll silently no-ops. Legacy quirk; do not port the dead anchor.
- §5.1/§6.1 dead-code calls are correct: `delivery-routes.blade.php` references removed `management.delivery-routes.save`, `payment-methods.blade.php` references removed `management.payment-methods.{skip,bank,paystack}` and `management.{store.get-banks,store.validate-bank,delivery-routes.form}` — all confirmed absent from `routes/`.
- §1.1 "missing: the whole filter bar": the legacy list view had **no** filter form either — status/q/from/to were URL-only server-side filters. Phrase as "URL filters never surfaced in the new UI" if it matters.
- Store create: `ownership_type_id`/`business_type_id` are validated by `CreateStoreRequest` and passed to the view but never rendered as fields; the audit correctly does not list them.
- `stores.banks.*` update/`setPrimary` do not verify the `{bank}` belongs to `{store}` (any StoreBank id works) — worth fixing if rebuilt, per mgmt-finance §3.4's tenant-check note.
