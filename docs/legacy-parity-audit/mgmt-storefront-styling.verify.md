# Verification pass — mgmt-storefront-styling (audience: business)

**Verifier method:** independently re-scanned the legacy storefront surface — `routes/v1/management.php` (store block lines 91–150), `routes/v1/admin_dashboard.php` (content group lines 155–167, store resource), `routes/web.php` + `routes/api/v1/admin_storefront.php` (slides AJAX), `App\Http\Controllers\Management\{StorefrontController,StoreController,StoreSettingsController,StoreDashboardController,StoreTabController}`, `App\Http\Controllers\Admin\{PageStylingController,StorefrontSlideController,StoreController,AdminSettingsController}`, `App\Actions\Stores\CreateStore`, `App\Http\Requests\Management\CreateStoreRequest`, `App\Services\StoreAnalyticsService`, `App\Models\{Store,PageStyling,StorefrontSlide,Setting}`; every in-scope Blade view file-by-file — `resources/views/management/stores/**` (17 files: create, index, settings, show, success, storefront/create, web-metrics, delivery-routes, payment-methods, tabs/{settings,products,orders,transactions,customers,invoices,staff,web-metrics}), `resources/views/admin/styling/**` (3), `resources/views/admin/storefront_slides/index.blade.php`, `resources/views/admin/stores/{index,show}.blade.php`, `resources/views/admin/advanced/settings.blade.php`, and the renderer `resources/views/storefront/**` (layout, header, footer, sidebar, cart, pages/{index,product-details,service-details,support,about,contact}); plus the new stack — `routes/api/v1/{management,admin,storefront}.php`, `Api\V1\Management\StoreController`, `Api\V1\Admin\StoreController`, `Api\V1\Storefront\CatalogController`, `storify-management` (`router/index.ts`, `endpoints.ts`, `views/StoresView.vue`) and `storify-admin` (`router/index.ts`, `endpoints.ts`, `views/StoresView.vue`), `storify-storefront` (`views/HomeView.vue`, `stores/storefront.ts`, `components/CatalogSection.vue`, `api/endpoints.ts`).

**Coverage verdict:** the inventory is substantively complete — every store-scoped and styling route maps to a feature entry (F1–F11), no `api_status: "missing"` hides an existing new endpoint, and the only `exists` claim (F1's stores index) is a real, non-stubbed endpoint. **No missed feature except one screen with storefront surface the audit never describes (below). Seven concrete corrections:** the legacy slug/social editing claim in F8 is wrong, the featured-toggle denial in D.4 is wrong twice over (legacy had it; the new storefront does not consume it), the blanket "no SEO fields anywhere" is wrong at platform level, F4's "refuses if already has a website" guard does not exist on the enable endpoint, and F11 invents a legacy website badge while missing the real legacy storefront signals on the admin list (Shop Link, Main badge). Details below; nothing else was invented.

---

## Missed feature

### A. Admin stores list — Shop Link, Main badge and filters — `partial` / `partial`

- **What the admin could do (legacy):** browse every platform store as a table — S/N, Name (+ logo, + "Main" badge when it is the `Setting::main_store_id` homepage store), Business (+ code, linked), Owner, Business Type, Status badge, **Shop Link** column — with URL filters for `status`, free-text `q` (name / `store_id` / owner name) and created `from`–`to`, 15/page; a "view shop" link in the Shop Link column opens the live customer storefront (`home.store.products.index` for the store's slug) in a new tab; a per-row actions menu (View, Edit modal prefilled with every storefront field, Suspend/Activate with reason, Delete behind confirm); an "Add Store" modal with the same storefront fields.
- **Legacy route:** `GET /office/stores` (`admin.stores.index`); `GET/POST/PUT/DELETE /office/stores...` for the modals
- **Legacy controller:** `Admin\StoreController@index` (with `mainStoreId`, filters, paginate 15)
- **Legacy views:** `resources/views/admin/stores/index.blade.php` (table L20–70, Shop Link L28/L66, Main badge L42–43, filters L415–433)
- **Key UI:** store table + filter bar + create/edit/suspend/activate/delete modals
- **Side effects:** `Log::info('stores_viewed')`; create/status-change emails (`AdminStoreCreated`, `StoreActivated`, `StoreSuspended`, `StoreReactivated`)
- **New API:** `GET /api/v1/admin/stores` — partial; supports `q`/`status`/sort/pagination, but returns no homepage-store marker and no explicit storefront URL (only `slug`).
- **New SPA:** `storify-admin/src/views/StoresView.vue`, route `/stores` — partial; has `q`/status filters and a Website badge, but no Shop Link column, no Main badge, no date filters.
- **Missing:** the storefront access link from the admin list, the homepage-store badge, and the created-date filter. (F11 describes the admin store page/forms; this list screen has no entry. "Main store" selection itself is in `admin-dashboard-config.md` §2.5.)
- **Effort:** S · **Priority:** P2

---

## Corrections

### 1. F8 — the slug is **not** editable from either settings screen; socials exist only on the store-detail tab

The audit: "…and the **slug** (subdomain) which is normalised with `Str::slug`… **Available from both the store-detail Settings tab and the standalone settings page**" (and, by implication, the social links too). Wrong. `grep 'name="slug"'` across `resources/views/management/**` returns **only** the hidden inputs in `create.blade.php` (L58), `storefront/create.blade.php` (L72) and `show.blade.php`'s enable modal (L388) — neither `settings.blade.php` nor `tabs/settings.blade.php` renders a slug field, and no form submits one. `StoreSettingsController@update` accepts an optional `slug` (L64) but nothing in the UI ever sends it, so a business user can only ever set the slug during create / enable-storefront / the storefront wizard. Social links: rendered only by `tabs/settings.blade.php` (L69–95); the standalone `settings.blade.php` has no social-links card at all. Rebuild scoping changes: slug editing is wizard-only parity, not settings parity.

### 2. F10 note / D.4 — "no UI anywhere (legacy or new) lets a business mark a product featured" is false

The legacy management product forms had a **Featured** toggle: `resources/views/management/products/create.blade.php` L176–180 ("Featured" checkbox), `resources/views/management/products/edit.blade.php` L410–413 ("Featured Product"), plus per-variant featured checkboxes in the variant editor (L287–289), and `management/products/show.blade.php` L159 displays the flag. `Management\ProductController` persists it on store/update (L277, L374). What is true is that the **new** management SPA dropped it — `mgmt-products.md` §5/§6 already inventories that loss. So the featured mechanism has full legacy parity precedent and is the natural replacement for slides (as D.4 suggests); re-state the claim as "legacy had it; the new SPA lost it".

### 3. §C / D.4 — the featured path is **not** just missing a toggle; the storefront never renders `featured_products`

`storify-storefront/src/stores/storefront.ts` reads only `data.data.store` and `data.data.categories` from `GET /api/v1/storefront/{store}/home`; `featured_products` is fetched but unused anywhere in the SPA (the only other occurrence of the word is the type declaration at `src/api/endpoints.ts` L58), and `views/HomeView.vue` renders a tabbed `CatalogSection` of the ordinary catalogue. Flipping `products.featured` therefore changes nothing a customer sees today. D.4's "featured flag is the smaller path — expose it end-to-end" understates the work: the render side (`HomeView`/`CatalogSection` must consume `featured_products` for a slides-like carousel) must be built alongside the management toggle.

### 4. Scope notes — "No SEO fields existed anywhere" is wrong at platform level

`settings.og_title/og_description/og_image_path/og_url/og_type` exist (migration `2025_10_24_201500_add_seo_fields_to_settings_table.php`), are editable via the admin Advanced Settings **"SEO Settings"** tab (`resources/views/admin/advanced/settings.blade.php` L251–323 → `Admin\AdminSettingsController@update`) and are rendered by the platform home (`resources/views/home/layout.blade.php` L7 via `home/components/seo.blade.php`). They are platform-wide (root-domain marketing site), not per-store: the per-store storefront statement stands (`storefront/layout.blade.php` keeps a hard-coded empty `<meta name="description">` with no OG tags). Already inventoried in `admin-dashboard-config.md` §2.6 — record this only as a scope-note precision fix, don't double-count it here.

### 5. F4 — `enableWebsite` does **not** refuse a store that already has a website

The audit: "Requires `stores settings` permission; **refuses if the store already has a website**." The `has_website` guard exists only in `StorefrontController@create` (L20) and `@store` (L44). `@enableWebsite` (L55–62) has no guard at all — the modal is merely hidden by `@unless($store->has_website)` in `show.blade.php` (L372), so a direct POST re-runs `enable()` (renaming/re-slugging) on an already-live store. Decide deliberately whether the rebuilt enable endpoint keeps the unguarded legacy behaviour or adds the guard.

### 6. F11 — legacy admin store page has **no** website badge; the real legacy storefront signals (Shop Link, Main badge) are on the admin list

The audit: "The admin store page also shows the logo, the **website badge**, and the Edit Slides button". The logo (L56) and Edit Slides (L24) are on `admin/stores/show.blade.php`; a website/`has_website` badge is not — `grep -i 'website\|has_website'` over `resources/views/admin/stores/` returns zero hits. The Website badge is a new-SPA element (`storify-admin/src/views/StoresView.vue` L108/138). Conversely, the legacy admin **list** does carry storefront signals the audit omits: the "Shop Link" column with a "view shop" link to the subdomain (index L28/L66) and the "Main" badge (L42–43) — see missed feature A.

### 7. F1 — the legacy list had no filter UI; the filters were URL-only

"Server-side filters: `status`, free-text `q` (name/store_id), created-date `from`/`to`; 10/page" reads as a capability the business used. `StoreController@index` does apply those query params, but `resources/views/management/stores/index.blade.php` (82 lines, read in full) renders no filter form at all — the `status`/`q`/`from`/`to`/`ownershipTypes`/`businessTypes` values it passes are never consumed. Re-phrase as "URL-only filters the Blade UI never surfaced" (same note as `mgmt-stores.verify.md` makes for the parallel screen).

---

## Smaller notes (no audit text change demanded)

- `Admin\StoreController@edit` returns `view('admin.stores.edit')`, but `resources/views/admin/stores/` contains only `index.blade.php` and `show.blade.php` — `GET /office/stores/{store}/edit` 500s in legacy; editing happens only via the inline modal. Do not port the dead route.
- The storefront `pages/about.blade.php` and `pages/contact.blade.php` are dead static Markit template files (hard-coded HTML, not extending `storefront.layout`, no controller renders them); only `support.blade.php` is live. Their `$store->whatsapp_number` / template copy is not a feature.
- F4's "refuses" aside, the audit's other StorefrontController claims check out: `template` validated (`required|in:basic`) and discarded; nationwide `DeliveryRoute` upsert with fee ×100 kobo and default 3 days; wizard redirects with info flash when `has_website`.
- F9's `PageStyling` description is accurate (fields, 600 s cache, save/delete bust — note a renamed `page_name` leaves the old cache key warm, harmless), and `$pageStyling` is confirmed inert in every view.
- F10's slides description is accurate (bulk add with "Already in slides" guard, status applied to the whole bulk, drag-reorder `order[]` → positions, paginated product search with primary images); the `title`/`description`/`price_override`/`image_path` columns remain unexposed by any UI and unrendered anywhere.
- `web_visits` on the management dashboard is a count of `has_website` stores (labelled "Web Visits", subtitle "Online stores"); it is inventoried in `mgmt-account-misc.md`, not missing here.
