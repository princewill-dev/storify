<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Section;
use App\Models\SizeUnit;
use App\Models\Store;
use App\Models\Warehouse;
use App\Models\WeightUnit;
use App\Services\Accounting\InventoryCostingService;
use App\Services\ActivityLogger;
use App\Services\ProductFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * WS-14 — Products Form, Variants & Media.
 *
 * This controller is the full create/edit surface for a product: the legacy
 * form's selling data (cost price, discount, VAT, bulk pricing, attributes),
 * the variant editor, image management and the digital-file listing. It
 * overrides the thin `products` routes registered in routes/api/v1/management.php
 * for index/show/store/update/status/destroy — same URIs, so the SPA keeps one
 * endpoint per action — and adds the form-options and per-image routes the new
 * screens need.
 *
 * Two deliberate decisions carried from the audit's "improve on legacy" notes:
 *
 * 1. Store-less products. Legacy created products without a store (its create
 *    form had no store selector) and the first new-stack release made those
 *    rows invisible and 403 on every read — a live-data blocker. Rather than
 *    guess a store for them, a row with `store_id = NULL` stays manageable for
 *    the business as long as the caller can reach the warehouse it lives in
 *    (or, for fully detached digital products, is not restricted staff). New
 *    products still require a store.
 * 2. Money on this table is the legacy decimal-naira column (`amount`,
 *    `cost_price`, `bulk_price`). Discount display is computed in integer kobo
 *    and basis points; `average_cost_kobo` is maintained by
 *    InventoryCostingService, never by hand.
 */
class ProductFormController extends ApiController
{
    use ResolvesManagementContext;

    /** Legacy amber stock warning threshold on the products list. */
    private const LOW_STOCK_THRESHOLD = 10;

    private ?string $defaultCurrency = null;

    /**
     * The product list: filters, stock math, price display and currency, all
     * scoped to what the caller can reach. Store-less (warehouse-only) rows
     * are included rather than hidden — see the class docblock.
     *
     * At runtime WS-25's ProductListController re-registers the same
     * method+URI from its own module file, which loads later, so its superset
     * handler serves this URI; this method remains the in-repo fallback.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'store_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'digital_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = $this->accessibleProductQuery($request)
            ->with(['images', 'variants', 'currency', 'store', 'warehouse', 'category', 'section'])
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $warehouseId) => $q->where('warehouse_id', $warehouseId))
            ->when($filters['category_id'] ?? null, fn ($q, $categoryId) => $q->where('category_id', $categoryId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('digital_only'), fn ($q) => $q->where('is_digital', true))
            ->when($filters['q'] ?? null, function ($q, $term) {
                // Legacy searched the category name too, not just the brand.
                $q->where(fn ($inner) => $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('product_code', 'like', "%{$term}%")
                    ->orWhere('brand', 'like', "%{$term}%")
                    ->orWhereHas('category', fn ($category) => $category->where('name', 'like', "%{$term}%")));
            })
            ->latest()
            ->paginate($filters['per_page'] ?? 20);

        return $this->ok(
            $products->getCollection()->map(fn (Product $product) => $this->payload($product, $request))->values()->all(),
            null,
            200,
            $this->paginationMeta($products),
        );
    }

    /**
     * The widened detail payload the edit view (and any future detail page)
     * renders: names for every relation, dimensions and units, stock math,
     * tags, views and full variant/file/image rows.
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $product->load($this->detailRelations());

        return $this->ok(['product' => $this->payload($product, $request, detailed: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $isDigital = $request->boolean('is_digital');
        $hasVariants = $request->boolean('has_variants');

        $data = $request->validate($this->rules(forUpdate: false, hasVariants: $hasVariants, isDigital: $isDigital));

        if ($error = $this->assignmentError($request, $data, null, $isDigital)) {
            return $this->error($this->firstError($error), 422, $error);
        }

        $variants = (array) ($data['variants'] ?? []);
        unset($data['variants'], $data['images'], $data['digital_files']);

        $data['business_id'] = $user->business_id;
        $data['is_digital'] = $isDigital;
        $data['featured'] = $request->boolean('featured');
        $data['is_taxable'] = $request->boolean('is_taxable', true);
        $data['cod_available'] = $isDigital ? false : $request->boolean('cod_available', true);
        $data['has_variants'] = $hasVariants;

        $product = DB::transaction(function () use ($request, $data, $hasVariants, $variants) {
            $product = Product::create($data);
            $this->syncBulkPricing($product, $data);

            // Cost price feeds accounting costing. The first new-stack release
            // dropped this side effect silently (audit #5).
            if ($product->cost_price !== null) {
                app(InventoryCostingService::class)->syncFromCostPrice($product);
            }

            $this->syncImages($request, $product);

            if ($hasVariants) {
                $this->syncVariants($product, $variants);
            }

            if ($request->hasFile('digital_files')) {
                app(ProductFileService::class)->storeFiles($product, $this->uploadedFiles($request, 'digital_files'));
            }

            return $product;
        });

        // Legacy wrote business_create_product on every save; the new stack had
        // no audit trail at all (audit #5).
        ActivityLogger::log(
            'business_create_product',
            'Business created a product: '.$product->name,
            ['user_id' => $user->id, 'product_id' => $product->id],
            $user->id,
        );

        return $this->ok(['product' => $this->payload($product->fresh()->load($this->detailRelations()), $request, detailed: true)], 'Product created.', 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $user = $this->user($request);
        $hasVariants = $request->has('has_variants') ? $request->boolean('has_variants') : (bool) $product->has_variants;
        $isDigital = $request->has('is_digital') ? $request->boolean('is_digital') : (bool) $product->is_digital;

        $data = $request->validate($this->rules(forUpdate: true, hasVariants: $hasVariants, isDigital: $isDigital, request: $request));

        if ($error = $this->assignmentError($request, $data, $product, $isDigital)) {
            return $this->error($this->firstError($error), 422, $error);
        }

        $variants = (array) ($data['variants'] ?? []);
        unset($data['variants'], $data['images'], $data['digital_files']);

        if ($request->has('is_digital')) {
            $data['is_digital'] = $isDigital;
        }
        if ($request->has('featured')) {
            $data['featured'] = $request->boolean('featured');
        }
        if ($request->has('is_taxable')) {
            $data['is_taxable'] = $request->boolean('is_taxable');
        }
        if ($request->has('cod_available')) {
            $data['cod_available'] = $isDigital ? false : $request->boolean('cod_available');
        } elseif (isset($data['is_digital']) && $isDigital) {
            $data['cod_available'] = false;
        }
        if ($request->has('has_variants')) {
            $data['has_variants'] = $hasVariants;
        }

        DB::transaction(function () use ($request, $product, $data, $hasVariants, $variants) {
            $product->update($data);
            $this->syncBulkPricing($product, $data);

            // Only when the caller actually submitted a cost price — otherwise
            // an unrelated edit would reset the weighted average that stock
            // receipts maintain.
            if (array_key_exists('cost_price', $data) && $data['cost_price'] !== null) {
                app(InventoryCostingService::class)->syncFromCostPrice($product);
            }

            $this->deleteImages($request, $product);

            if ($request->filled('delete_file_ids')) {
                app(ProductFileService::class)->deleteFiles($product, (array) $request->input('delete_file_ids'));
            }

            $this->syncImages($request, $product);

            // Disabling variants deletes the rows (legacy did; the first
            // new-stack release left them orphaned — verify pass #2). While
            // enabled, variants are upserted by id so existing rows keep their
            // identity instead of being deleted and re-created (audit #6).
            if (! $hasVariants) {
                $product->variants()->delete();
            } elseif ($request->has('variants')) {
                $this->syncVariants($product, $variants);
            }

            if ($request->hasFile('digital_files')) {
                app(ProductFileService::class)->storeFiles($product, $this->uploadedFiles($request, 'digital_files'));
            }
        });

        ActivityLogger::log(
            'business_update_product',
            'Business updated a product',
            ['user_id' => $user->id, 'product_id' => $product->id, 'has_variants' => (bool) $product->has_variants],
            $user->id,
        );

        return $this->ok(['product' => $this->payload($product->fresh()->load($this->detailRelations()), $request, detailed: true)], 'Product updated.');
    }

    public function updateStatus(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        $product->update(['status' => $data['status']]);

        return $this->ok(['product' => $this->payload($product->fresh(), $request)], 'Product status updated.');
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        DB::transaction(function () use ($product) {
            foreach ($product->images as $image) {
                $this->deleteImageFile($image);
                $image->delete();
            }

            app(ProductFileService::class)->deleteAllFiles($product);
            $product->delete();
        });

        return $this->ok([], 'Product deleted.');
    }

    /**
     * Everything the product form needs to render its pickers in one call:
     * stores, warehouses with their sections, categories, currencies, size and
     * weight units, and the digital-download defaults.
     */
    public function formOptions(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $storeIds = $this->storeIds($request);

        $warehouses = $user->accessibleWarehouses()
            ->where('status', '!=', Warehouse::STATUS_DELETED)
            ->with(['sections' => fn ($query) => $query->where('status', '!=', Section::STATUS_DELETED)->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn ($warehouse) => [
                'id' => $warehouse->id,
                'warehouse_code' => $warehouse->warehouse_code,
                'name' => $warehouse->name,
                'city' => $warehouse->city,
                'state' => $warehouse->state,
                'sections' => $warehouse->sections->map(fn (Section $section) => [
                    'id' => $section->id,
                    'section_code' => $section->section_code,
                    'name' => $section->name,
                    'status' => $section->status->value,
                ])->values()->all(),
            ])->values()->all();

        return $this->ok([
            'stores' => $user->accessibleStores()
                ->where('status', '!=', Store::STATUS_DELETED)
                ->orderBy('name')
                ->get()
                ->map(fn (Store $store) => [
                    'id' => $store->id,
                    'store_id' => $store->store_id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                ])->values()->all(),
            'warehouses' => $warehouses,
            'categories' => Category::where('business_id', $user->business_id)
                ->whereIn('store_id', $storeIds)
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'store_id' => $category->store_id,
                    'status' => $category->status,
                ])->values()->all(),
            'currencies' => Currency::orderBy('code')->get()->map(fn (Currency $currency) => [
                'id' => $currency->id,
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'is_default' => (bool) $currency->is_default,
            ])->values()->all(),
            'size_units' => SizeUnit::orderBy('name')->get()->map(fn (SizeUnit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'code' => $unit->code,
            ])->values()->all(),
            'weight_units' => WeightUnit::orderBy('name')->get()->map(fn (WeightUnit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'code' => $unit->code,
            ])->values()->all(),
            'defaults' => [
                'download_limit' => (int) config('digital.default_download_limit', 5),
                'download_expiry_days' => (int) config('digital.default_expiry_days', 7),
            ],
            'low_stock_threshold' => self::LOW_STOCK_THRESHOLD,
            'business_currency' => $this->defaultCurrencyCode($request),
        ]);
    }

    /**
     * Set the gallery's primary image. The upload path could never elect one,
     * so a "set primary" action had nothing to read (verify pass #1).
     */
    public function setPrimaryImage(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        if ((int) $image->product_id !== (int) $product->id) {
            abort(404, 'Image not found for this product.');
        }

        DB::transaction(function () use ($product, $image) {
            $product->images()->update(['is_primary' => false]);
            $image->update(['is_primary' => true]);
        });

        return $this->ok([
            'product' => $this->payload($product->fresh()->load($this->detailRelations()), $request, detailed: true),
        ], 'Primary image updated.');
    }

    public function destroyImage(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        if ((int) $image->product_id !== (int) $product->id) {
            abort(404, 'Image not found for this product.');
        }

        DB::transaction(function () use ($product, $image) {
            $wasPrimary = (bool) $image->is_primary;

            $this->deleteImageFile($image);
            $image->delete();

            // Legacy could end up with a primary-less gallery; promote the
            // first remaining image so the list thumbnail stays deterministic.
            if ($wasPrimary) {
                $next = $product->images()->orderBy('position')->orderBy('id')->first();
                $next?->update(['is_primary' => true]);
            }
        });

        return $this->ok([
            'product' => $this->payload($product->fresh()->load($this->detailRelations()), $request, detailed: true),
        ], 'Image removed.');
    }

    /**
     * Products the caller may manage: their stores, plus store-less rows the
     * business still holds in one of their warehouses (see the class docblock).
     */
    private function accessibleProductQuery(Request $request)
    {
        $user = $this->user($request);
        $storeIds = $this->storeIds($request);
        $warehouseIds = $user->accessibleWarehouseIds();

        return Product::query()
            ->where('business_id', $user->business_id)
            ->where(function ($query) use ($user, $storeIds, $warehouseIds) {
                $query->whereIn('store_id', $storeIds)
                    ->orWhere(fn ($inner) => $inner->whereNull('store_id')->whereIn('warehouse_id', $warehouseIds));

                // Fully detached digital rows (legacy allowed a product with no
                // store and no warehouse) stay reachable for the business.
                if (! $user->isRestrictedStaff()) {
                    $query->orWhere(fn ($inner) => $inner->whereNull('store_id')->whereNull('warehouse_id'));
                }
            });
    }

    private function authorizeProduct(Request $request, Product $product): void
    {
        $user = $this->user($request);

        if ((int) $product->business_id !== (int) $user->business_id) {
            abort(403, 'You do not have access to this product.');
        }

        if ($product->store_id) {
            // Mirrors the legacy check: the store must be reachable and not
            // soft-deleted, or the product is out of scope.
            if ($this->storeIds($request)->contains((int) $product->store_id)) {
                return;
            }

            abort(403, 'You do not have access to this product.');
        }

        if ($product->warehouse_id && $user->accessibleWarehouses()->whereKey($product->warehouse_id)->exists()) {
            return;
        }

        if (! $product->warehouse_id && ! $user->isRestrictedStaff()) {
            return;
        }

        abort(403, 'You do not have access to this product.');
    }

    /**
     * Accessible stores minus soft-deleted ones — legacy filtered deleted
     * stores on every product read.
     *
     * @return Collection<int, int>
     */
    private function storeIds(Request $request)
    {
        return $this->user($request)
            ->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->pluck('id');
    }

    /**
     * Store/warehouse/section/category ownership checks. The update path used
     * to accept any store id and only `authorizeProduct()` guarded the product
     * as it stood, so a product could be moved anywhere (verify pass #3).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>|null
     */
    private function assignmentError(Request $request, array $data, ?Product $product, bool $isDigital): ?array
    {
        $user = $this->user($request);

        $storeId = array_key_exists('store_id', $data) ? $data['store_id'] : $product?->store_id;
        if ($storeId !== null && ! in_array((int) $storeId, $this->storeIds($request)->all(), true)) {
            return ['store_id' => ['Invalid store selection.']];
        }

        if (array_key_exists('category_id', $data) && $data['category_id'] !== null) {
            $belongs = Category::where('business_id', $user->business_id)->whereKey($data['category_id'])->exists();
            if (! $belongs) {
                return ['category_id' => ['Invalid category selection.']];
            }
        }

        $section = null;
        if (array_key_exists('section_id', $data) && $data['section_id'] !== null) {
            $section = Section::where('business_id', $user->business_id)->whereKey($data['section_id'])->first();
            if (! $section) {
                return ['section_id' => ['Invalid section selection.']];
            }
        }

        // The Product model fills warehouse_id from the section on save, so
        // judge both the explicit warehouse and the section's one — which of
        // the two wins depends on whether section_id is dirty.
        $explicitWarehouseId = array_key_exists('warehouse_id', $data) ? $data['warehouse_id'] : $product?->warehouse_id;

        foreach (array_filter([$explicitWarehouseId, $section?->warehouse_id]) as $warehouseId) {
            if (! $user->accessibleWarehouses()->whereKey($warehouseId)->exists()) {
                return ['warehouse_id' => ['Invalid warehouse selection.']];
            }
        }

        $effectiveWarehouseId = $section?->warehouse_id ?: $explicitWarehouseId;

        if (! $isDigital && ! $effectiveWarehouseId) {
            return ['warehouse_id' => ['Assign the product to a warehouse.']];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $forUpdate, bool $hasVariants, bool $isDigital, ?Request $request = null): array
    {
        $digitalMimes = implode(',', config('digital.allowed_mimes', ['pdf', 'zip']));
        $digitalMaxKb = (int) config('digital.max_upload_kb', 102400);
        $presence = $forUpdate ? 'sometimes' : 'required';

        $rules = [
            'name' => [$presence, 'string', 'max:255'],
            'store_id' => $forUpdate ? ['sometimes', 'nullable', 'integer'] : ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'brand' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tags' => ['nullable', 'string', 'max:1000'],
            // Not nullable: the column has no NULL state, so an explicit null
            // must fail validation rather than blow up the insert.
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            // Single-SKU attributes the old API rules never accepted even
            // though the columns existed (audit #5).
            'color' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'numeric', 'min:0'],
            'size_unit_id' => ['nullable', 'integer', 'exists:size_units,id'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit_id' => ['nullable', 'integer', 'exists:weight_units,id'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'bulk_quantity' => ['nullable', 'integer', 'min:1'],
            'bulk_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'is_digital' => ['sometimes', 'boolean'],
            'is_taxable' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'has_variants' => ['sometimes', 'boolean'],
            'cod_available' => ['sometimes', 'boolean'],
            'download_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'download_expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'images.*' => ['nullable', 'mimes:jpeg,jpg,png,gif,webp', 'max:20480'],
            'digital_files.*' => ['nullable', 'file', "mimes:{$digitalMimes}", "max:{$digitalMaxKb}"],
            'delete_image_ids' => ['sometimes', 'array'],
            'delete_image_ids.*' => ['integer'],
            'delete_file_ids' => ['sometimes', 'array'],
            'delete_file_ids.*' => ['integer'],
            'primary_image_id' => ['nullable', 'integer'],
        ];

        if ($hasVariants) {
            // Variants carry the price and stock: base amount/quantity are not
            // required while the product is variant-driven (legacy disabled the
            // base inputs too).
            $rules['amount'] = ['nullable', 'numeric', 'gt:0'];
            $rules['quantity'] = ['nullable', 'integer', 'min:0'];

            // A variant product must actually carry variants. On update this is
            // only demanded when the caller is turning the flag on, so partial
            // updates (renaming, toggling featured) don't need to resend rows.
            $variantsRequired = ! $forUpdate || ($request?->has('has_variants') ?? false);
            $rules['variants'] = [$variantsRequired ? 'required' : 'sometimes', 'array', 'min:1'];
            $rules['variants.*.id'] = ['sometimes', 'integer'];
            $rules['variants.*.sku'] = ['nullable', 'string', 'max:100'];
            $rules['variants.*.size'] = ['nullable', 'numeric', 'min:0'];
            $rules['variants.*.size_unit_id'] = ['nullable', 'integer', 'exists:size_units,id'];
            $rules['variants.*.weight'] = ['nullable', 'numeric', 'min:0'];
            $rules['variants.*.weight_unit_id'] = ['nullable', 'integer', 'exists:weight_units,id'];
            $rules['variants.*.color'] = ['nullable', 'string', 'max:100'];
            $rules['variants.*.quantity'] = ['required_with:variants', 'integer', 'gt:0'];
            $rules['variants.*.amount'] = ['required_with:variants', 'numeric', 'gt:0'];
            $rules['variants.*.currency_id'] = ['nullable', 'integer', 'exists:currencies,id'];
            $rules['variants.*.status'] = ['nullable', Rule::in(['active', 'inactive'])];
            $rules['variants.*.featured'] = ['sometimes', 'boolean'];

            return $rules;
        }

        $rules['amount'] = $forUpdate ? ['sometimes', 'numeric', 'gt:0'] : ['required', 'numeric', 'gt:0'];
        $rules['quantity'] = $isDigital
            ? ['nullable', 'integer', 'min:0']
            : [$forUpdate ? 'sometimes' : 'required', 'integer', 'min:0'];

        return $rules;
    }

    /**
     * Bulk pricing is the one pair of columns the Product model does not list
     * in `$fillable` (the model file is shared with other workstreams and is
     * not this controller's to change), so `create()`/`update()` discard both
     * keys without a word — the form accepted them and nothing was ever
     * stored. Write them directly, the same way the admin product controller
     * does, inside the caller's transaction.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncBulkPricing(Product $product, array $data): void
    {
        $bulk = [];

        if (array_key_exists('bulk_quantity', $data)) {
            $bulk['bulk_quantity'] = $data['bulk_quantity'] !== null ? (int) $data['bulk_quantity'] : null;
        }

        if (array_key_exists('bulk_price', $data)) {
            $bulk['bulk_price'] = $data['bulk_price'] !== null ? (float) $data['bulk_price'] : null;
        }

        if ($bulk !== []) {
            $product->forceFill($bulk)->save();
        }
    }

    /**
     * Append uploads to the gallery. The first image a product ever receives
     * becomes primary — the old code's `position === 0` test after a null
     * `max(position)` cast could never be true, so every upload landed
     * non-primary (verify pass #1).
     */
    private function syncImages(Request $request, Product $product): void
    {
        if ($request->hasFile('images')) {
            $hasPrimary = $product->images()->exists();
            $position = ((int) $product->images()->max('position')) + 1;
            $position = max(0, $position);

            $files = $request->file('images');
            $files = is_array($files) ? $files : [$files];

            foreach ($files as $file) {
                $path = $file->store('products/images', 'public');

                ProductImage::create([
                    'product_id' => $product->id,
                    'business_id' => $product->business_id,
                    'path' => $path,
                    'is_primary' => ! $hasPrimary,
                    'position' => $position++,
                ]);

                $hasPrimary = true;
            }
        }

        if ($request->filled('primary_image_id')) {
            $primaryId = $request->integer('primary_image_id');

            if ($product->images()->whereKey($primaryId)->exists()) {
                $product->images()->update(['is_primary' => false]);
                $product->images()->whereKey($primaryId)->update(['is_primary' => true]);
            }
        }
    }

    private function deleteImages(Request $request, Product $product): void
    {
        if (! $request->filled('delete_image_ids')) {
            return;
        }

        $images = $product->images()->whereIn('id', (array) $request->input('delete_image_ids'))->get();
        $removedPrimary = $images->contains(fn (ProductImage $image) => (bool) $image->is_primary);

        foreach ($images as $image) {
            $this->deleteImageFile($image);
            $image->delete();
        }

        if ($removedPrimary) {
            $next = $product->images()->orderBy('position')->orderBy('id')->first();
            $next?->update(['is_primary' => true]);
        }
    }

    /**
     * A single-file field arrives as an UploadedFile, a multi-upload as an
     * array; the file service wants a list either way.
     *
     * @return array<int, UploadedFile>
     */
    private function uploadedFiles(Request $request, string $key): array
    {
        $files = $request->file($key);

        return array_values(array_filter(is_array($files) ? $files : [$files]));
    }

    private function deleteImageFile(ProductImage $image): void
    {
        try {
            Storage::disk('public')->delete($image->path);
        } catch (\Throwable $e) {
            // Non-fatal: the row goes regardless, matching legacy cleanup.
        }
    }

    /**
     * Upsert variants in place: rows sent with an id keep their identity, new
     * rows are created, and rows that are no longer sent are deleted. Legacy
     * behaved this way; the old API deleted and re-created every row, churning
     * ids (audit #6).
     *
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function syncVariants(Product $product, array $variants): void
    {
        $keep = [];

        foreach ($variants as $row) {
            $attributes = [
                'sku' => $row['sku'] ?? null,
                'size' => $row['size'] ?? null,
                'size_unit_id' => $row['size_unit_id'] ?? null,
                'weight' => $row['weight'] ?? null,
                'weight_unit_id' => $row['weight_unit_id'] ?? null,
                'color' => $row['color'] ?? null,
                'quantity' => (int) ($row['quantity'] ?? 0),
                'amount' => $row['amount'] ?? 0,
                'currency_id' => $row['currency_id'] ?? null,
                'status' => $row['status'] ?? 'active',
                // The old API hardcoded featured=false (audit #6).
                'featured' => ! empty($row['featured']),
            ];

            $existing = ! empty($row['id'])
                ? $product->variants()->whereKey($row['id'])->first()
                : null;

            if ($existing) {
                $existing->update($attributes);
                $keep[] = $existing->id;
            } else {
                $keep[] = $product->variants()->create($attributes)->id;
            }
        }

        $product->variants()->whereNotIn('id', $keep)->delete();
    }

    /**
     * @return array<int, string>
     */
    private function detailRelations(): array
    {
        return [
            'images' => fn ($query) => $query->orderBy('position'),
            'files',
            'variants' => fn ($query) => $query->orderBy('id'),
            'category',
            'store',
            'section',
            'warehouse',
            'currency',
            'sizeUnit',
            'weightUnit',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product, Request $request, bool $detailed = false): array
    {
        $amountKobo = $product->amount !== null ? (int) round(((float) $product->amount) * 100) : null;
        $discountPercent = $product->discount_percentage !== null ? (float) $product->discount_percentage : null;

        // Discount is applied in integer kobo and basis points, never float
        // arithmetic on the money itself.
        $discountKobo = 0;
        if ($amountKobo !== null && $discountPercent) {
            $discountKobo = (int) round($amountKobo * (int) round($discountPercent * 100) / 10000);
        }

        $displayKobo = $amountKobo !== null ? max(0, $amountKobo - $discountKobo) : null;

        $quantity = (int) $product->quantity;
        $isDigital = (bool) $product->is_digital;

        // Variant-driven products hold their stock on the variant rows; the
        // base quantity is only a fallback.
        $variantStock = $product->has_variants && $product->relationLoaded('variants')
            ? (int) $product->variants->sum('quantity')
            : null;

        $data = [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'slug' => $product->slug,
            'brand' => $product->brand,
            'tags' => $product->tags,
            'amount' => $amountKobo !== null ? $amountKobo / 100 : null,
            'display_amount' => $displayKobo !== null ? $displayKobo / 100 : null,
            'discount_percentage' => $discountPercent,
            'has_discount' => $discountKobo > 0,
            'discount_amount' => $discountKobo / 100,
            'quantity' => $quantity,
            'variant_stock' => $variantStock,
            'stock_quantity' => $product->stock_quantity !== null ? (int) $product->stock_quantity : null,
            'sold_quantity' => $product->soldQuantity(),
            'stock_percentage' => $product->stockPercentage(),
            'stock_level' => $this->stockLevel($product),
            'low_stock' => ! $isDigital && ($variantStock ?? $quantity) <= self::LOW_STOCK_THRESHOLD,
            'is_digital' => $isDigital,
            'is_taxable' => (bool) $product->is_taxable,
            'status' => $product->status,
            'featured' => (bool) $product->featured,
            'cod_available' => (bool) $product->cod_available,
            'has_variants' => (bool) $product->has_variants,
            'store_id' => $product->store_id,
            'store_name' => $product->store?->name,
            'warehouse_id' => $product->warehouse_id,
            'warehouse_name' => $product->warehouse?->name,
            'section_id' => $product->section_id,
            'section_name' => $product->section?->name,
            'category_id' => $product->category_id,
            'category_name' => $product->category?->name,
            'currency_id' => $product->currency_id,
            'currency_code' => $product->currency?->code ?? $this->defaultCurrencyCode($request),
            'currency_symbol' => $product->currency?->symbol,
            'price_range' => $this->priceRange($product),
            'variant_count' => $product->relationLoaded('variants') ? $product->variants->count() : null,
            'image_url' => $product->primaryImage()?->path
                ? asset('storage/'.$product->primaryImage()->path)
                : null,
            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
        ];

        if ($detailed) {
            $data['description'] = $product->description;
            $data['color'] = $product->color;
            $data['size'] = $product->size !== null ? (float) $product->size : null;
            $data['size_unit_id'] = $product->size_unit_id;
            $data['size_unit_name'] = $product->sizeUnit?->name;
            $data['weight'] = $product->weight !== null ? (float) $product->weight : null;
            $data['weight_unit_id'] = $product->weight_unit_id;
            $data['weight_unit_name'] = $product->weightUnit?->name;
            $data['cost_price'] = $product->cost_price !== null ? (float) $product->cost_price : null;
            $data['average_cost_kobo'] = (int) ($product->average_cost_kobo ?? 0);
            $data['bulk_quantity'] = $product->bulk_quantity !== null ? (int) $product->bulk_quantity : null;
            $data['bulk_price'] = $product->bulk_price !== null ? (float) $product->bulk_price : null;
            $data['download_limit'] = $product->download_limit;
            $data['download_expiry_days'] = $product->download_expiry_days;
            // Legacy showed a views counter on the detail screens.
            $data['views'] = (int) ($product->views ?? 0);
            $data['currency'] = $product->currency ? [
                'id' => $product->currency->id,
                'code' => $product->currency->code,
                'symbol' => $product->currency->symbol,
            ] : null;
            $data['store'] = $product->store ? [
                'id' => $product->store->id,
                'store_id' => $product->store->store_id,
                'name' => $product->store->name,
                'slug' => $product->store->slug,
            ] : null;
            $data['warehouse'] = $product->warehouse ? [
                'id' => $product->warehouse->id,
                'warehouse_code' => $product->warehouse->warehouse_code,
                'name' => $product->warehouse->name,
            ] : null;
            $data['section'] = $product->section ? [
                'id' => $product->section->id,
                'section_code' => $product->section->section_code,
                'name' => $product->section->name,
            ] : null;
            $data['category'] = $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ] : null;
            $data['variants'] = $product->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'variant_code' => $variant->variant_code,
                'sku' => $variant->sku,
                'size' => $variant->size !== null ? (float) $variant->size : null,
                'size_unit_id' => $variant->size_unit_id,
                'size_unit_name' => $variant->sizeUnit?->name,
                'weight' => $variant->weight !== null ? (float) $variant->weight : null,
                'weight_unit_id' => $variant->weight_unit_id,
                'weight_unit_name' => $variant->weightUnit?->name,
                'color' => $variant->color,
                'quantity' => (int) $variant->quantity,
                'amount' => (float) $variant->amount,
                'currency_id' => $variant->currency_id,
                'status' => $variant->status,
                'featured' => (bool) $variant->featured,
            ])->values()->all();
            $data['files'] = $product->files->map(fn ($file) => [
                'id' => $file->id,
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size' => (int) $file->size,
                'formatted_size' => $file->formatted_size,
                'is_primary' => (bool) $file->is_primary,
                'position' => (int) $file->position,
                // Lesson from the audit's digital-file gap: tell the business
                // when a stored file has vanished from disk.
                'exists_on_disk' => $file->existsOnDisk(),
            ])->values()->all();
            $data['images'] = $product->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => asset('storage/'.$image->path),
                'is_primary' => (bool) $image->is_primary,
                'position' => (int) $image->position,
            ])->values()->all();
        }

        return $data;
    }

    /**
     * Variant price span for the list/summary screens, or null when the
     * product has no variants.
     *
     * @return array{min: float, max: float}|null
     */
    private function priceRange(Product $product): ?array
    {
        if (! $product->relationLoaded('variants') || $product->variants->isEmpty()) {
            return null;
        }

        $amounts = $product->variants->pluck('amount')->map(fn ($amount) => (float) $amount);

        return ['min' => (float) $amounts->min(), 'max' => (float) $amounts->max()];
    }

    /**
     * Good/Medium/Low band for the stock bar the legacy detail screen showed.
     */
    private function stockLevel(Product $product): string
    {
        if ($product->is_digital) {
            return 'digital';
        }

        $percentage = $product->stockPercentage();

        if ($percentage >= 60) {
            return 'good';
        }

        return $percentage >= 25 ? 'medium' : 'low';
    }

    private function defaultCurrencyCode(Request $request): string
    {
        return $this->defaultCurrency ??= (Currency::where('is_default', true)->value('code')
            ?: $this->user($request)->business?->currency
            ?: 'NGN');
    }

    /**
     * @param  array<string, array<int, string>>  $error
     */
    private function firstError(array $error): string
    {
        $messages = reset($error);

        return is_array($messages) ? (string) reset($messages) : (string) $messages;
    }
}
