# Legacy Gap Audit — Storefront Styling & Configuration

**Domain:** `mgmt-storefront-styling` · **Audience:** business (management dashboard) — the platform-admin styling surface (`admin/styling/**`, `admin/storefront_slides/**`) is covered in a separate section because the legacy "styling" features physically live there
**Legacy source of truth:** `/Users/mac/Desktop/my_files/work/storify/storify-api` — `routes/v1/management.php`, `routes/v1/admin_dashboard.php`, `routes/api/v1/admin_storefront.php`, `app/Http/Controllers/Management/{StorefrontController,StoreController,StoreSettingsController,StoreDashboardController,StoreTabController}.php`, `app/Http/Controllers/Admin/{PageStylingController,StorefrontSlideController,StoreController}.php`, `resources/views/management/stores/**`, `resources/views/admin/styling/**`, `resources/views/admin/storefront_slides/**`, `resources/views/storefront/**`
**New API:** `/Users/mac/Desktop/my_files/work/storify/storify-api` — `routes/api/v1/management.php`, `routes/api/v1/admin.php`, `app/Http/Controllers/Api/V1/Management/StoreController.php`, `app/Http/Controllers/Api/V1/Admin/StoreController.php`
**New SPAs:** `/Users/mac/Desktop/my_files/work/storify/storify-management` (`src/router/index.ts`, `src/views/StoresView.vue`, `src/api/endpoints.ts`); `/Users/mac/Desktop/my_files/work/storify/storify-admin` (same files) — plus the customer renderer `/Users/mac/Desktop/my_files/work/storify/storify-storefront` (`src/views/HomeView.vue`, `src/stores/storefront.ts`), which is the output pipeline these settings feed
**Status vocabulary:** `exists` = fully usable in the new stack; `partial` = endpoint or screen present but missing actions/fields/validations from legacy; `missing` = absent from the new stack.

---

## Scope notes (read before the inventory)

- **`resources/views/management/styling/**` does not exist.** The directory is present but empty (created 28 May, never populated). There was never a business-facing "styling" screen. The business storefront configuration surface is `Management\StorefrontController` + the store create/settings screens; the *styling* screens (`admin/styling/**`) are superadmin-only. This report covers both, labelled.
- **No per-store theme or colour ever existed.** Grepped `stores` migrations, `Store` model, all management controllers/views for `theme|colour|color|banner|hero`: nothing. The only colour/custom-CSS feature is admin **Page Styling** (`page_stylings.background_color`, `custom_css`), which is page-level for the *platform* storefront templates, applies globally (not per business), and is currently **inert in rendering** (see feature 9). Per-store theming is new scope, not parity.
- **"Banner/hero/slide" = `StorefrontSlide`** — a per-store, admin-managed list of *products* (`product_id`, `status`, `position`). `title`, `description`, `price_override`, `image_path` columns exist but no UI ever wrote them. No storefront view (legacy Blade or new SPA) renders slides — the feature is orphaned/dead output in legacy. The new storefront home instead serves `featured` products (`CatalogController@home`), and the new management products API accepts `featured` (create/update), but the management SPA exposes no featured toggle either.
- **The storefront wizard's template chooser is cosmetic.** `StorefrontController@store` validates `template` (`required|in:basic`) but `enable()` never persists it. One template ("basic") existed.
- **No SEO fields existed anywhere.** No `meta_title`/`meta_description`/OG columns, no SEO form; the empty `<meta name="description" content="">` in the storefront layout is never filled. The wizard's "SEO-friendly URLs and social sharing" bullet refers to the slug-based URL only.
- **No storefront disable existed.** Only enable. Stores are taken offline via suspend/delete (`StoreLifecycleController`, covered by `mgmt-stores.md`).
- **No live preview existed.** "Preview" in legacy = the tinted template card mock in the wizard + "Visit Storefront" links that open the live site. The new management SPA has no store-detail screen at all, so it has neither.
- **Overlap with `mgmt-stores.md`:** store creation, the tabbed store detail screen, store settings and store lifecycle are inventoried there. Here they are covered only for their storefront-specific slice (online-storefront checkbox, slug, branding fields that feed the storefront, web metrics, enable/create-storefront flows), with cross-references.

---

# A. Business (management dashboard)

## 1. Stores list with storefront status, visit link and quick actions

- **What a business user could do:** browse accessible stores as a card grid (logo, name, store code, status badge, description, location, product/category counts). Cards with `has_website` show a **Storefront** quick action that opens `{slug}.{main_domain}` in a new tab, plus a settings glyph deep-linking to the store detail `#settings` tab. Server-side filters: `status`, free-text `q` (name/store_id), created-date `from`/`to`; 10/page. "Create Store" CTA in the empty state.
- **Legacy:** `GET /management/stores` → `Management\StoreController@index` · `resources/views/management/stores/index.blade.php`
- **New API:** `GET /api/v1/management/stores` (`Api\V1\Management\StoreController@index`) — includes `has_website` and `slug` in the payload; supports `q` only.
- **New SPA:** `src/views/StoresView.vue`, route `/stores` — 5-column read-only table (Store, Products, Orders, Balance, Status).
- **Status:** api **exists**, spa **partial**.
- **Missing in the new stack:** the `has_website` website indicator, the Storefront external link (nothing in the SPA opens `{slug}.{domain}`), the settings deep link, logo/description/location, category counts, status/date filters, and the Create Store button. The API already returns `has_website`/`slug`, so the badge and link are cheap; the rest is part of the stores rebuild.
- **Effort:** S · **Priority:** P1.

## 2. Create a store with an online storefront (business-model choice, slug preview, branding, socials)

- **What a business user could do:** from the create screen choose **Store Model** checkboxes — *Physical Store* (reveals Physical Address) and/or *Online Storefront* (reveals the subdomain preview). Also: logo upload with live preview (PNG/JPG/WEBP ≤ 2 MB), store name, description ("About Your Store" — rendered on the storefront home), support email/phone, business address, currency, optional bank assignment, optional staff assignment, and a collapsible Social Links block (Instagram, Facebook, X/Twitter, TikTok — rendered in the storefront footer). An explainer modal described what an online storefront means. Submitting with Online checked sets `has_website` and the slug/subdomain.
- **Legacy:** `GET /management/stores/create`, `POST /management/stores` → `Management\StoreController@{create,store}` (via `App\Actions\Stores\CreateStore`, `App\Http\Requests\Management\CreateStoreRequest`) · `resources/views/management/stores/create.blade.php`
- **New API:** none (no store-create endpoint anywhere in `routes/api/v1/management.php`).
- **New SPA:** none (no create-store route in `src/router/index.ts`).
- **Status:** api **missing**, spa **missing**.
- **Missing in the new stack:** the entire screen. For this domain specifically: the **Online Storefront** toggle that decides `has_website`, the slug preview panel, the branding fields (logo/description/socials) that the customer-facing storefront reads. Store creation generally is inventoried in `mgmt-stores.md` §1.2 (P0 there); the storefront slice is the online-model decision + slug + branding.
- **Effort:** M (with the rest of store create; the storefront fields alone ~S on top) · **Priority:** P1 (creation itself is P0 in `mgmt-stores.md`; no storefront can exist until a store can be created).

## 3. Subdomain (slug) availability check and URL preview

- **What a business user could do:** typing a store name debounce-checked `POST /management/stores/check-slug`, which slugs the name, auto-suffixes on collision (`-1`, `-2`…), and returns `{available, slug, url, original}`. The UI rendered "✓ Available", or "Suggested: *slug*" plus the full `{slug}.{main_domain}` preview. Used in **three** places: store create, the Enable Web Storefront modal, and the storefront wizard.
- **Legacy:** `POST /management/stores/check-slug` (`management.store.check-slug`) → `Management\StoreController@checkSlugAvailability` · the three views above
- **New API:** none.
- **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** the endpoint and every debounced caller. Any rebuilt create/enable/storefront form needs it (the API side is trivial: `Str::slug` + uniqueness loop + `config('app.main_domain')`).
- **Effort:** S · **Priority:** P1.

## 4. Enable Web Storefront from the store dashboard (modal)

- **What a business user could do:** from the store dashboard's **Web Storefront** card, open the "Enable Web Storefront" modal: store name, live slug check with "✓ Available / Suggested" feedback, and an optional **Nationwide Delivery** checkbox that reveals **Delivery Fee (₦)** and **Delivery Days**. Saving updates `name`, `slug`, `has_website = true`, and upserts a nationwide `DeliveryRoute` (state "All States", country Nigeria, fee ×100 to kobo, default 3 days) so the storefront checkout can charge shipping. Success flash shows the live URL. Requires `stores settings` permission; refuses if the store already has a website.
- **Legacy:** `POST /management/stores/{store}/enable-website` (`management.stores.enable-website`) → `Management\StorefrontController@enableWebsite` · `resources/views/management/stores/show.blade.php` (card + modal + debounced slug JS)
- **New API:** none.
- **New SPA:** none — the management SPA has no store-detail route at all (`src/router/index.ts`), and `StoresView.vue` has no actions column.
- **Status:** api **missing**, spa **missing**.
- **Missing:** the whole flow. Consequence: a store created without `has_website` (or any existing store) can never be put online from the new dashboards. The nationwide delivery-route upsert must be preserved when rebuilding.
- **Effort:** S · **Priority:** P0 — it is the gateway to the online channel for every existing store.

## 5. Create Storefront wizard (template chooser + details + delivery)

- **What a business user could do:** a dedicated wizard at `/management/stores/{store}/storefront/create` for stores that don't yet have a website: choose a **template card** (one option, "Basic" — static mock with a colour chip; value validated but discarded), enter the store name, see the live-checked subdomain preview, enable **Nationwide Delivery** with flat fee + days, and read a "What You Get" sidebar (branded URL, searchable catalog, mobile-optimised, SEO-friendly URLs, inventory sync). Submitting creates the storefront and flashes the live URL. Redirects back with an info flash if the store already has a website.
- **Legacy:** `GET /management/stores/{store}/storefront/create` (`management.stores.storefront.create`), `POST /management/stores/{store}/storefront` (`management.stores.storefront.store`) → `Management\StorefrontController@{create,store}` · `resources/views/management/stores/storefront/create.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** the whole wizard. Also decide the template question on rebuild: legacy presented one template and never persisted the choice — either drop the chooser or build a real persisted theme (see gaps).
- **Effort:** M · **Priority:** P1.

## 6. Web Storefront status card + Visit Storefront action (store dashboard)

- **What a business user could do:** the store dashboard showed a **Web Storefront** card: when live — green "Live" dot, the `{slug}.{domain}` subdomain, and a "Visit Store" button; when not — "No online storefront yet" with an **Enable Web Storefront** button (feature 4). A header action "Visit Storefront" opened the site in a new tab (only when `has_website && slug`). The store dashboard itself (metrics, tabs, POS card, low-stock) is inventoried in `mgmt-stores.md` §2.1.
- **Legacy:** part of `GET /management/stores/{store}` → `Management\StoreDashboardController@show` · `resources/views/management/stores/show.blade.php`
- **New API:** partial — `GET /api/v1/management/stores/{store}` returns `has_website` + `slug` + `logo_url` but there is no SPA screen consuming it (no store-detail route in `storify-management`).
- **New SPA:** none.
- **Status:** api **partial**, spa **missing**.
- **Missing:** any store-detail UI to host the card/link. The data is already in the payload, so this is cheap once a store-detail screen exists.
- **Effort:** S (once store detail is built; currently blocked by the missing screen) · **Priority:** P2.

## 7. Web store analytics — "Web Store" tab and Web Metrics page

- **What a business user could do:** see how the online store performs. **Tab** (only rendered when `has_website`): four tiles — Store Views, Product Views, Web Orders (orders with `source = checkout`), Web Revenue (confirmed transactions on checkout orders or store invoices) — a 6-month **Web Orders** bar chart (ApexCharts), and a **Top Products by Views** list. The **standalone page** `/management/stores/{store}/web-metrics` (gated on `has_website`; otherwise redirect + error flash) adds a "Your storefront is live / Visit Store" card with the URL and a **Recent Activity** feed (last 10 `ActivityLog` entries for the store: description, time-ago, IP). Data comes from `StoreAnalyticsService@web`; `stores.views` and `products.views` are incremented by the storefront `Home\ProductController` on store/product detail hits.
- **Legacy:** `GET /management/stores/{store}/tab/{tab}` (`management.stores.tab`, tab `web-metrics`) → `Management\StoreTabController@webMetrics`; `GET /management/stores/{store}/web-metrics` (`management.stores.web-metrics`) → `Management\StoreDashboardController@webMetrics` · `resources/views/management/stores/tabs/web-metrics.blade.php`, `resources/views/management/stores/web-metrics.blade.php`
- **New API:** none — no endpoint in `routes/api/v1/management.php` exposes `views`, checkout orders or web revenue for a store.
- **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** all five metrics, both charts/lists, the activity feed and the visit-store card. The underlying data (columns, checkout source, transaction statuses) still exists in the new schema, so this is an API + one screen job.
- **Effort:** M · **Priority:** P2 (important for online sellers' weekly review, not blocking day-one trade).

## 8. Store branding & profile edit (logo, description, contacts, socials, slug)

- **What a business user could do:** edit the fields that make the storefront look like their brand: **Store Logo** (image, jpeg/png/jpg/webp ≤ 2 MB, old file deleted after successful save, new file cleaned up on failure — rendered in the storefront header, footer, sidebar and as favicon), **Description** (rendered as the storefront home subtitle), support email/phone, address, **social links** (Instagram, Facebook, X/Twitter, TikTok — rendered as icons in the storefront footer), and the **slug** (subdomain) which is normalised with `Str::slug`, de-duplicated, and blocked for reserved words (`ReservedStoreSlug`). Available from both the store-detail Settings tab and the standalone settings page; requires `stores edit`/`stores settings`.
- **Legacy:** `PUT /management/stores/{store}` (`management.stores.update`) → `Management\StoreSettingsController@update`; screens `GET /management/stores/{store}/settings` (`management.stores.settings`) and tab `settings` · `resources/views/management/stores/settings.blade.php`, `resources/views/management/stores/tabs/settings.blade.php`
- **New API:** none — no store update endpoint exists (`routes/api/v1/management.php` has only `GET stores`, `GET stores/{store}`). `Api\V1\Management\StoreController` reads `logo_url` but never writes.
- **New SPA:** none — no settings screen; `storesApi` in `src/api/endpoints.ts` exposes only `index`/`show`.
- **Status:** api **missing**, spa **missing**.
- **Missing:** the whole edit capability, the validations (image mimes/size, reserved slug, unique slug loop) and the media lifecycle (store/delete old logo on the `public` disk). This is the fastest way to make storefronts stop looking generic — the customer-facing SPA already renders `logo_url` and `description`, so the output half exists.
- **Effort:** M · **Priority:** P1.
- **Side effects (legacy):** logo file create/delete on the public disk; slug change changes the live storefront URL (breaks shared links — legacy allowed it silently).

---

# B. Platform admin (superadmin) — styling surface

These features were only reachable in `storify-admin`'s predecessor (`/office/*`). They are in scope for this domain because "banner/slide management" and "theme and colour choices" map to exactly these screens.

## 9. Page Styling CRUD (per-page background colour + custom CSS)

- **What an admin could do:** manage "Page Styling" records that colour the platform's storefront templates. List screen: table of #, Page Label, Page Name (identifier code), Background Color (swatch + hex), Status badge, edit/delete actions. Create/Edit form: `page_label` (human name), `page_name` (unique identifier, e.g. `product_details`, `home`, `checkout`), `background_color` (colour picker synced to a hex text input, max 7 chars), `custom_css` (free-text CSS textarea, "advanced users only"), `is_active` toggle. Delete behind a confirm modal. `PageStyling::getPageStyling()` caches the active record for 600 s and busts on save/delete.
- **Legacy:** resource `GET/POST /office/styling`, `GET /office/styling/create`, `GET /office/styling/{styling}/edit`, `PUT`, `DELETE` (`admin.styling.*`) → `Admin\PageStylingController` · `resources/views/admin/styling/{index,create,edit}.blade.php` · sidebar entry "Page Styling" · permission `admin.content`
- **New API:** none (`routes/api/v1/admin.php` has businesses/stores/users/transactions/coupons only; no `Api\V1\Admin\PageStylingController`).
- **New SPA:** none (no route/view in `storify-admin`; no nav entry).
- **Status:** api **missing**, spa **missing**.
- **Missing:** everything. **Judgement call:** the legacy feature is currently **inert** — `Home\ProductController` loads `PageStyling::getPageStyling('product_details')` and passes it to `storefront.pages.product-details` and `service-details`, but no Blade ever reads `$pageStyling` (grepped `resources/views/**`: zero usages), and the new storefront SPA has no styling hook at all. Rebuilding the admin CRUD alone would produce a screen that changes nothing. Either wire background colour + custom CSS into the renderer first, or defer both.
- **Effort:** S (CRUD) · **Priority:** P2 — build only together with the rendering consumer.

## 10. Storefront Slides management (per-store product carousel)

- **What an admin could do (from the admin store page, "Edit Slides"):** manage a per-store list of product slides. Table: #, Image (product primary image), Product (name, code, price), Status chip, Actions. **Add Slides** modal: debounced product search across name/code/slug with pagination (20/page) + "Load more", checkboxes with an "Already in slides" guard, a status select applied to the whole bulk, and "Add Selected". **Edit** modal: product autocomplete (search results show name/code/price, selected product links to its edit page), plus an active/inactive status select. **Delete** behind a confirm modal. **Drag-and-drop reorder** persists positions via AJAX. Product search is an AJAX endpoint returning `{data, current_page, last_page, per_page, total}` with primary image paths. Validation: slide always belongs to the store; product must exist.
- **Legacy:** `GET/POST /office/stores/{store}/storefront-slides` (`admin.storefront-slides.index/store`), `PUT/DELETE /office/stores/{store}/storefront-slides/{slide}` (`update/destroy`) → `Admin\StorefrontSlideController` · `resources/views/admin/storefront_slides/index.blade.php`; plus AJAX (still mounted via `routes/web.php` under session auth): `GET /api/superadmin/stores/{store}/products` (`api.admin.store-products.index`), `POST /api/superadmin/stores/{store}/storefront-slides/bulk` (`storefront-slides.bulk`), `POST /api/superadmin/stores/{store}/storefront-slides/reorder` (`storefront-slides.reorder`) · permission `admin.content`
- **New API:** none in the token API (`routes/api/v1/admin.php` has no slides routes; `admin_storefront.php` points at the legacy session-auth controller and is not used by the SPA, which is bearer-token against `/api/v1`).
- **New SPA:** none.
- **Status:** api **missing**, spa **missing**.
- **Missing:** the whole feature. **Obsolescence note:** the slides never rendered anywhere — no legacy storefront Blade references `StorefrontSlide` (only `StoreWipe`/`WarehouseDelete` clean the table), and the new storefront home instead serves `featured` products. The model's `title`/`description`/`price_override`/`image_path` columns were never exposed by any UI. So this is a dead-end feature: either drop it in favour of the featured-products mechanism (which itself needs a `featured` toggle in the products UI — the management API accepts `featured` but `ProductsView.vue` exposes no toggle), or rebuild slides end-to-end including rendering.
- **Effort:** M (admin CRUD + bulk + reorder) · **Priority:** P2.

## 11. Admin store branding edit (name, slug, description, logo, contacts, socials)

- **What an admin could do:** create and edit stores from the platform office, including the storefront-facing fields: name, **slug** (normalised, de-duplicated with random suffixes, reserved for the main store), description, **logo** (upload with preview; old logo deleted), support email/phone, address, social links. The admin store page also shows the logo, the website badge, and the **Edit Slides** button (entry to feature 10), and blocks suspending the main-homepage store.
- **Legacy:** `GET/POST /office/stores`, `GET/PUT /office/stores/{store}`, `DELETE`, suspend/activate → `Admin\StoreController` · `resources/views/admin/stores/{index,show}.blade.php`
- **New API:** read-only — `GET /api/v1/admin/stores`, `GET /api/v1/admin/stores/{store}` (`Api\V1\Admin\StoreController`); no update/create/delete.
- **New SPA:** `storify-admin/src/views/StoresView.vue` — list + detail drawer; shows the "Website" badge and copies store ID/slug; no write actions.
- **Status:** api **missing** (for the storefront-relevant writes), spa **missing**.
- **Missing:** store edit (name/slug/description/logo/contacts/socials), store create, the logo preview/upload lifecycle, and the slides entry point. The badge + slug surfaced in the SPA are useful groundwork.
- **Effort:** M · **Priority:** P2.

---

# C. What the customer-facing output actually renders (context for rebuilds)

**Legacy Blade storefront (`resources/views/storefront/**`):** `<title>` = store name; favicon = logo; header shows the store logo (or name); sidebar shows the logo; footer shows the logo + social icons (Facebook/Twitter/Instagram/TikTok, each `@if`-guarded); home page shows `<h2>{{ $store->name }}</h2>` and `<p>{{ $store->description }}</p>`; per-store pages/products/services with review stars and currency display. It renders **no slides, no theme colour, no custom CSS, no per-store banners** and an empty `<meta name="description">`. `$pageStyling` is passed but unused.

**New storefront SPA (`storify-storefront` + `GET /api/v1/storefront/{store}/home`):** the store payload carries `id, store_id, name, slug, description, address, support_email, support_phone, logo_url`; the page renders name + description as the catalog title/subtitle, and the home endpoint serves `featured_products` (products with `featured = true`) + categories instead of slides. So the *output pipeline* for logo/description already exists in the new stack — rebuilding feature 8 (branding edit) immediately shows results; slides have no pipeline at all.

---

# D. Gaps worth calling out

1. **The entire business storefront-configuration domain is absent from the new stack.** Of the eight business features, none has a working SPA screen, and only the read-only stores list has a partial API. A business on the new dashboards cannot: create a store, put a store online, brand it, see its web performance, or open its storefront URL. The new stack's only "storefront signal" is the `has_website` badge in the admin SPA.
2. **The brief's `views/management/styling/**` is an empty directory.** There was never a business styling UI; "styling" in legacy is admin Page Styling, which is a platform-wide, page-level colour/CSS injector — and it is **inert in the legacy renderer and has no consumer in the new stack**. Do not build the admin CRUD alone; build the render-side hook first or skip it.
3. **Theme/colour per store never existed.** The wizard's single "Basic" template was validated and discarded. A theme gallery would be new product scope, not parity — worth an explicit decision from the user, since "theme and colour choices" was named in the brief.
4. **Slides are a dead end in their current form.** Admin-managed product slides were never rendered by any storefront view in legacy, and the new storefront home uses `featured` products instead. Meanwhile no UI anywhere (legacy or new) lets a business mark a product featured — the new products API accepts `featured` but `ProductsView.vue` has no toggle. Pick one mechanism (featured flag is the smaller path) and expose it end-to-end.
5. **SEO parity is zero — and was zero in legacy.** No SEO columns, no meta management, empty description tags. Only slug-based URLs. If SEO fields are wanted, they are new scope; if not, the wizard's "SEO-friendly URLs" copy should stay marketing-only.
6. **"Disable storefront" doesn't exist in legacy.** Only enable. Stores go offline via suspend/delete (which also hides the store from customers). If a reversible per-storefront disable is wanted, it is new scope — and the legacy model has no column for it beyond `has_website`.
7. **"Preview" had no live preview.** Legacy preview = template card mock + "Visit Storefront/Visit Store" links opening the live subdomain. The new stack has neither (and no store-detail screen to host them). If a real preview is wanted, the storefront SPA's slug resolution would need a preview token/mode — new scope.
8. **Web metrics data still exists in the new schema** (`stores.views`, `products.views` incremented by the legacy storefront; `orders.source = 'checkout'`; `transactions.status = 'confirmed'`), so rebuilding feature 7 is query work, not a migration.
9. **Delivery knock-on:** storefront enablement/wizard also creates the nationwide `DeliveryRoute` ("All States", Nigeria, fee in kobo, days) that the storefront checkout charges. Preserve this in the rebuilt enable/create flows or online orders will have no shipping fee.
10. **Slug edits are destructive.** Both the business and admin flows allow changing the slug freely; the live storefront URL then 404s for every link already shared. Legacy had no redirect/alias handling; a rebuilt editor should at least warn (or freeze the slug once live).
