# WS-25 — Products List, Bulk Actions & Detail — repair pass

Scope: verify the WS-25 implementation in place, fix what is broken, record what
cannot be fixed without touching a file this workstream does not own.

Verification performed (first pass, plus the continuation pass that fixed the two
test-file defects below): `php -l` on the controller, route module and test file;
`class_exists` autoload probe on the controller; `php artisan route:list
--path=api/v1/management/products --json` (and the full `--path=api/v1/management`
list); source review of every owned file against the models, services and shared
components it depends on; a field-by-field diff of the WS-25 row payload against
the WS-14 list payload it claims to supersede; a source read **and a runtime probe**
of the installed vue-router (5.3.1) matcher to confirm the duplicate-route-name
takeover its router module relies on; a live `storify_test` schema audit
(`@@sql_mode` and every NOT NULL / no-default column of each table the test
inserts into); a direct `AssertableJsonString` probe on the exact wire JSON the
`$this->ok()` envelope produces; and a Node `@vue/compiler-sfc` parse/compile +
`typescript` syntax + import-resolution walk over the five SPA files.
`php artisan test` / `npm run typecheck` were not run (the fleet shares one test
database and one toolchain).

---

## Fixed

### 1. `ProductDetail` TS type was missing two fields the detail view reads

`src/views/products/ProductDetailView.vue` reads `product.low_stock` (line 167,
Stock card accent) and `product.variant_count` (line 166, variant-count hint),
but `ProductDetail` in `src/api/modules/ws25-products-list.ts` declared neither —
so `vue-tsc --noEmit` would fail with two `TS2339` errors in the template.

The API is not the wrong side: `ProductFormController::show()` returns the
detailed payload built on the WS-14 base payload, and that base payload contains
both `low_stock` (bool) and `variant_count` (int|null) — they are also present in
the WS-25 list row type, which got them right. The type was fixed, not the view:

- `low_stock: boolean` added after `stock_level`
- `variant_count: number | null` added after `price_range`

### 2. Currency fallback diverged from the WS-14/WS-27 payload

`ProductListController::defaultCurrencyCode()` fell back
`business->currency ?: 'NGN'`, while `ProductFormController` (and every other
currency-aware management controller, e.g. `CatalogMetaController`) tries the
platform `Currency::where('is_default', true)` row first. For a product with no
`currency_id` on a business whose `currency` column is unset, the list and the
detail screen would have reported different currency codes for the same product —
breaking the module's documented "strict superset so existing consumers keep
working" contract. Aligned with the WS-14 fallback order (default row → business
code → `'NGN'`) and added the `App\Models\Currency` import.

### 3. Seven whole-naira test assertions compared floats against JSON integers
(continuation pass)

`tests/Feature/Api/ws25productslistTest.php` asserted `3000.0` / `5000.0` /
`2400.0` / `4000.0` (`assertJsonPath`, lines 110–113) and `2000.0` / `500.0` /
`1500.0` (`expect(…)->toBe(…)`, lines 132–134) against money the API emits as
whole naira. PHP drops the zero fraction — on this runtime (PHP 8.4.25,
`serialize_precision=-1`) `json_encode(3000.0) === "3000"` — and neither Laravel's
`JsonResponse` (encoding options `0`) nor `ApiController::ok()` sets
`JSON_PRESERVE_ZERO_FRACTION`. `assertJsonPath` compares with
`AssertableJsonString::assertPath` → `PHPUnit::assertSame`, and Pest's `toBe` is
`assertSame` too, so every one of those assertions was guaranteed to fail against
the decoded integer. Proven directly this pass: a JSON payload
`{"price_range":{"min":3000}}` fails `assertPath(…, 3000.0)` and passes
`assertPath(…, 3000)`.

Fixed in `tests/Feature/Api/ws25productslistTest.php` by asserting the integers
the API actually emits (values unchanged) plus a header "money note" recording the
rule — the same repair the ws21 invoice and ws03 store-detail passes applied, and
the same convention `StorefrontApiTest` already uses.

### 4. The category fixture could never insert: `categories.slug` is NOT NULL
(continuation pass)

Test 1's fixture built its category with
`Category::create(['business_id', 'store_id', 'name'])` — but `categories.slug`
is `varchar(255) NOT NULL` with no default and the `Category` model has no
`creating` hook (confirmed against the live `storify_test` schema and
`app/Models/Category.php`). The test database runs `STRICT_TRANS_TABLES`, so the
insert would have died with error 1364 ("Field 'slug' doesn't have a default
value") before a single assertion ran.

Fixed by setting `'slug' => 'boots'` in the fixture, with a comment. The sibling
convention already does this: `ws31Category`, `ad15Category` and the ws03 store
detail fixture all set `slug`, and ws03's report records the same NOT NULL trap.

---

## Verified clean (no change needed)

**Routes → controller methods.** All four registrations in
`routes/api/v1/management/ws25-products-list.php` resolve to methods that exist
with matching signatures:

| Method | URI | Handler |
| --- | --- | --- |
| GET | `products` | `ProductListController@index` |
| POST | `products/bulk-update` | `ProductListController@bulkUpdate` |
| POST | `products/bulk-status` | `ProductListController@bulkStatus` |
| POST | `products/bulk-delete` | `ProductListController@bulkDelete` |

**The same-URI takeover works.** `routes/api/v1/management.php` registers the
thin `ProductController` list route inline and globs the module directory at the
end of the group, so modules load afterwards; the glob is alphabetical
(`ws14-product-form.php` before `ws25-products-list.php`). Laravel's
`RouteCollection::addToCollections()` keys routes by method+domain+URI and
`route:list --json` confirms the last registration wins:

```
GET api/v1/management/products  api.management.products.index
  -> App\Http\Controllers\Api\V1\Management\ProductListController@index
```

The WS-14 repair report (`build-ws14-product-form.repair.md`) records the same
resolution from the other side.

**Envelope and tenant scoping.** All four methods return `$this->ok(...)` (no
error paths were needed — validation failures are thrown as 422 by
`$request->validate()`). Reads and writes all go through `accessibleProductQuery()`,
which scopes on `business_id`, then on the caller's non-deleted accessible stores,
then on accessible warehouses for store-less rows, with fully detached rows
excluded for restricted staff. `bulkStatus` re-applies the business scope on the
mass update after reading ids through the access scope, and `bulkDelete` deletes
only the rows fetched through it. `bulkUpdate`/`bulkDelete` run inside
`DB::transaction`; `bulkStatus`'s single mass update is wrapped too.

**Middleware.** `route:list --json` shows the bulk routes carrying exactly the
intended gates on top of the inherited auth/audience/team stack:

```
bulk-update / bulk-status -> Spatie ...PermissionMiddleware:products edit
bulk-delete               -> Spatie ...PermissionMiddleware:products delete
products                  -> ...PermissionMiddleware:products view
```

**Route module hygiene.** No prefix/name/middleware wrapper around the file —
only the per-route `permission:` groups (the same shape as
`ws14-product-form.php` and every other module). No new route names collide:
`products.index` duplicates the WS-14 module and the parent file deliberately
(same URI, same name, latest wins — documented in all three places), and
`products.bulk-update` / `bulk-status` / `bulk-delete` appear only in this module
under the `api.management.` name space. The legacy Blade file
`routes/v1/management.php` names its look-alikes `management.products.*` on a
different URI space (`/management/...` under the `web` guard), so there is no
clash.

**SPA router module.** Default-exports `RouteRecordRaw[]`; paths are relative
(`products`, `products/:code`) with `meta.title` on both; no leading slash. The
duplicate `products` name is safe: the records are spread into the `/` children
array after the inline record, and the installed vue-router 5.3.1 matcher
explicitly calls `removeRoute(record.name)` before inserting a new root record
with an existing name (`node_modules/vue-router/dist/vue-router.js`, `addRoute`).
Confirmed at runtime this pass with a `createMemoryHistory` probe over the same
route shapes: `/products` resolves to the module record (`ProductsListView`), the
old shared record disappears from `getRoutes()`, and `products/create` /
`products/:code/edit` (WS-14) still outrank `products/:code`. No other module
declares `products` or `products.show`.

**Nav module.** Default-exports `NavGroup[]` (empty by design): `AppLayout.vue`
already renders the Catalog → "All Products" entry at `/products` with
`products view`, and the detail route is covered by that item — the same
empty-module choice WS-14 made.

**SPA ↔ API contract.** Every endpoint the two views call exists and is exported
by the api module:

| SPA call | Route | Status |
| --- | --- | --- |
| `productListApi.index` | GET `/management/products` | WS-25 (this module) |
| `productListApi.options` | GET `/management/products/form/options` | WS-14 route, exists |
| `productListApi.detail` | GET `/management/products/{code}` | WS-14 route, exists |
| `productListApi.updateStatus` | PUT `/management/products/{code}/status` | WS-14 route, exists |
| `productListApi.destroy` | DELETE `/management/products/{code}` | WS-14 route, exists |
| `bulkUpdate` / `bulkStatus` / `bulkDelete` | POST `products/bulk-*` | WS-25, this module |

The response shapes match the views' destructuring (`data.data` rows +
`data.meta` on the list; `data.data.product` on the detail). The row payload is a
strict superset of the WS-14 list payload — a key-set diff of the two
`return [...]` arrays found exactly one addition (`display_price_range`) and no
removals — so WS-27's `storeTabsApi.products`, which reuses this endpoint with
`store_id`, keeps every field its `StoreTabProduct` type reads (`product_code`,
`image_url`, `section_name`, `currency_code`, `amount`, `price_range`,
`quantity`, `low_stock`, `is_digital`, `status`, all present).

**Imports resolve.** Models (`Product`, `ProductImage`, `Store`, now `Currency`),
`ProductFileService` (`deleteAllFiles` exists and deletes from each file's own
disk), `ResolvesManagementContext` (`user`), `ApiController`
(`ok`/`paginationMeta`); SPA side `@/api/client`, `@/lib/money`
(`currencySymbol`, `formatDate`), `@/stores/ui` (`success`/`error`/`info`),
`@/stores/auth` (`can`), `StatusBadge`, `PaginationBar`, `AppModal`, `StatCard`
— all verified to export/accept exactly what the views use. `:indeterminate` is a
real `InputHTMLAttributes` member in the installed Vue, so it typechecks.

**Tests.** Helper names (`ws25Token`, `ws25Store`, `ws25Warehouse`, `ws25Section`,
`ws25Product`, `ws25Currency`) are unique across `tests/` — no Pest file-scope
collision — and match the sibling-workstream idiom (`ws14Token` etc.). Every
model/hook the test file leans on exists: product code/slug auto-generation,
`ProductVariant` code auto-generation, `Section` code auto-generation, the
`Product::$saving` validator that refuses a zero quantity on a store product
(which is what makes the "rejected row" test meaningful), the `managing_director`
role's view-only products permission (403s) and `store_manager`'s full products
permissions plus the `assignedStores` pivot path for restricted staff 200/`skipped`
behaviour.

The continuation pass then audited every fixture insert against the live
`storify_test` schema (NOT NULL / no-default columns of `stores`, `warehouses`,
`sections`, `products`, `product_variants`, `product_images`, `product_files`,
`currencies`, `businesses`, `users`, `staff_assignments`) and every numeric
expectation against the exact wire format, which is how fixes 3 and 4 were found.
After them: every insert satisfies the schema (auto-generated codes cover
`store_id`, `warehouse_code`, `section_code`, `product_code`/`slug`,
`variant_code`), every money expectation matches the JSON integer the API emits,
and the file lints clean.

**PHP syntax.** `php -l` clean on all three PHP files; the controller autoloads
(`class_exists` → true) after the edits.

---

## Notes — real, but not this workstream's to change

1. **The URI takeover depends on load order.** Three files register
   `GET products`; it resolves to WS-25 today only because the module glob runs
   after the shared file and sorts `ws14 < ws25`. A rename/reorder could silently
   change which handler serves the list. Shared files, not touched.

2. **`src/views/ProductsView.vue` is dead code** — WS-25 re-registered the
   `products` route name to `views/products/ProductsListView.vue`; already noted
   in the WS-14 repair report and in the router module's header comment.

3. **The detail screen does not refetch when only `:code` changes** (no
   `watch(route.params)`), which matches `WarehouseDetailView`/`OrderDetailView`
   — nothing in the app currently navigates detail→detail, so this is an
   observation, not a defect.

4. **`tests/Feature/Api/ws14productformTest.php` has the same
   `categories.slug` fixture bug this pass fixed here** — three
   `Category::create([...])` calls without `slug` (lines 79, 464, 561) hit a NOT
   NULL / no-default column under `STRICT_TRANS_TABLES`, so those three WS-14
   tests cannot insert their fixtures. The file belongs to the WS-14 workstream
   (another agent owns it), so it was not touched; recorded for the orchestrator
   / the WS-14 repair pass. The fix is the one applied here: pass a `slug`.

---

## Unfixable

None of this workstream's own files. One genuine defect was found in a file this
workstream does not own, so it is recorded rather than patched:

- `tests/Feature/Api/ws14productformTest.php` lines 79, 464 and 561 call
  `Category::create([...])` without `slug`; `categories.slug` is
  `varchar(255) NOT NULL` with no default and no model hook, under
  `STRICT_TRANS_TABLES` — those three tests cannot insert their fixtures. The
  file is WS-14's (another agent's), so it was left untouched; the fix is the one
  applied here (pass a `slug`). See note 4.
