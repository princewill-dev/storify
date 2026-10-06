# Verification pass — mgmt-products (audience: business)

**Verifier method:** re-read all four legacy Blade views (`resources/views/management/products/{index,create,edit,show}.blade.php`), every product route in `routes/v1/management.php`, all 11 public methods of `Management\ProductController`, `ProductRequest`, `StoreTabController::products`, the warehouse/section entry points that call `ProductController`, the new API (`routes/api/v1/management.php`, `Api/V1/Management/ProductController`) and the SPA (`ProductsView.vue`, `router/index.ts`, `endpoints.ts`).

**Coverage verdict:** the audit is substantively complete within its scope. Every product view file, product route and `ProductController` method is accounted for; no whole feature was omitted. The warehouse Products tab (cards, per-card menu, client-side search, bulk delete) is a product surface the products audit does not describe, but it is already covered by `mgmt-inventory.md` §1.6/§1.7, and the section-detail product list by `mgmt-catalog-org.md` §12 — no duplicate work needed. Remaining findings are corrections of specific claims, below.

---

## Corrections

### 1. #7 Product images — first upload does NOT become primary in the new API (audit says "nothing structural")

`Api\V1\Management\ProductController::syncImages()` (lines 233–248):

```php
$position = (int) $product->images()->max('position');   // null -> 0 on a fresh product
$position = $position < 0 ? 0 : $position + 1;           // 0 < 0 is false -> position = 1
...
'is_primary' => ! $hasPrimary && $position === 0,        // false && ... -> never true
```

With no images, `max(position)` is null → `(int)` 0 → `$position` becomes **1**, so the `$position === 0` half of the condition is unreachable and **every uploaded image is stored with `is_primary = false`** (verified with `php -r`; also note the migration default/unique index on `(product_id, position)`). Legacy `ProductController@store` set the first upload primary (`'is_primary' => $pos === 0`, `$pos` starting at 0). Consequence: `show` returns `images[].is_primary = false` for everything the SPA uploads (the SPA never sends `primary_image_id`), and any future "set primary" gallery will never see an existing selection. The audit's #5 already lists "first upload becomes primary only" as missing, but #7 asserts the opposite ("Uploads, delete_image_ids, primary_image_id all implemented … nothing structural"); the two entries contradict each other and #7 is wrong. `Product::primaryImage()` masks it in list thumbnails only because it falls back to `images->first()`.

### 2. #6 Variants — turning `has_variants` off does not delete variants in the new API

`update()` calls `syncVariants($request, $product, replace: $request->filled('variants'))`, but `syncVariants()` starts with:

```php
if (! $request->filled('variants')) { return; }
```

An update with `has_variants=0` and no `variants` key leaves the existing `product_variants` rows in place (they are returned by `show` forever, with `has_variants=false`). Legacy `ProductController@update` ran `ProductVariant::where('product_id', …)->delete()` whenever the flag was off. The audit documents legacy's "disabling variants deletes them" but lists only id-churn/replace under the new API's partial status, not this orphan-row gap.

### 3. #5 Create/edit — new API `update()` performs no accessible-store check on `store_id` (legacy rejected it)

New rules: `'store_id' => [$forUpdate ? 'sometimes' : 'required', 'integer']` and `update()` passes `$data` straight to `$product->update($data)`. `authorizeProduct()` only checks the product's *current* store, and the `ResolvesManagementContext` trait has no request-level store guard, so a product can be moved to any store id (not even `exists`-validated, nor `warehouse_id`/`section_id`/`category_id`). Legacy `update()` returned "Invalid store selection." for a store outside `accessibleStores()`. `store()` in the new controller *does* check membership — the update path is the asymmetric hole. Worth adding to #5's missing-validation list alongside the missing `size`/`weight`/`currency_id` rules.

### 4. #5 Create/edit — the SPA missing-fields list omits initial stock (`stock_quantity`)

`ProductsView.vue` renders/binds only `quantity` (Stock quantity, line 308) and `save()` never appends `stock_quantity` or `stock_price`… accordingly. Legacy's create and edit forms both had a distinct **Initial Stock** field, and the edit form printed the derived "Sold: N unit(s)" hint (`soldQuantity() = stock_quantity − quantity`). With `stock_quantity` always null from the SPA, sold/stock-percentage math can never populate for SPA-created products, and the audit's SPA field list should say so.

### 5. #1 Product list — the "Missing vs legacy" SPA list includes filters legacy never had

Audit: "Missing vs legacy: category filter, warehouse filter, … per-page selector". The legacy *index UI* had exactly three controls (q, status, store) plus Clear; `ProductController@index` supported `from`/`to`/`per_page` server-side but no category or warehouse filter existed anywhere in legacy (the new API actually **adds** `category_id`, `warehouse_id`, `digital_only`). Only the store tab had a per-page selector. These three items should be re-scoped as net-new conveniences, not parity losses; the genuine UI regressions in that list are the variant price range/discount display, Store/Section/Source columns, low-stock highlight, row selection, name→detail link, and data-driven currency formatting.

### 6. #1/#9 Scoping — legacy warehouse-only products (store_id NULL) are unmanageable in the new stack

The audit's quirk #140 notes the SPA "does not support warehouse-only products", but not the consequence for existing data: new `index()` filters `whereIn('store_id', $accessibleStoreIds)` and `show()`/`update()`/`destroy()` call `authorizeProduct()`, which does `accessibleStores()->whereKey($product->store_id)->exists()` — both exclude and 403 any product with `store_id = NULL`. Legacy's create form had no store selector at all, so **store-less products were the default legacy creation mode** and are exactly the rows that vanish. This is a migration blocker the port plan should carry, beyond the SPA form gap.

---

## Smaller notes (no action required on the audit text)

- Legacy store-tab "Add" link passes `?store_id=` and the warehouse empty-state link passes `?warehouse_id=`, but `create()` reads neither query param (only the route-bound `$warehouse` / `old()`), so #12's "deep-links to product create for that store" should be read as "passes a param the create form ignores" — consistent with quirk #140.
- Section detail page has its own "Create Product" link (`?section_id=`, actually the section *code*) — same dead-param situation; section surface already covered by `mgmt-catalog-org.md` §12.
- Legacy edit's variant toggle disables the base `quantity/amount/size/weight/color/currency` inputs (`setBaseFieldsDisabled`), so those values are not submitted while variants are on — a nuance worth keeping if a variant editor is ported.
- Legacy `updateStatus`/`destroy`/`bulk*` redirect to `management.stores.products` with `$product->store`; null-store products make that redirect fail — another symptom of the store_id-NULL split.
- `views` counter and `Log::info('business.products.viewed')` confirmed present in legacy index; `Admin\ProductController` confirmed separate (out of scope).
- No product `Imports`/`Exports`, duplicate, archive, reviews or questions found by grep in the business panel — the audit's "never existed" claims hold.
