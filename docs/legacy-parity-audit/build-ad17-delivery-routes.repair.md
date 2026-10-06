# AD-17 / WS-17 — Delivery routes (platform-wide) — repair pass

Verified against the actual files (not the completion report). Workstream files:

- API: `app/Http/Controllers/Api/V1/Admin/DeliveryRouteController.php`,
  `routes/api/v1/admin/ad17-delivery-routes.php`
- Admin SPA: `src/views/DeliveryRoutesView.vue`,
  `src/api/modules/ad17-delivery-routes.ts`,
  `src/router/modules/ad17-delivery-routes.ts`,
  `src/nav/modules/ad17-delivery-routes.ts`
- Test: `tests/Feature/Api/ad17deliveryroutesTest.php`

## Verdict

All seven checklist items pass after one in-scope repair. Every route resolves to an existing
controller method with the right signature, every method returns the house envelope under the
platform-admin guard + `store_id IS NULL` scope, and every SPA call matches a registered route
and an exported module function. One thread of the roadmap acceptance criteria ("disabled routes
disappear from checkout") cannot be landed from this workstream because its reader is in files
owned by other workstreams — recorded under Unfixable with the exact files.

## Fixed

1. **`areasByState()` in `DeliveryRouteController` was a `str_replace` no-op, so the FCT/Abuja
   never received area suggestions — and the workstream's own lookups test would have failed.**
   The code did `str_replace(['—', '–'], ['–', '—'], $state)` to bridge the spelling gap between
   `Nigeria::states()` (`'FCT — Abuja'`, U+2014 em dash) and `Nigeria::citiesByState()`
   (`'FCT – Abuja'`, U+2013 en dash). PHP applies array replacements left to right over the
   result of the previous replacement, so em→en was immediately followed by en→em and the
   function returned its input unchanged; `citiesByState()` then returned `[]` and
   `areas_by_state` had no FCT key at all. Verified by executing the helper standalone:
   before the fix the swap was a no-op and `areasByState` produced 9 keys with no FCT; after the
   fix it produces `FCT — Abuja` with `Garki` present (also Lagos/Lekki, needed by the test).
   The swap is now one-way (`str_contains($state, '—') ? str_replace('—', '–', $state) :
   str_replace('–', '—', $state)`) with a comment explaining why a two-entry `str_replace` is
   wrong here. This is what makes the test's FCT assertion pass.

## Verified, no change needed

1. **Route → method signatures.** All six routes point at existing methods: `index`, `lookups`,
   `store`, `update(Request, DeliveryRoute $deliveryRoute)`, `toggle(Request, DeliveryRoute
   $deliveryRoute)`, `destroy(Request, DeliveryRoute $deliveryRoute)`. `{deliveryRoute}` matches
   the method parameter name, so implicit route-model binding resolves.
2. **Envelope + scope.** Every method returns `$this->ok(...)` / `$this->error(...)` from
   `ApiController` (the index passes `paginationMeta`). All queries are restricted to platform
   rows (`whereNull('store_id')`); `guardPlatformRoute()` 404s a store-scoped id for
   update/toggle/delete, so tenant rows are unreachable from this screen. `EnsuresPlatformAdmin`
   guards every method (the `permission:admin.delivery` gate alone is not enough — business
   "Super Admin" roles carry the `admin.*` names).
3. **SPA ↔ API contract.** `DeliveryRoutesView.vue` calls `deliveryRoutesApi.list/lookups/create/
   update/toggle/remove`; all six are exported by `src/api/modules/ad17-delivery-routes.ts` and
   match the six routes exactly (`GET list`, `GET lookups`, `POST`, `PUT {id}`, `POST {id}/toggle`,
   `DELETE {id}`). Envelope shapes line up (`data.routes`, `data.summary`, top-level `meta`).
   `useDataTable` always sends `q`/`status` as empty strings when unset; the global
   `ConvertEmptyStringsToNull` middleware turns them into nulls, so the `nullable`/`Rule::in`
   validation accepts them (verified in the framework's `getGlobalMiddleware()`, which
   `bootstrap/app.php` does not override).
4. **Route module shape.** `routes/api/v1/admin/ad17-delivery-routes.php` has no prefix or
   auth/audience/team wrapper — only the `permission:admin.delivery` + `AdminApiActivityLogger`
   group (the same idiom as `ad01`/`ad05`). A whole-app duplicate-name scan
   (`route:list --json` + count) reports **no duplicate route names anywhere**, and
   `php artisan route:list --path=api/v1/admin/delivery-routes` shows exactly the six expected
   `api.admin.delivery-routes.*` routes.
5. **SPA registration modules.** The router module default-exports a `RouteRecordRaw[]` with one
   child of `/` (`settings/delivery-routes`, no leading slash, `meta.title`) — checked against
   `router/index.ts`'s `./modules/*.ts` glob; no other module claims that path or name (the shared
   `settings/:tab?` route is a param route, so the static segment outranks it). The nav module
   default-exports `[{ label, nodes }]` with a node of the shape `AdminLayout.vue` globs
   (`label`/`icon`/`to`/`permission`), matching the ad02/ad04 siblings; its top-of-file comment
   hands the Settings-section merge to the orchestrator.
6. **Imports.** PHP: controller (`Nigeria`, `EnsuresPlatformAdmin`, `ApiController`,
   `DeliveryAddress`, `DeliveryRoute`, `Order`, `OrderDelivery`, `Log`, `Rule`) and route file
   (`DeliveryRouteController`, `AdminApiActivityLogger`) all resolve, no unused imports. SPA:
   `@/composables/useDataTable`, `@/stores/ui` (`success`/`error` present), `@/lib/format`
   (`formatKobo` present), and all six components (`AppModal` props `modelValue`/`title`/
   `maxWidth`; `ConfirmDialog` `modelValue`/`title`/`message`/`confirmText`/`danger`; `EmptyState`
   `icon`/`title`/`description` + `action` slot; `StatCard` `label`/`value`/`hint`/`accent`/`icon`;
   `TableFooter` `meta`/`perPage`/`pageSizes`; `TableSkeleton` `rows`/`cols`) exist with matching
   prop names. API base URL already ends in `/api/v1`, so the module's `/admin/...` paths hit the
   registered routes.
7. **Syntax/format.** `php -l` clean on all three PHP files; `vendor/bin/pint --test` passes on
   all three. (Per the fleet rule, `php artisan test` and `npm run typecheck` were not run.)

Test file static review: helpers mirror the working `ad05` convention (superadmin via
`Gate::before`, `Platform Admin` role for the permission gate); the models/migrations it touches
(`orders.delivery_route_id` + SoftDeletes, `order_deliveries.delivery_route_id`,
`delivery_addresses.delivery_route_id`, `carts` FK `nullOnDelete`) all exist, and the FCT fix
above is required for its lookups assertion to pass.

## Unfixable (outside WS-17 file ownership)

**The consumer side of the blocking scope decision — store-first with global fallback — is not
landed, so "disabled routes disappear from checkout" is not yet observable and a fresh install
still shows zero delivery options.** Admin rows are correctly platform-wide (`store_id NULL`) and
ready to be the fallback, but every new-stack consumer filters strictly by `store_id`:

- `app/Http/Controllers/Api/V1/Storefront/CatalogController@deliveryRoutes` (the storefront
  reader, registered at `routes/api/v1/storefront.php:37`) — `where('store_id', $store->id)`.
- `app/Actions/Checkout/PlaceStorefrontOrder.php:153` — resolves the submitted route with
  `where('store_id', $store->id)->find($routeId)`, so even if the reader offered a global route
  checkout would silently drop it and charge no shipping.

Both are existing files this workstream does not own (the prompt forbids editing existing
controllers, and `routes/api/v1/storefront.php` / `routes/api.php` are shared files). It also
cannot be re-registered from a new file: `routes/api.php` does `require
__DIR__.'/api/v1/storefront.php'` directly — storefront routes are not module-globbed the way
management/admin are (verified by reading `routes/api.php`; only `management/*.php` and
`admin/*.php` are glob-loaded by their parent files, and a later duplicate registration would
not take precedence anyway). Fix ownership: whichever workstream owns storefront/checkout
(WS-05 storefront-enable territory) needs to add the fallback in one shared resolver, e.g.
store routes first, then `store_id IS NULL` active platform defaults, and use it in both
`CatalogController@deliveryRoutes` and `PlaceStorefrontOrder`.
