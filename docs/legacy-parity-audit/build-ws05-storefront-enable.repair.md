# WS-05 repair — Storefront Enablement & Branding

**Verdict:** repair complete. All seven verification checks pass on the current tree.
One display defect found and fixed in place (empty slug suggestion); no API defects found.

## Files verified

| Piece | Path |
| --- | --- |
| API controller | `/Users/mac/Desktop/my_files/work/storify/storify-api/app/Http/Controllers/Api/V1/Management/StorefrontController.php` |
| Route module | `/Users/mac/Desktop/my_files/work/storify/storify-api/routes/api/v1/management/ws05-storefront-enable.php` |
| API test | `/Users/mac/Desktop/my_files/work/storify/storify-api/tests/Feature/Api/ws05storefrontenableTest.php` (26 Pest tests) |
| SPA API client | `/Users/mac/Desktop/my_files/work/storify/storify-management/src/api/modules/ws05-storefront-enable.ts` |
| SPA router module | `/Users/mac/Desktop/my_files/work/storify/storify-management/src/router/modules/ws05-storefront-enable.ts` |
| SPA nav module | `/Users/mac/Desktop/my_files/work/storify/storify-management/src/nav/modules/ws05-storefront-enable.ts` |
| SPA views | `/Users/mac/Desktop/my_files/work/storify/storify-management/src/views/StorefrontView.vue`, `StorefrontCreateView.vue` |
| SPA components | `/Users/mac/Desktop/my_files/work/storify/storify-management/src/components/StorefrontEnableModal.vue`, `StorefrontStatusCard.vue` |

## Checks

1. **Routes → controller methods.** All five route targets exist with matching signatures:
   `checkSlug(Request)`, `index(Request)`, `show(Request, Store)`, `enableWebsite(Request, Store)`,
   `store(Request, Store)`. Verified by booting the router and reflecting over the class; `{store}` binds
   via `Store::getRouteKeyName()` = `store_id`. No two-segment `POST stores/{store}` exists in any module,
   so `POST stores/check-slug` cannot be shadowed by a model-bind route.
2. **Envelope + tenant scoping.** Every public method ends in `$this->ok(...)` / `$this->error(...)`
   (`index` passes `paginationMeta($stores)` as the fourth `ok()` argument). The listing is built from
   `accessibleStores()` and excludes `STATUS_DELETED`; `show`/`enable` run `authorizeStorefront()`
   (business_id + `accessibleStores()`), which 404s deleted stores; the enable mutation and the nationwide
   delivery upsert share one `DB::transaction`; `checkSlug` resolves `ignore_store` through
   `accessibleStores()` so a caller can never ignore another tenant's store. `authorizeStore` / `abort(403)`
   is the shared `ResolvesManagementContext` trait used by 62 management controllers — house pattern.
3. **SPA calls ↔ routes ↔ api module.** `index`, `show`, `checkSlug`, `enable`, `create` map one-to-one onto
   the five registered URIs (`/management/storefront/stores`, `/…/{store}`, `/management/stores/check-slug`,
   `/management/stores/{store}/enable-website`, `/management/stores/{store}/storefront`), all exported by
   `storefrontApi` in the WS-05 api module. The WS-02 store-create screen consumes the same shared slug
   endpoint and both read the `{data: {available, slug, url, original}}` envelope.
4. **Route module shape / duplicate names.** No prefix and no inherited-middleware wrapper — only the
   controller import, route-level `permission:stores view` / `permission:stores settings` gates and the
   documented ungated `check-slug` (shared with store create; legacy parity). App-wide scan of all
   **1107 registered routes found 0 duplicate names**; the only duplicate method+URI pairs are pre-existing
   public web routes (`GET /`, `GET services`, `GET support`), unrelated to WS-05. Route middleware confirmed
   via `route:list --json`: auth:sanctum + audience + team + the expected permission.
5. **Router / nav modules.** Router module default-exports `RouteRecordRaw[]` with child paths
   (`storefront`, `stores/:id/storefront/create`) — no leading slashes, `meta.title` on both, names unique
   across `src/router/`. Nav module default-exports `NavGroup[]` in `@/nav/types` shape; AppLayout's
   `../nav/modules/*.ts` glob picks it up and filters by `permission: stores view`. Group label `Online`
   is unique.
6. **Imports resolve.** Machine-checked every static import in the seven SPA files and every `use` in the
   three PHP files — all targets exist (ApiController, `ResolvesManagementContext`, models, `ReservedStoreSlug`,
   `config/storefront.php`, SFCs, stores, `mainDomain`, `apiErrorMessage`, `useAuthStore.can`, `useUiStore`).
   The dashboard tab (`StoreDashboardTab.vue`) mounts `StorefrontEnableModal` with a compatible payload
   (`dashboard.store: StoreDetailStore` satisfies the modal's `StoreLike`).
7. **PHP syntax.** `php -l` clean on the controller, route module and test file.

## Fix applied

- **`StorefrontCreateView.vue` and `StorefrontEnableModal.vue`** — for input that slugs to nothing
  (e.g. `!!!`), `POST stores/check-slug` correctly answers `available:false` but suggests an empty slug,
  so both forms rendered the hint *"Taken — we suggest "* with nothing after it. The "Taken — we suggest X"
  branch now renders only when a suggestion exists, and a plain "Use letters, numbers and hyphens —
  e.g. ada-fashions." hint covers the unusable-input case. Submit was already refused by validation with a
  proper slug error; this only fixes the misleading hint. Both SFCs recompiled clean with the local
  `@vue/compiler-sfc`.

## Notes (no change made)

- `StorefrontStatusCard.vue` is unmounted by design: `StoreDashboardTab.vue` (inside the store-detail
  shell) already renders the equivalent Web Storefront card with Live badge, URL, Visit Store and the
  enable modal, so mounting the standalone card there would duplicate the surface. The file carries a
  top-of-file mount instruction; per the file-ownership rule the orchestrator wires any extra surface.
- `POST stores/check-slug` carries no permission gate on purpose (documented in the route module):
  it backs store create (WS-02/WS-03) as well as enable/wizard, runs before a store exists, and is
  authenticated + throttled. `ignore_store` is tenant-scoped, so it leaks nothing across businesses.
- The implementation note said "20 Pest tests"; the file contains **26**.
- `stores.slug` has a DB unique index, so the validate-then-write race is backstopped by the database
  (a lost race surfaces as a 500, not a duplicate slug) — matches the rest of the codebase.

## Verification performed

- `php -l` on the controller, route module and test — no syntax errors.
- `php artisan route:list --path=api/v1/management` — all five WS-05 routes register with the expected
  names, URIs and middleware.
- Full-app route scan (`route:list --json`, 1107 routes) for duplicate names / URIs.
- Router + class reflection script confirming every route target method exists.
- Import-resolution script over the seven SPA files; `@vue/compiler-sfc` parse + `compileScript` +
  `compileTemplate` on the four SFCs; local TypeScript `transpileModule` on the three TS modules.
- Cross-checked the 26 Pest tests against controller/helper/seed behaviour (tenant scoping, kobo
  conversion, statuses, permission roles) — no mismatches found.
- Per the brief, the fleet-shared `php artisan test` and `npm run typecheck` were **not** run.
