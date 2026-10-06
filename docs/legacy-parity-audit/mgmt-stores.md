# mgmt-stores — Legacy → New Stack Feature Inventory

**Domain:** Stores and their settings (audience: business)
**Legacy source of truth:** `storify-api` @ `routes/v1/management.php` + `app/Http/Controllers/Management/{Store,StoreTab,StoreLifecycle,StoreSettings,StoreDashboard,StoreDeliveryRoute,Location}Controller.php` + `resources/views/management/stores/**`, `resources/views/management/locations/**`
**New stack:** `storify-api` (`routes/api/v1/management.php`, `app/Http/Controllers/Api/V1/Management/**`) + `storify-management` (`src/router/index.ts`, `src/views/**`, `src/api/endpoints.ts`)
**Status vocabulary:** `exists` = fully usable; `partial` = endpoint or screen present but missing actions/fields/validations; `missing` = absent.

---

## Executive summary

The new management SPA ships exactly **one store screen** — `StoresView.vue`, a read-only table (name/slug/type, products, orders, balance, status) backed by two API endpoints (`GET /management/stores`, `GET /management/stores/{store}`). Everything else that made stores operable in legacy is gone: **store creation, the tabbed store detail screen and all nine tabs, per-store dashboard analytics, store settings (details, branding, socials, service charges, staff/bank/payment assignment), the store lifecycle (suspend/activate/delete with guards and emails), delivery-route management, web-storefront enablement, POS enablement per store, and the locations module.**

| Area | Legacy features | exists | partial | missing |
|---|---|---|---|---|
| Store list & creation | 4 | 0 | 1 | 3 |
| Store detail shell & tabs | 9 | 0 | 5 | 4 |
| Per-store dashboard / web metrics | 2 | 0 | 0 | 2 |
| Per-store settings | 8 | 0 | 1 | 7 |
| Store lifecycle | 3 | 0 | 0 | 3 |
| Delivery routes | 1 | 0 | 0 | 1 |
| Locations | 1 | 0 | 0 | 1 |

**Headline numbers:** 0 of 26 audited features are fully usable in the new stack. 8 are partial (read-only foundation only), 18 are entirely missing. Store creation, store detail, settings and lifecycle are the P0 blockers — a business cannot add, configure, pause or remove a store from the new UI at all.

**Route-level reality check (new API):**

```php
Route::middleware('permission:stores view')->group(function () {
    Route::get('stores', [StoreController::class, 'index'])->name('stores.index');
    Route::get('stores/{store}', [StoreController::class, 'show'])->name('stores.show');
});
```

That is the complete business-facing store surface in `routes/api/v1/management.php`. `StoreController@show` returns a basic payload (`id, store_id, name, slug, status, store_type, has_website, pos_enabled, balance, payment_mode, *_count, logo_url, created_at`) and nothing else; the admin API (`routes/api/v1/admin.php`) is equally read-only (`GET admin/stores`, `GET admin/stores/{store}`), so there is no alternate route into these features either.

---

## 1. Store list & creation

### 1.1 Store list with filters and per-store quick actions — `partial`
- **What the user could do (legacy):** Browse accessible stores (staff see only assigned stores) as a card grid: logo, name, `store_id` code, status badge, description, location, product/category counts, empty-state CTA. Filters accepted server-side: `status` (active / inactive / suspended / deleted — anything else shows all non-deleted), free-text `q` over name and `store_id`, and `from`/`to` created-date range; 10 per page with query string preserved. Per-card quick actions: **View** (store detail), **Orders** (`/management/stores/{store}/orders`, pre-filtered order list), **Storefront** (public URL, only when `has_website`), and a settings glyph that deep-links to the store detail `#settings` tab. Create Store button hidden from staff.
- **Legacy route:** `GET /management/stores` (`management.stores.index`); deep links `GET /management/stores/{store}/orders`, `GET /management/stores/{store}/products`
- **Legacy controller:** `Management\StoreController@index` (+ route closures at `routes/v1/management.php` lines 205, 250)
- **Legacy views:** `resources/views/management/stores/index.blade.php`
- **New API:** `GET /api/v1/management/stores` → `Api\V1\Management\StoreController@index` — paginated, `q` and `per_page` only; payload omits description/location/categories_count and always returns `customers_count: null`.
- **New SPA:** `storify-management/src/views/StoresView.vue` (route `/stores`), `storesApi.index`
- **Missing:** the whole filter bar (status, created-date range — and `q` has no UI input either); card layout with logo/description/location/category counts; per-card quick actions (View, Orders, Storefront, settings deep link); Create Store button; API `status`/`from`/`to` filters and category counts; sorting is name-only in the new API vs `latest()` in legacy.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 1.2 Create store (full onboarding form) — `missing`
- **What the user could do (legacy):** A single create screen with: logo upload + preview (PNG/JPG/WEBP, max 2MB); **Store Model** checkboxes — *Physical Store* reveals Physical Address, *Online Storefront* reveals the slug preview; store name; description; support email and phone; business address; currency select; optional **bank account assignment** (business-scoped banks); optional **staff assignment**; collapsible social links (Instagram, Facebook, X/Twitter, TikTok); an explainer modal for "Online Storefront". Defaults are pre-filled from the owner (`pending_store_defaults` session or user name/email/phone/location). Email-verification gate. Server validates unique support email/phone, `ReservedStoreSlug`, assigns bank/staff pivots and stores the logo atomically; store starts `pending`.
- **Legacy route:** `GET /management/stores/create` (`stores.create`), `POST /management/stores` (`stores.store`)
- **Legacy controller:** `Management\StoreController@create` / `@store`; `App\Http\Requests\Management\CreateStoreRequest`; `App\Actions\Stores\CreateStore`
- **Legacy views:** `resources/views/management/stores/create.blade.php`
- **New API:** none — `CreateStore` action exists in the repo but is referenced only by the legacy controller; no API route or controller uses it.
- **New SPA:** none; no create button, form or route.
- **Missing:** everything. Without this a business literally cannot add a second store (or the first, post-onboarding) in the new UI.
- **Side effects:** store row created (status `pending`), `store_banks` pivot, `store_staff` pivot, logo written to `storage/stores/logos`.
- **Effort:** M — **Priority:** P0

### 1.3 Live slug availability check — `missing`
- **What the user could do (legacy):** On the create form and in the "Enable Web Storefront" modal, typing a store name debounced-checks `POST /management/stores/check-slug`, which returns `available`, a collision-free `slug` suggestion (auto-suffixed `-1`, `-2`…), and the full `<slug>.<main_domain>` URL, rendered as "✓ Available" / "Suggested: …".
- **Legacy route:** `POST /management/stores/check-slug` (`management.store.check-slug`) — outside the auth+subscription group, throttled by login throttle
- **Legacy controller:** `Management\StoreController@checkSlugAvailability`
- **Legacy views:** `create.blade.php`, `show.blade.php` (website modal), `storefront/create.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** availability endpoint + debounced UI in any future store-create/storefront form.
- **Effort:** S — **Priority:** P1

### 1.4 Store creation success / finalize page — `missing`
- **What the user could do (legacy):** After creating a store, land on `/management/stores/{store}/finalize`: celebratory header, store logo and name, the **public storefront URL with a copy-to-clipboard button**, and smart next-step CTA — "Go to Dashboard" when a subscription is active, otherwise "Configure Store" (links to store settings). Access-checked.
- **Legacy route:** `GET /management/stores/{store}/finalize` (`stores.success.new`)
- **Legacy controller:** `Management\StoreController@success`
- **Legacy views:** `resources/views/management/stores/success.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the whole screen; without store creation it is moot, but the onboarding hand-off (copy URL → configure) must be rebuilt alongside it.
- **Effort:** S — **Priority:** P2

---

## 2. Store detail: shell and tabs

### 2.1 Tabbed store detail shell — `partial`
- **What the user could do (legacy):** Open a store and get a tabbed workspace: header actions **Visit Storefront** (when `has_website` + slug), **Open POS Portal** (when `pos_enabled`), **Stock Adjustment**; tab bar with **Dashboard, Products, Sales, Transactions, Customers, Invoices, Staff, Web Store, Settings** — Products/Sales/Staff gated by permissions, Web Store shown only when `has_website`. Tabs are fetched by AJAX (`/stores/{store}/tab/{tab}`), cached client-side, with loading/error/retry states, hash-based deep linking (`#settings`), browser back/forward handling, and pagination/filter links intercepted to stay inside the tab. Breadcrumbs Dashboard → Stores → {store}.
- **Legacy route:** `GET /management/stores/{store}` (`management.stores.show`); `GET /management/stores/{store}/tab/{tab}` (`management.stores.tab`)
- **Legacy controller:** `Management\StoreDashboardController@show`; `Management\StoreTabController@show` (dispatches products/orders/settings/staff/transactions/customers/invoices/web-metrics)
- **Legacy views:** `resources/views/management/stores/show.blade.php` + `tabs/*.blade.php`
- **New API:** `GET /api/v1/management/stores/{store}` → `StoreController@show` — access check + profile payload only. No tab, analytics or sub-resource endpoints exist.
- **New SPA:** none — no `/stores/:id` route at all; `StoresView.vue` rows are not clickable.
- **Missing:** the entire screen: store detail route, tab shell, lazy tab loading, header actions, permission gating, deep links. Note the new API's `show` loads `ownershipType`/`businessType` but never returns them.
- **Side effects:** none (shell).
- **Effort:** M — **Priority:** P0

### 2.2 Store dashboard tab (per-store metrics) — `missing`
- **What the user could do (legacy):** Four metric cards — **Revenue (This Month)** with % change vs last month, **Total Sales** with pending/completed split, **Products** with active count + total stock, **Customers** (unique buyers) — plus a 6-month **Revenue Overview** area chart (ApexCharts), **Recent Sales** list (latest 8 orders, customer, status badge, total, link to order detail), a **Web Storefront** card (Live + visit link, or Enable Web Storefront button opening the modal), a **POS Terminal** card (session open state with opened-by/since/float, close-session form with cash-count field; or open-session float form; or Enable POS button), and a **Low Stock** card (products qty 1–10, latest 6) with an out-of-stock count footer. Breadcrumbs Dashboard → Stores → {store}.
- **Legacy route:** `GET /management/stores/{store}` (`management.stores.show`)
- **Legacy controller:** `Management\StoreDashboardController@show`; `App\Services\StoreAnalyticsService@dashboard`
- **Legacy views:** `resources/views/management/stores/show.blade.php`
- **New API:** none — no per-store analytics/stats endpoint (`GET stores/{store}` returns counts only). The business-level `GET /management/dashboard` accepts a `store_id` filter for revenue/orders/products/customers but the SPA never sends it and there is no chart/series/list payload for a single store.
- **New SPA:** none.
- **Missing:** every metric, the revenue series, recent-sales and low-stock lists, the storefront status card, and the POS enable/open/close card (POS session APIs exist under `api/v1/pos.php` but nothing on the business dashboard calls them).
- **Side effects (legacy screen):** POS session open/close; storefront enablement; POS enablement.
- **Effort:** M — **Priority:** P0

### 2.3 Store Products tab — `partial`
- **What the user could do (legacy):** Per-store product table fetched via AJAX: search `q` over name and `product_code`, status filter (active/inactive), per-page selector (10/50/100), pagination that stays in the tab; columns thumbnail, name + code, section (with warehouse), price (variant min–max range formatted with currency symbol), stock (red when ≤ 5), status badge; link to product detail; **Add** button that opens product create pre-selected to this store.
- **Legacy route:** `GET /management/stores/{store}/tab/products` (`management.stores.tab`)
- **Legacy controller:** `Management\StoreTabController@products`
- **Legacy views:** `resources/views/management/stores/tabs/products.blade.php`
- **New API:** `GET /api/v1/management/products` supports `store_id`, `q`, `status`, pagination — the data is reachable, but there is no store-scoped tab endpoint or store page to host it.
- **New SPA:** `src/views/ProductsView.vue` has a store filter dropdown and product create form with store select; no store detail tab.
- **Missing:** the store-scoped tab itself; variant price-range display is also absent from the new products list (separate domain). Add-product-from-store deep link.
- **Side effects:** none.
- **Effort:** S (reuse existing list/API) — **Priority:** P1

### 2.4 Store Sales (Orders) tab — `partial`
- **What the user could do (legacy):** Per-store order table: search over order number and customer first/last name; status filter with all 7 statuses (pending, processing, dispatched, delivered, completed, cancelled, returned); columns order number (+ purple POS badge), customer (or Walk-in), item count, total, status badge, date; pagination; link to order detail.
- **Legacy route:** `GET /management/stores/{store}/tab/orders`
- **Legacy controller:** `Management\StoreTabController@orders`
- **Legacy views:** `resources/views/management/stores/tabs/orders.blade.php`
- **New API:** `GET /api/v1/management/orders` supports `store_id`, `q`, `status`, `from`, `to` — reachable, but no store tab endpoint.
- **New SPA:** `src/views/OrdersView.vue` — has q/status/date filters but **no store filter control** and no store tab.
- **Missing:** store-scoped tab; store filter on the global orders screen (API supports it, UI does not).
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 2.5 Store Transactions tab — `partial`
- **What the user could do (legacy):** Per-store transactions across orders *and* invoices (confirmed only for revenue elsewhere): status filter (confirmed / pending / refunded / refund_pending); columns reference (mono link), customer (order customer or invoice recipient), amount, payment method, status badge, date; pagination; link to transaction detail.
- **Legacy route:** `GET /management/stores/{store}/tab/transactions`
- **Legacy controller:** `Management\StoreTabController@transactions`
- **Legacy views:** `resources/views/management/stores/tabs/transactions.blade.php`
- **New API:** `GET /api/v1/management/transactions` is business-scoped only — **no `store_id` filter** — and there is no store tab endpoint.
- **New SPA:** `src/views/TransactionsView.vue` (global list + detail drawer with confirm/reject/refund); no store filter, no store tab.
- **Missing:** store scoping on the API and screen; the per-store tab.
- **Side effects:** none on the list (mutations live in the transactions domain).
- **Effort:** S–M (add `store_id` filter) — **Priority:** P1

### 2.6 Store Customers tab — `partial`
- **What the user could do (legacy):** Per-store customer list limited to customers who have ordered from this store, with an orders-count scoped to the store; columns avatar/initials, name + email, phone, orders count, status badge, "since" date; pagination; link to customer detail. Header line `{n} customer(s) who purchased from this store`.
- **Legacy route:** `GET /management/stores/{store}/tab/customers`
- **Legacy controller:** `Management\StoreTabController@customers`
- **Legacy views:** `resources/views/management/stores/tabs/customers.blade.php`
- **New API:** `GET /api/v1/management/customers` — `q`/`status` only; **no store filter** and no per-store order counts.
- **New SPA:** `src/views/CustomersView.vue` — global list/detail; no store filter.
- **Missing:** store scope (query + UI) and per-store order counts.
- **Effort:** S–M — **Priority:** P1

### 2.7 Store Invoices tab — `missing`
- **What the user could do (legacy):** Per-store invoice table: status filter over all `InvoiceStatus` cases, `q` search over invoice number / recipient name / recipient email, "Clear" button when filtered; columns invoice number + issue date, customer/recipient + email, total with amount-paid hint when partial and status ≠ paid, colour-dotted status badge, due date (red when overdue); pagination; link to invoice detail.
- **Legacy route:** `GET /management/stores/{store}/tab/invoices`
- **Legacy controller:** `Management\StoreTabController@invoices`
- **Legacy views:** `resources/views/management/stores/tabs/invoices.blade.php`
- **New API:** no management invoice endpoints at all (POS has read/create under `api/v1/pos.php`), so no store-scoped invoice data.
- **New SPA:** none.
- **Missing:** the entire management invoices area is absent (owned by the orders/invoices domain audit); the store tab needs both the module and the tab.
- **Effort:** M (depends on mgmt-invoices module) — **Priority:** P2

### 2.8 Store Staff tab — `partial`
- **What the user could do (legacy):** Staff assigned to this store: count line; per-member avatar initials, name + email, role badges, "N store(s), M WH(s)" summary, status badge, **Edit** link to staff edit; **Add Staff** button that switches to the settings tab and scrolls to the assigned-staff card (permission `staff create`); empty state.
- **Legacy route:** `GET /management/stores/{store}/tab/staff`
- **Legacy controller:** `Management\StoreTabController@staff`
- **Legacy views:** `resources/views/management/stores/tabs/staff.blade.php`
- **New API:** `GET /api/v1/management/staff` returns each staff's `assignedStores`; assignment can be changed through `PUT /management/staff/{staff}` (`store_ids`), but there is no store-scoped endpoint.
- **New SPA:** `src/views/StaffView.vue` can check/uncheck stores in the staff create/edit modal; no store tab / store-side list.
- **Missing:** store-scoped staff list and the "Add Staff" jump-to-settings flow.
- **Effort:** S — **Priority:** P1

### 2.9 Per-store web metrics (tab + standalone page) — `missing`
- **What the user could do (legacy):** **Tab** (Web Store, when `has_website`): Store Views, Product Views, Web Orders, Web Revenue metric tiles; 6-month Web Orders bar chart; Top Products by Views list (top 10, links to product). **Standalone page** (`/stores/{store}/web-metrics`, gated on `has_website` with redirect + error flash otherwise): same four metric cards, same chart, ranked Top Products, a "Your storefront is live / Visit Store" card with URL, and a **Recent Activity** feed from `ActivityLog` (description, time-ago, IP address). Both use `StoreAnalyticsService@web`.
- **Legacy route:** `GET /management/stores/{store}/tab/web-metrics`; `GET /management/stores/{store}/web-metrics` (`management.stores.web-metrics`)
- **Legacy controller:** `Management\StoreDashboardController@webMetrics`; `Management\StoreTabController@webMetrics`
- **Legacy views:** `resources/views/management/stores/tabs/web-metrics.blade.php`, `resources/views/management/stores/web-metrics.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the whole analytics feature (view counters exist on `Store.views` / `Product.views` but are never exposed).
- **Effort:** M — **Priority:** P2

---

## 3. Per-store settings

### 3.1 Store details and branding edit — `missing`
- **What the user could do (legacy):** Edit a store's name, support email, support phone, address, description and logo (image mimes jpeg/png/jpg/webp, ≤ 2MB) from both the Settings tab card and the standalone settings page; slug is (re)generated from the provided slug or name via `Str::slug`, de-duplicated with `-1`, `-2`… suffixes, blocked for reserved words (`ReservedStoreSlug` rule); `redirect_to` decides where to return; the old logo file is deleted after a successful save and the new file cleaned up on failure. Update requires `stores edit` permission.
- **Legacy route:** `PUT /management/stores/{store}` (`stores.update`); screens `GET /management/stores/{store}/settings` (`stores.settings`) and the tab `GET /management/stores/{store}/tab/settings`
- **Legacy controller:** `Management\StoreSettingsController@update` / `@show`; `Management\StoreTabController@settings`
- **Legacy views:** `resources/views/management/stores/tabs/settings.blade.php`, `resources/views/management/stores/settings.blade.php`
- **New API:** none — no PUT/PATCH store endpoint; `show` is read-only.
- **New SPA:** none.
- **Missing:** the entire edit capability (name/contacts/address/description/logo/slug), validations and file lifecycle.
- **Side effects:** logo file create/delete on the `public` disk; slug change affects the live storefront URL.
- **Effort:** M — **Priority:** P0

### 3.2 Store social links edit — `missing`
- **What the user could do (legacy):** Dedicated "Social Links" card with Instagram, Facebook, X (Twitter) and TikTok URL fields (each `nullable|url|max:255`), saved through the same store update endpoint and shown as icons.
- **Legacy route:** `PUT /management/stores/{store}` (`stores.update`)
- **Legacy controller:** `Management\StoreSettingsController@update`
- **Legacy views:** `resources/views/management/stores/tabs/settings.blade.php`
- **New API:** none (payload doesn't even expose the fields).
- **New SPA:** none.
- **Missing:** fields + endpoint (also absent from the create form in the new stack).
- **Effort:** S — **Priority:** P2

### 3.3 Service charges CRUD and toggle — `missing`
- **What the user could do (legacy):** Per-store service charges card: list with name, amount, truncated description, Active/Inactive pill; **Disable/Enable** toggle; inline **Edit** (prefills the form); **Delete** with confirm; **Add Service Charge** form (name required, amount numeric ≥ 0, description ≤ 500). All mutations ride the store update endpoint via special fields (`service_charge_name`, `service_charge_amount`, `service_charge_description`, `service_charge_id`, `delete_service_charge_id`, `toggle_service_charge_id`). These charges are applied at POS checkout.
- **Legacy route:** `PUT /management/stores/{store}` (special-case branches in `management.stores.update`)
- **Legacy controller:** `Management\StoreSettingsController@update` + private `saveServiceCharge`
- **Legacy views:** `resources/views/management/stores/tabs/settings.blade.php`
- **New API:** no management endpoint. POS has a **read-only** `GET /pos/stores/{store}/service-charges` (`Api\V1\Pos\ServiceChargeController@index`) — checkout can display charges but nobody can create them.
- **New SPA:** none.
- **Missing:** create/update/delete/toggle endpoints and the management card.
- **Effort:** S — **Priority:** P1

### 3.4 Store staff assignment (assign/remove from the store) — `partial`
- **What the user could do (legacy):** Assigned-staff list with avatar, role badges, email and **Remove** per member; assign form with a select of business staff not yet assigned (with their roles) and an Assign button; guarded to business-scoped `role=staff` users.
- **Legacy route:** `POST /management/stores/{store}/assign-staff` (`stores.assign-staff`), `DELETE /management/stores/{store}/remove-staff/{user}` (`stores.remove-staff`)
- **Legacy controller:** `Management\StoreSettingsController@assignStaff` / `@removeStaff` (also in `StoreTabController@settings` for the available list)
- **Legacy views:** `resources/views/management/stores/tabs/settings.blade.php`, `settings.blade.php`
- **New API:** **partial** — no store-scoped endpoints, but `POST/PUT /api/v1/management/staff` accepts `store_ids[]` and syncs `assignedStores` (intersected with allowed stores).
- **New SPA:** **partial** — `StaffView.vue` handles store checkboxes in the staff create/edit modal; there is no store-side screen.
- **Missing:** assign/remove from the store context; the store staff card.
- **Effort:** S — **Priority:** P1

### 3.5 Store payment methods and bank accounts assignment — `missing`
- **What the user could do (legacy):** "Payment Methods" card showing assigned gateways (with masked public key) and assigned bank accounts (account name, masked number, Verified badge); a **Manage Payment Methods** modal listing Assigned Gateways / Assigned Bank Accounts / Available Gateways / Available Bank Accounts. Actions: assign a business gateway to the store (`POST /management/payment-method/{id}/assign/{type}` with `store_id`), unassign (`DELETE …/unassign/{type}/{store_id}`), assign a business bank account (`POST /management/stores/{store}/assign-bank`), remove it (`DELETE /management/stores/{store}/remove-bank/{bank}`); "All available payment methods are assigned" state with link to Payment Settings.
- **Legacy route:** `stores.assign-bank`, `stores.remove-bank`; `payment-settings.assign-store`, `payment-settings.unassign-store` (PaymentSettings domain owns the gateway side)
- **Legacy controller:** `Management\StoreSettingsController@assignBank` / `@removeBank`; `Management\PaymentSettingsController@assignStore` / `@unassignStore`; `StoreTabController@settings` builds the modal data
- **Legacy views:** `resources/views/management/stores/tabs/settings.blade.php`
- **New API:** none in the management API (no payment-method/bank endpoints at all).
- **New SPA:** none.
- **Missing:** both the store↔gateway and store↔bank pivots are unmanageable. This decides where money settles, so treat as money-critical.
- **Effort:** M — **Priority:** P0

### 3.6 Enable POS per store — `missing`
- **What the user could do (legacy):** From the store dashboard or settings screen, click **Enable POS** (POST, sets `pos_enabled`), then see "POS terminal enabled" state, an **Open Terminal** link to the POS portal, an open-session form (opening cash float) and, when a session is live, opened-by/since/float plus a close-session form.
- **Legacy route:** `POST /management/stores/{store}/pos/enable` (`pos.enable`); session open/close on the dashboard card and settings page (`management.pos.open`, `management.pos.close`)
- **Legacy controller:** `Management\StoreSettingsController@enablePos`
- **Legacy views:** `resources/views/management/stores/show.blade.php`, `tabs/settings.blade.php`, `settings.blade.php`
- **New API:** none — no endpoint sets `pos_enabled`; the POS API (`api/v1/pos.php`) only reads it (`SessionController` rejects stores where `pos_enabled` is false, `Pos\AuthController` filters by it).
- **New SPA:** none.
- **Missing:** the enable switch; consequently no store can be onboarded to POS via the new UI. (Session open/close exist in the POS app for already-enabled stores.)
- **Effort:** S — **Priority:** P1

### 3.7 Web storefront enablement and creation wizard — `missing`
- **What the user could do (legacy):** Two flows. (a) **Enable Web Storefront** modal on the store dashboard: store name, live slug check, optional **Nationwide Delivery** checkbox revealing flat Delivery Fee (₦) and Delivery Days; on save sets `name`, `slug`, `has_website = true` and upserts a nationwide `DeliveryRoute` (state "All States", country Nigeria). (b) **Create Storefront** wizard (`/stores/{store}/storefront/create`): template chooser (one "Basic" template card), store name + slug check, nationwide delivery options, "What You Get" sidebar with the prospective URL; redirects back with the live URL. Requires permission `stores settings`; refuses when the store already has a website.
- **Legacy route:** `POST /management/stores/{store}/enable-website` (`stores.enable-website`), `GET/POST /management/stores/{store}/storefront/create|store` (`stores.storefront.create/store`)
- **Legacy controller:** `Management\StorefrontController@create/store/enableWebsite`
- **Legacy views:** `resources/views/management/stores/show.blade.php` (modal), `resources/views/management/stores/storefront/create.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** both flows, the delivery upsert, and the URL feedback. A store created with `has_website = false` can never go online from the new UI (nor can the new UI create a store at all).
- **Effort:** M — **Priority:** P1

### 3.8 Store settings screen shell (cards, info sidebar, danger zone) — `missing`
- **What the user could do (legacy):** A settings workspace in two renderings — the AJAX **Settings tab** inside the store detail page and the **standalone page** `/stores/{store}/settings` (tab bar Dashboard / Web Metrics / Settings). Cards: Storefront CTA (create storefront when absent), Store Details, Social Links, Payment Methods, Service Charges, Assigned Staff, Store Info sidebar (logo, store ID, created date, business type, status badge), POS Terminal card, and a **Danger Zone** with Reactivate (if suspended) or Suspend-with-reason (expandable input) plus **Delete Store** behind a confirmation modal warning orders/products/settings are preserved but hidden. The standalone variant additionally lists **Bank Accounts** (read-only) and **Delivery Routes** (read-only: area/state/country, fee, days) and links to POS Session History.
- **Legacy route:** `GET /management/stores/{store}/tab/settings`; `GET /management/stores/{store}/settings`
- **Legacy controller:** `Management\StoreTabController@settings`; `Management\StoreSettingsController@show`
- **Legacy views:** `resources/views/management/stores/tabs/settings.blade.php`, `resources/views/management/stores/settings.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the whole settings screen and its composition (the forms inside it are tracked as 3.1–3.6).
- **Effort:** M — **Priority:** P0

---

## 4. Store lifecycle

### 4.1 Suspend store (with reason) — `missing`
- **What the user could do (legacy):** Suspend a store from the settings Danger Zone, optionally typing a **suspension reason** (`nullable|string|max:2000`, defaults to "Suspended by store owner"); `status` becomes `suspended`. Two UI paths: a reason-reveal form on the settings tab button and a confirm modal on the standalone page (which posts a fixed reason). The owner is emailed **StoreSuspended** (queued; failures logged, never block).
- **Legacy route:** `PATCH /management/stores/{store}/suspend` (`stores.suspend`) — permission `stores settings`
- **Legacy controller:** `Management\StoreLifecycleController@suspend`
- **Legacy views:** `resources/views/management/stores/tabs/settings.blade.php`, `settings.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the transition, reason capture, email.
- **Side effects:** `status = suspended`, queued `StoreSuspended` mail, store hidden from customers (storefront gating reads status).
- **Effort:** S — **Priority:** P1

### 4.2 Reactivate store (KYC-gated) — `missing`
- **What the user could do (legacy):** Reactivate a suspended store; **blocked unless the owner has an approved KYC application**, with error flash "Complete KYC verification before activating this store."; optional reason (defaults "Reactivated by store owner"); sets `status = active` and queues **StoreReactivated** email with reason.
- **Legacy route:** `PATCH /management/stores/{store}/activate` (`stores.activate`)
- **Legacy controller:** `Management\StoreLifecycleController@activate` (reads `App\Models\KycApplication::STATUS_APPROVED`)
- **Legacy views:** `resources/views/management/stores/tabs/settings.blade.php`, `settings.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the transition, the KYC gate, the email.
- **Effort:** S — **Priority:** P1

### 4.3 Delete store (guarded, soft status) — `missing`
- **What the user could do (legacy):** Delete a store behind a confirmation modal ("This action cannot be undone… Orders, products, and settings will be preserved"). Server guards: refuses if any order is not `completed` ("Cannot delete: store has incomplete orders.") or any transaction is not `confirmed` ("Cannot delete: store has incomplete transactions."); otherwise sets `status = deleted` (data retained), logs `store.deleted`, and redirects to the store list with a success flash. Deleted stores are excluded from the default index filter.
- **Legacy route:** `DELETE /management/stores/{store}` (`stores.destroy`) — permission `stores settings`
- **Legacy controller:** `Management\StoreLifecycleController@destroy`
- **Legacy views:** `resources/views/management/stores/tabs/settings.blade.php`, `settings.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the transition, the two guards, the log, the modal.
- **Side effects:** `status = deleted`, `Log::info('store.deleted')`.
- **Effort:** S — **Priority:** P2

---

## 5. Delivery routes

### 5.1 Delivery route CRUD (per store) — `missing`
- **What the user could do (legacy):** Create/update/delete delivery routes for a store with fields Country (required), State (required), Area (optional), Delivery Fee in ₦ (required, ≥ 0, converted to kobo ×100), Delivery Days (required, ≥ 1) and Active checkbox; owner-only authorization (`$store->user_id === $user->id`), everything logged as `business.delivery_route.created/updated/deleted`. The settings screen shows the routes read-only (area/state, country, fee, days) and the storefront wizard auto-creates a nationwide route. A legacy multi-route bulk form (`delivery-routes.blade.php`, add/remove rows, "Add Another Route", fee shown in ₦) survives in the repo but references **dead routes** (`management.delivery-routes.save/form` no longer exist) and is unreachable — obsolete.
- **Legacy route:** `POST/PUT/DELETE /management/stores/{store}/delivery-routes[/{deliveryRoute}]` (`stores.delivery-routes.store/update/destroy`) — permission `stores settings`
- **Legacy controller:** `Management\StoreDeliveryRouteController@store/update/destroy`; read-only list via `StoreSettingsController@show` / `StoreTabController@settings`
- **Legacy views:** `resources/views/management/stores/settings.blade.php` (list), `delivery-routes.blade.php` (orphaned bulk form)
- **New API:** none.
- **New SPA:** none (delivery fee/days surface only as POS/checkout math if a route exists from legacy data).
- **Missing:** the whole feature — CRUD endpoints, UI, ₦→kobo handling, active toggle.
- **Side effects:** route records affect storefront checkout shipping fees.
- **Effort:** S–M — **Priority:** P1

---

## 6. Locations

### 6.1 Location management (list, create, edit, delete, detail) — `missing` *(legacy-dead — see note)*
- **What the user could do (legacy):** `LocationController` + four Blade views implement a full module: index card grid (name, `location_code`, active/inactive badge, address/city/state, warehouse count) with an overflow menu (View/Edit/Delete); create modal and edit modal (name required, address, country fixed to Nigeria, state select from the Nigeria dataset, city select, active checkbox); delete confirmation modal; show page with Location Details plus **Warehouses at this Location** (name, sections count, stock quantity sum, status) and Add Warehouse / Edit Location buttons.
- **Legacy route:** **none registered** — `routes/v1/*.php` contain no locations routes; the controller and views are unreachable dead code (confirmed against `routes/v1/management.php`), and `AGENTS.md` states the Location model "exists in DB but is NOT used in UI — warehouse location is set via state/city directly on the warehouse form".
- **Legacy controller:** `Management\LocationController@index/create/store/show/edit/update/destroy`
- **Legacy views:** `resources/views/management/locations/{index,create,show,edit}.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing / judgement:** treat as **obsolete unless the business wants multi-branch location grouping**; if revived, the warehouse module's location association has changed (warehouses no longer carry `location_id`), so this would be a net-new feature rather than a port. Recommend deprioritising.
- **Effort:** S (if revived as a simple CRUD) — **Priority:** P2

---

## Gaps worth calling out

1. **The new stores area is read-only — and barely.** Two GET endpoints and one table view. There is no `POST /stores`, no `PUT /stores/{store}`, no lifecycle, no settings, no tabs. For a platform whose core entity is "store", this is the largest single hole in the management rewrite.
2. **No store can be created from the new UI.** The `CreateStore` action and `CreateStoreRequest` still exist server-side and are used by the legacy controller only — a ready-made implementation waiting for a controller + endpoint + form. This is the cheapest P0 win in the domain.
3. **Store detail has no route at all in the SPA.** `StoresView.vue` rows are not clickable; every per-store workflow (products of a store, sales of a store, staff of a store, settings, dashboard) has no entry point. Nine legacy tabs collapse to zero.
4. **Lifecycle is absent, including its guards.** Suspend/activate/delete carry real business rules (suspension reason, KYC-approved gate for activation, "no incomplete orders/transactions" gate for deletion, queued owner emails) — none of it exists. Deleting/suspending a store from the new stack is impossible, so a misbehaving store cannot be stopped.
5. **Money plumbing per store is missing.** Bank-account and gateway assignment pivots (`store_banks`, `store_payment_method`), the nationwide-delivery upsert and service charges all live inside store settings in legacy; without them the new UI cannot route payouts or configure checkout fees. POS can *read* service charges but nobody can *create* them.
6. **The new store API payload is thinner than the legacy screens need.** `show` eager-loads `ownershipType`/`businessType` but drops them from the response; description, contacts, address, physical address, socials, currency and category counts are never returned, and `customers_count` is hard-coded null — a future store detail screen needs the payload widened first.
7. **Store scoping exists in APIs but not in screens (and vice-versa).** `products`/`orders` accept `store_id`; the SPA only exposes it on Products. `transactions`/`customers` have no `store_id` filter at all. Decide on one pattern (a store detail with tabs, or store filters on global lists) and implement it consistently.
8. **Dead/orphaned legacy artifacts to not port blindly:** `delivery-routes.blade.php` (references removed `management.delivery-routes.save/form` routes) and `payment-methods.blade.php`; the Location module (no routes, unused by warehouses). Their existence inflates the legacy surface — verify before rebuilding.
9. **Adjacent feature not in this scope but blocking store UX:** the active-store switcher (`POST /management/switch-store`, `DashboardController@switchStore`, session `active_store_id`, "Showing all stores" reset) has no new API or SPA equivalent. The POS API has its own `switch-store`; management does not.
10. **Admin side is equally read-only.** `routes/api/v1/admin.php` exposes only `GET admin/stores` + `GET admin/stores/{store}` (no suspend/activate even though the legacy admin had them), so there is no admin workaround for the missing lifecycle while the business UI catches up.
