# WS-30 repair — Services Catalogue

**Verdict:** verified clean on this pass. All seven repair checks pass; no file required an
in-place change, so nothing was edited or reformatted. One wiring item remains outside this
workstream's file ownership (nav coalescing — see *Unfixable* below).

Re-verified against the implementation reported complete:

| Piece | Path |
| --- | --- |
| API controller | `app/Http/Controllers/Api/V1/Management/ServiceController.php` |
| API meta controller | `app/Http/Controllers/Api/V1/Management/CatalogMetaController.php` |
| Route module | `routes/api/v1/management/ws30-services.php` |
| API test | `tests/Feature/Api/ws30servicesTest.php` (18 tests) |
| SPA view | `storify-management/src/views/ServicesView.vue` |
| SPA API module | `storify-management/src/api/modules/ws30-services.ts` |
| SPA router module | `storify-management/src/router/modules/ws30-services.ts` |
| SPA nav module | `storify-management/src/nav/modules/ws30-services.ts` |

## Checks

1. **Routes → existing controller methods, right signatures.** `ReflectionClass` confirms
   `ServiceController@{index,store,show,update,destroy}` (all public; `show/update/destroy` take
   `Request, Service`; `index/store` take `Request`) and `CatalogMetaController@currencies(Request)`.
   Implicit binding `{service}` → `App\Models\Service` uses the model's
   `getRouteKeyName() = service_code`, matching legacy. `Route::get('services', …)` is declared
   before `services/{service}`, so no shadowing.

2. **House envelope + tenant scoping.** Every action returns `$this->ok(...)` / `$this->error(...)`
   (no raw arrays); cross-tenant access aborts 403 exactly like the reference
   `WarehouseController`. Reads filter `whereIn('store_id', accessibleStoreIds)` fed by
   `user->accessibleStores()` minus `Store::STATUS_DELETED`; `store()`/`update()` validate the
   submitted `store_id` against that set (422 "Invalid store selection."); `show/update/destroy`
   re-check the bound service's store (and non-null `business_id`). Mutations run in
   `DB::transaction` (create + images, update + gallery, delete). `delete_image_ids` and
   `primary_image_id` resolve through `$service->images()`, so another service's image id is
   rejected/ignored, never acted on. Money is the legacy `decimal(12,2)` naira column and is only
   cast, never arithmetic.

3. **SPA calls ↔ registered routes ↔ api-module exports.** `ServicesView.vue` imports only
   `servicesApi`/`catalogMetaApi`; every call maps 1:1: `list` GET `/management/services` (params
   `page`, `per_page` 20, `q`, `status`, `store_id` — all accepted/validated by `index()`);
   `show` GET `/management/services/{serviceCode}`; `create` POST; `update` POST + `_method=PUT`
   multipart spoofing (Laravel honours it, and the spoofed PUT carries `permission:products
   edit`); `destroy` DELETE; `currencies` GET `/management/meta/currencies`. Response readers
   (`data.data.services`/`stores` + `meta`, `data.data.service.images[]`,
   `data.data.currencies`/`default_currency_id`) match the controller keys.

4. **Route-module shape / duplicate names.** `ws30-services.php` contains only `Route::` lines —
   no `prefix()`, no auth/audience/team wrapper (inherited from `routes/api/v1/management.php`);
   the per-verb `Route::middleware('permission:products …')` groups are the required gates and
   match sibling modules (`ws25`, `ws31`). An app-wide `route:list --json` scan (1107 routes)
   reports zero duplicate route names; `api.management.services.*` and
   `api.management.meta.currencies` are unique in the codebase.

5. **Router / nav module shapes.** Router module default-exports `RouteRecordRaw[]` with one child
   (`path: 'services'`, no leading slash, `meta: { title: 'Services', permission: 'products view' }`);
   the name `services` is unique across the shared router plus all 36 module files, and no module
   declares a child dynamic segment that could shadow `/services`. Nav module default-exports
   `NavGroup[]` with the exact `@/nav/types` shape (`label`, `icon`, `items[].{label,to,icon,permission}`).

6. **Imports resolve.** PHP: every imported symbol exists (`ApiController`,
   `ResolvesManagementContext`, `ActivityLog`, `Currency`, `Service`, `ServiceImage`, `Store`,
   `Rule`, `DB`, `Storage`, `UploadedFile`, `Collection`, `Request`, `JsonResponse`). The
   controller's private `accessibleStoreIds()` override of the trait method resolves to the class
   method (verified by a standalone PHP visibility probe — class member wins, no trait fatal), and
   the trait/`ApiController` duplicate `paginationMeta` are byte-identical. SPA: `@/api/client`
   exports `api`/`apiErrorMessage`; `AppModal` (`modelValue`, `title`, `maxWidth`),
   `PaginationBar` (`meta`, emits `page`), `StatusBadge` (`status`) all exist with compatible
   props; `useAuthStore` exposes `can()` and typed `stores`; `useUiStore` exposes
   `success()`/`error()`; `currencySymbol` is exported from `@/lib/money`; `AuthUser.stores[].id`
   types the `auth.stores[0]?.id` fallback in `openCreate`.

7. **PHP syntax / style.** `php -l` clean on both controllers, the route module and the test file;
   `vendor/bin/pint --test` on the same four files → `{"tool":"pint","result":"passed"}`.

## Fixes applied (in place)

None. Every check above passed without a defect to repair; no WS-30 file was modified.

## Notes (judgement calls, deliberately unchanged)

- **`amount` is legacy decimal-naira (`decimal(12,2)`), not kobo.** The services table, the public
  storefront payload (`CatalogController`) and the legacy web screens all read it as naira, so
  converting would break the storefront and legacy parity. The controller returns it cast and the
  SPA formats it from the currency relation (never a hardcoded ₦), which is what the roadmap
  asked for. Consistent with the sibling products column.
- **`authorizeService()` additionally 403s when a non-null `business_id` differs from the
  caller's.** Stricter than the reference `WarehouseController`, meaning a non-impersonating
  superadmin (`business_id = null`) could list but not open a service. The management audience
  makes that path practically unreachable; left as defence-in-depth.
- **`meta: { permission }` on the SPA route is inert today** — no router guard reads it; gating is
  by the nav filter and in-view `auth.can()`, matching `ws25`/`ws31`. Convention, not a defect.
- **`update()` treats an omitted `currency_id` as null** (full-replacement PUT). The SPA always
  sends the currency when the service has one, and the "Default" option intentionally clears it,
  so this matches the caller contract; legacy's `$request->only()` would have preserved it, but no
  UI path depends on that.

## Unfixable within this workstream's ownership

- **Two "Catalog" sidebar groups must be coalesced by the orchestrator.**
  `storify-management/src/layouts/AppLayout.vue` (owned by another workstream; WS-30 may not edit
  it) renders an inline `Catalog` group with "All Products"/"Categories", while
  `src/nav/modules/ws30-services.ts` exports its own `Catalog` group holding "Services". Until
  wired, the sidebar renders two "Catalog" headers. The module already carries the required
  MOUNT NOTE with the exact entry to fold in
  (`{ label: 'Services', to: '/services', icon: 'fi fi-rr-wrench', permission: 'products view' }`)
  or, alternatively, the orchestrator can delete AppLayout's inline group and let this module own
  all three entries (the comment lists them). Exporting `[]` from this module instead would drop
  the new destination from the nav entirely, so the mount note is the only correct
  in-ownership state. Route `/services` is registered and reachable regardless.

## Verification performed (this pass)

- `php -l` on `ServiceController.php`, `CatalogMetaController.php`, `ws30-services.php`,
  `ws30servicesTest.php` — no syntax errors.
- `php artisan route:list --path=api/v1/management` and `-v` — all six WS-30 routes registered
  with names `api.management.services.{index,store,show,update,destroy}` and
  `api.management.meta.currencies`, and middleware chain
  `auth:sanctum → token.audience:management → team.context → permission:products view|create|edit|delete`.
- `php artisan route:list --json` duplicate-name scan — 1107 routes, zero duplicate names.
- `ReflectionClass` probe of every routed method and its parameters; standalone PHP trait
  visibility probe for the `accessibleStoreIds` override.
- `vendor/bin/pint --test` on the four PHP files — passed.
- Test expectations cross-checked statically (the fleet forbids `php artisan test`):
  `createBusinessOwner` + `RefreshDatabase` setup, helper-function names unique against other
  test files, `activity_logs`/`service_images`/`services` columns and FK cascade, `Store`/`Service`
  boot hooks (slug + code generation), storefront route resolution by `Store.slug`, and
  `postJson`/`putJson` file extraction for the image assertions.
