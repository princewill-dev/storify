<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\Admin\Concerns\SerializesAdminProducts;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SizeUnit;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\WeightUnit;
use App\Services\ActivityRecorder;
use App\Services\ProductFileService;
use App\Services\StockLedgerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * WS-15 (admin console) — the platform product catalogue.
 *
 * Rebuilds `/office/products` and `/office/stores/{store}/products`: a
 * platform-wide directory with the legacy filter set (status, q over name /
 * code / store / category, created range, the URL-only `store_id` scope) plus
 * the store-scoped variants, the create/edit form (variants, bulk pricing,
 * multi-image with a primary picker), the tabbed detail, activate/deactivate
 * and delete.
 *
 * Scope decisions from the roadmap and its audit corrections:
 *
 * - **One serializer for the admin app.** {@see SerializesAdminProducts}
 *   holds the list/detail payloads and the money formatting; field names match
 *   the management payload where they overlap so a future consolidation is a
 *   move, not a rewrite (the management controller is another workstream's
 *   file and cannot be edited from here).
 * - **No digital-file / section / warehouse controls.** The legacy admin
 *   forms never rendered them (verify correction #3) — they were request-only
 *   fields on `ProductRequest`. They stay out of the admin API entirely;
 *   digital catalogue management lives in the management app, which has the
 *   pickers. `is_digital` is therefore read-only here.
 * - **The legacy `store_id` filter no longer fails open.** Legacy dropped an
 *   unresolvable store filter and returned every store's products; an
 *   unknown store now returns an empty page.
 * - **Upload errors stay human-readable.** Legacy translated PHP's
 *   `upload_max_filesize` / `post_max_size` failures; the API does the same
 *   instead of leaking "The images.0 failed to upload."
 * - **Variant sync actually prunes.** Legacy skipped the "delete variants
 *   missing from the payload" step whenever no incoming row carried an id, so
 *   replacing a variant list left every old row behind. Here the payload is
 *   authoritative once `has_variants` is on, and a forged variant id from
 *   another product is refused instead of updated.
 * - **Variant rows or a single SKU, never both half-required.** When
 *   `has_variants` is on the base quantity/amount are not required (matching
 *   legacy and the model's own validator); when off, amount and a positive
 *   quantity are.
 *
 * Stock: a created single-SKU product with quantity > 0 gets the legacy
 * store stock location and the "Product created (Admin) — initial stock"
 * ledger entry, so receiving, transfers and stock counts have a balance to
 * work from. Variant products keep legacy behaviour (no base quantity).
 * The initial-entry path is skipped only when a location for the product and
 * store already exists with stock (firstOrCreate + idempotent ledger).
 */
class ProductController extends ApiController
{
    use EnsuresPlatformAdmin;
    use SerializesAdminProducts;

    /**
     * Columns the list may sort on — the legacy list passed no sort through,
     * but the SPA's table headers need a whitelist either way.
     */
    private const SORTABLE = ['name', 'product_code', 'amount', 'status', 'featured', 'created_at', 'updated_at'];

    /**
     * The legacy image limit (20 MB), kept as the single source for the rule,
     * the form-options hint and the error copy.
     */
    private const IMAGE_MAX_KB = 20480;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->listResponse($request, null);
    }

    /**
     * The legacy `/admin/stores/{store}/products` scope.
     */
    public function storeIndex(Request $request, Store $store): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->listResponse($request, $store);
    }

    public function show(Product $product): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $product->load($this->detailRelations());

        return $this->ok(['product' => $this->productDetailPayload($product)]);
    }

    /**
     * The legacy store-scoped show — looked up by public product code inside
     * the store, so `/products/{code}` from one store can't resolve another
     * store's product through a copied URL.
     */
    public function showInStore(Store $store, string $product): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $model = Product::query()
            ->where('store_id', $store->id)
            ->where('product_code', $product)
            ->firstOrFail();

        $model->load($this->detailRelations());

        return $this->ok(['product' => $this->productDetailPayload($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($problem = $this->uploadProblem($request)) {
            return $problem;
        }

        $data = $request->validate($this->rules($request), $this->messages());

        $store = $this->liveStore((int) $data['store_id']);

        if (! $store) {
            return $this->error('Choose a store that still exists.', 422, ['store_id' => ['Choose a store that still exists.']]);
        }

        if ($message = $this->categoryMismatch($data['category_id'] ?? null, $store->id)) {
            return $this->error($message, 422, ['category_id' => [$message]]);
        }

        $product = DB::transaction(function () use ($request, $data, $store) {
            $product = new Product;
            $product->fill(Arr::except($data, ['images', 'primary_image', 'primary_image_id', 'delete_image_ids', 'variants']));
            $product->store_id = $store->id;
            $product->business_id = $store->business_id;
            // Defaults match the column defaults and the management API: a
            // product collects payment on delivery unless told otherwise.
            $product->cod_available = $request->boolean('cod_available', true);
            $product->featured = $request->boolean('featured');
            $product->has_variants = $request->boolean('has_variants');
            $product->is_taxable = $request->boolean('is_taxable', true);
            $product->bulk_quantity = $request->filled('bulk_quantity') ? (int) $request->input('bulk_quantity') : null;
            $product->bulk_price = $request->filled('bulk_price') ? (float) $request->input('bulk_price') : null;

            // A variant product carries no base quantity/amount; a single-SKU
            // product must (the rules require both, the model re-checks).
            if ($product->has_variants) {
                $product->quantity = (int) ($data['quantity'] ?? 0);
            }

            // product_code and slug are always server-generated (the model
            // boots them when empty) — never accept them from the request.
            $product->product_code = null;
            $product->slug = null;
            $product->save();

            $this->recordInitialStock($request, $product);
            $this->storeImages($request, $product);
            $this->syncVariants($request, $product);

            ActivityRecorder::record(
                action: 'create_product',
                description: "Product {$product->name} created for {$store->name}.",
                subject: $product,
                new: [
                    'name' => $product->name,
                    'product_code' => $product->product_code,
                    'store_id' => $product->store_id,
                    'amount' => $product->amount,
                    'quantity' => $product->quantity,
                    'has_variants' => (bool) $product->has_variants,
                ],
                metadata: ['has_variants' => (bool) $product->has_variants, 'store_id' => $product->store_id],
            );

            return $product;
        });

        $product->load($this->detailRelations());

        return $this->ok(['product' => $this->productDetailPayload($product)], 'Product created.', 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($problem = $this->uploadProblem($request)) {
            return $problem;
        }

        $data = $request->validate($this->rules($request, forUpdate: true), $this->messages());

        $store = $product->store;

        if (array_key_exists('store_id', $data) && (int) $data['store_id'] !== (int) $product->store_id) {
            $store = $this->liveStore((int) $data['store_id']);

            if (! $store) {
                return $this->error('Choose a store that still exists.', 422, ['store_id' => ['Choose a store that still exists.']]);
            }
        }

        // Judge the category against the store the product will belong to.
        $categoryId = array_key_exists('category_id', $data) ? $data['category_id'] : $product->category_id;

        if ($message = $this->categoryMismatch($categoryId, $store?->id)) {
            return $this->error($message, 422, ['category_id' => [$message]]);
        }

        DB::transaction(function () use ($request, $product, $data, $store) {
            $before = [
                'name' => $product->name,
                'status' => $product->status,
                'amount' => $product->amount,
                'quantity' => $product->quantity,
                'store_id' => $product->store_id,
                'category_id' => $product->category_id,
            ];

            $product->fill(Arr::except($data, ['images', 'primary_image', 'primary_image_id', 'delete_image_ids', 'variants']));

            if ($store) {
                $product->store_id = $store->id;
                $product->business_id = $store->business_id;
            }

            // Flags apply only when the payload carries them: a partial update
            // must not silently reset a flag it never mentioned (legacy's
            // full-form-only assumption read every omission as `false`).
            if ($request->has('cod_available')) {
                $product->cod_available = $request->boolean('cod_available');
            }

            if ($request->has('featured')) {
                $product->featured = $request->boolean('featured');
            }

            if ($request->has('is_taxable')) {
                $product->is_taxable = $request->boolean('is_taxable');
            }

            if ($request->has('has_variants')) {
                $product->has_variants = $request->boolean('has_variants');
            }

            // Bulk fields are nulled when cleared (legacy) — "remove the bulk
            // price and save" has to actually remove it. Like the flags above,
            // a payload that never mentions them must not wipe them: only a
            // present (possibly empty) bulk field is written.
            if ($request->has('bulk_quantity') || $request->has('bulk_price')) {
                $product->bulk_quantity = $request->filled('bulk_quantity') ? (int) $request->input('bulk_quantity') : null;
                $product->bulk_price = $request->filled('bulk_price') ? (float) $request->input('bulk_price') : null;
            }

            $product->save();

            if ($request->filled('delete_image_ids')) {
                $images = $product->images()->whereIn('id', (array) $request->input('delete_image_ids'))->get();

                foreach ($images as $image) {
                    try {
                        Storage::disk('public')->delete($image->path);
                    } catch (\Throwable $e) {
                        // A missing file must not strand the row — the audit
                        // note on delete applies here too.
                    }

                    $image->delete();
                }
            }

            $this->storeImages($request, $product);
            $this->syncVariants($request, $product);

            ActivityRecorder::record(
                action: 'update_product',
                description: "Product {$product->name} updated.",
                subject: $product,
                old: $before,
                new: [
                    'name' => $product->name,
                    'status' => $product->status,
                    'amount' => $product->amount,
                    'quantity' => $product->quantity,
                    'store_id' => $product->store_id,
                    'category_id' => $product->category_id,
                ],
                metadata: ['has_variants' => (bool) $product->has_variants],
            );
        });

        $product->load($this->detailRelations());

        return $this->ok(['product' => $this->productDetailPayload($product)], 'Product updated.');
    }

    public function updateStatus(Request $request, Product $product): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $previous = $product->status;

        DB::transaction(function () use ($product, $data, $previous) {
            $product->update(['status' => $data['status']]);

            ActivityRecorder::record(
                action: 'product_status_updated',
                description: $data['status'] === 'active'
                    ? "Product {$product->name} activated."
                    : "Product {$product->name} deactivated.",
                subject: $product,
                old: ['status' => $previous],
                new: ['status' => $data['status']],
            );
        });

        $product->load($this->detailRelations());

        return $this->ok(
            ['product' => $this->productListRow($product)],
            $data['status'] === 'active' ? 'Product activated.' : 'Product deactivated.',
        );
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorizePlatformAdmin();

        DB::transaction(function () use ($product) {
            foreach ($product->images as $image) {
                try {
                    Storage::disk('public')->delete($image->path);
                } catch (\Throwable $e) {
                    // Best effort: the row must still go.
                }

                $image->delete();
            }

            app(ProductFileService::class)->deleteAllFiles($product);

            ActivityRecorder::record(
                action: 'delete_product',
                description: "Product {$product->name} deleted.",
                subject: $product,
                old: ['product_code' => $product->product_code, 'store_id' => $product->store_id],
            );

            $product->delete();
        });

        return $this->ok([], 'Product deleted.');
    }

    /**
     * Create/edit form dropdowns: stores that still exist, their categories,
     * currencies and the size/weight units. Legacy loaded these per form
     * action; one endpoint keeps the SPA form's data in a single round trip.
     */
    public function formOptions(): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->ok([
            'stores' => Store::query()
                ->where('status', '!=', Store::STATUS_DELETED)
                ->with('business:id,name,business_code')
                ->orderBy('name')
                ->get(['id', 'store_id', 'name', 'status', 'business_id'])
                ->map(fn (Store $store) => [
                    'id' => $store->id,
                    'store_id' => $store->store_id,
                    'name' => $store->name,
                    'status' => $store->status,
                    'business' => $store->business?->name,
                    'business_code' => $store->business?->business_code,
                ])->values()->all(),
            'categories' => Category::query()
                ->orderBy('name')
                ->get(['id', 'name', 'store_id', 'status'])
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'store_id' => $category->store_id,
                    'status' => $category->status,
                ])->values()->all(),
            'currencies' => Currency::query()
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'symbol', 'is_default'])
                ->map(fn (Currency $currency) => [
                    'id' => $currency->id,
                    'name' => $currency->name,
                    'code' => $currency->code,
                    'symbol' => $currency->symbol,
                    'is_default' => (bool) $currency->is_default,
                ])->values()->all(),
            'size_units' => SizeUnit::query()->orderBy('name')->get(['id', 'name', 'code'])->all(),
            'weight_units' => WeightUnit::query()->orderBy('name')->get(['id', 'name', 'code'])->all(),
            'default_currency_id' => Currency::query()->where('is_default', true)->value('id'),
            'image_max_kb' => self::IMAGE_MAX_KB,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listResponse(Request $request, ?Store $store): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            // The legacy URL-only store scope matches the numeric id or the
            // public `st_…` id.
            'store_id' => ['nullable', 'string', 'max:50'],
            'category_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($filters['per_page'] ?? 10);

        $query = $this->catalogueQuery();

        if ($store) {
            $query->where('store_id', $store->id);
        } elseif (($filters['store_id'] ?? null) !== null && $filters['store_id'] !== '') {
            $storeId = $this->resolveStoreId((string) $filters['store_id']);

            if ($storeId === null) {
                // Legacy dropped an unresolvable store filter and returned
                // every store's rows; an unknown store returns nothing.
                return $this->ok([], null, 200, [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ]);
            }

            $query->where('store_id', $storeId);
        }

        $query
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $like = '%'.$term.'%';

                $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $like)
                    ->orWhere('product_code', 'like', $like)
                    ->orWhereHas('store', fn ($s) => $s->where('name', 'like', $like))
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', $like)));
            })
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $categoryId) => $q->where('category_id', $categoryId))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('created_at', '<=', $to));

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $products = $query->orderBy($sort, $direction)->orderByDesc('id')->paginate($perPage)->withQueryString();

        return $this->ok(
            $products->getCollection()->map(fn (Product $product) => $this->productListRow($product))->values()->all(),
            null,
            200,
            $this->paginationMeta($products),
        );
    }

    private function catalogueQuery(): Builder
    {
        return Product::query()->with([
            'store:id,name,store_id,slug',
            'category:id,name',
            'currency:id,code,symbol',
            'images:id,product_id,path,is_primary,position',
            'variants:id,product_id,amount,currency_id,status',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function detailRelations(): array
    {
        return [
            'store:id,name,store_id,slug',
            'category:id,name',
            'currency:id,code,symbol',
            'images' => fn ($q) => $q->orderBy('position'),
            'files',
            'variants' => fn ($q) => $q->with(['sizeUnit:id,name', 'weightUnit:id,name'])->orderBy('id'),
        ];
    }

    private function liveStore(int $id): ?Store
    {
        return Store::query()->whereKey($id)->where('status', '!=', Store::STATUS_DELETED)->first();
    }

    /**
     * A category can only be attached to its own store — legacy accepted any
     * category id, so a product could sit in a category the storefront never
     * rendered.
     */
    private function categoryMismatch(?int $categoryId, ?int $storeId): ?string
    {
        if (! $categoryId || ! $storeId) {
            return null;
        }

        $belongs = Category::query()->whereKey($categoryId)->where('store_id', $storeId)->exists();

        return $belongs ? null : 'The selected category does not belong to this store.';
    }

    /**
     * Resolve the legacy `store_id` filter: the numeric id or the public
     * `st_…` id. Null when no such store exists.
     */
    private function resolveStoreId(string $value): ?int
    {
        $store = Store::query()
            ->where('store_id', $value)
            ->when(ctype_digit($value), fn ($q) => $q->orWhere('id', (int) $value))
            ->first(['id']);

        return $store?->id;
    }

    /**
     * The initial stock ledger entry the audit asks for: a store stock
     * location plus one ADDED movement. Only for single-SKU products with
     * quantity to carry; the ledger service is idempotent per product +
     * location, so retrying the same create cannot double-count.
     */
    private function recordInitialStock(Request $request, Product $product): void
    {
        if ($product->has_variants || (int) $product->quantity <= 0) {
            return;
        }

        $location = StockLocation::firstOrCreate(
            [
                'product_id' => $product->id,
                'locationable_type' => Store::class,
                'locationable_id' => $product->store_id,
            ],
            [
                'quantity' => 0,
                'min_quantity' => 0,
                'business_id' => $product->business_id,
            ],
        );

        app(StockLedgerService::class)->recordAddition(
            $location,
            (int) $product->quantity,
            $product,
            $request->user(),
            'Product created (Admin) — initial stock',
        );
    }

    /**
     * Append the uploaded images at the end of the position order and apply
     * the primary picker. `primary_image` is the legacy index into the upload
     * array (create); `primary_image_id` picks a saved image (edit).
     */
    private function storeImages(Request $request, Product $product): void
    {
        if ($request->hasFile('images')) {
            $position = (int) ($product->images()->max('position') ?? -1) + 1;
            $created = [];
            $primaryIndex = $request->filled('primary_image') ? (int) $request->input('primary_image') : null;

            foreach ($request->file('images') as $index => $file) {
                $path = $file->store('products/images', 'public');

                $created[$index] = ProductImage::create([
                    'product_id' => $product->id,
                    'business_id' => $product->business_id,
                    'path' => $path,
                    'is_primary' => false,
                    'position' => $position++,
                ]);
            }

            if ($primaryIndex !== null && isset($created[$primaryIndex])) {
                $this->makePrimary($product, $created[$primaryIndex]->id);
            }
        }

        if ($request->filled('primary_image_id')) {
            $imageId = (int) $request->input('primary_image_id');

            if (! $product->images()->whereKey($imageId)->exists()) {
                abort(422, 'The selected image does not belong to this product.');
            }

            $this->makePrimary($product, $imageId);
        }

        // A product with images but no primary picker still needs one — the
        // storefront's primary image is the first by position.
        if (! $product->images()->where('is_primary', true)->exists()) {
            $first = $product->images()->orderBy('position')->first();

            if ($first) {
                $first->update(['is_primary' => true]);
            }
        }
    }

    private function makePrimary(Product $product, int $imageId): void
    {
        $product->images()->update(['is_primary' => false]);
        $product->images()->whereKey($imageId)->update(['is_primary' => true]);
    }

    /**
     * Variant synchronisation: existing rows are updated in place by id, new
     * ones are created, and rows missing from the payload are deleted. With
     * `has_variants` off, every variant is removed (legacy's "switch to
     * single-SKU" branch).
     */
    private function syncVariants(Request $request, Product $product): void
    {
        if ($request->has('has_variants') && ! $request->boolean('has_variants')) {
            $product->variants()->delete();

            return;
        }

        if (! $request->boolean('has_variants') || ! $request->has('variants')) {
            return;
        }

        $incoming = collect((array) $request->input('variants', []));

        if ($incoming->isEmpty()) {
            abort(422, 'Add at least one variant.');
        }

        $ids = $incoming->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($ids !== []) {
            $owned = ProductVariant::query()->where('product_id', $product->id)->whereIn('id', $ids)->pluck('id')->all();

            if (array_diff($ids, $owned) !== []) {
                abort(422, 'A variant does not belong to this product.');
            }
        }

        $keepIds = [];

        foreach ($incoming as $row) {
            $attributes = [
                'sku' => $row['sku'] ?? null,
                'size' => $row['size'] ?? null,
                'size_unit_id' => $row['size_unit_id'] ?? null,
                'weight' => $row['weight'] ?? null,
                'weight_unit_id' => $row['weight_unit_id'] ?? null,
                'color' => $row['color'] ?? null,
                'quantity' => (int) $row['quantity'],
                'amount' => $row['amount'],
                'currency_id' => $row['currency_id'] ?? null,
                'status' => $row['status'] ?? 'active',
                'featured' => ! empty($row['featured']),
            ];

            if (! empty($row['id'])) {
                $variant = ProductVariant::query()->where('product_id', $product->id)->findOrFail((int) $row['id']);
                $variant->update($attributes);
                $keepIds[] = $variant->id;

                continue;
            }

            $keepIds[] = $product->variants()->create($attributes)->id;
        }

        $product->variants()->whereNotIn('id', $keepIds)->delete();
    }

    /**
     * Legacy's variant-aware rules: base amount/quantity are not required
     * while variants are on; the variant rows are. On update everything is
     * "sometimes" so a partial payload cannot wipe fields it never sent.
     *
     * @return array<string, mixed>
     */
    private function rules(Request $request, bool $forUpdate = false): array
    {
        $presence = $forUpdate ? 'sometimes' : 'required';
        $hasVariants = $request->boolean('has_variants');

        $rules = [
            'store_id' => $forUpdate ? ['sometimes', 'integer'] : ['required', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'name' => [$presence, 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'status' => $forUpdate
                ? ['sometimes', Rule::in(['active', 'inactive'])]
                : ['required', Rule::in(['active', 'inactive'])],
            'featured' => ['sometimes', 'boolean'],
            'cod_available' => ['sometimes', 'boolean'],
            'has_variants' => ['sometimes', 'boolean'],
            'is_taxable' => ['sometimes', 'boolean'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'bulk_quantity' => ['nullable', 'integer', 'min:1'],
            'bulk_price' => ['nullable', 'numeric', 'min:0'],
            'images' => ['sometimes', 'array'],
            'images.*' => ['nullable', 'file', 'mimes:jpeg,jpg,png,gif,webp', 'max:'.self::IMAGE_MAX_KB],
            'primary_image' => ['sometimes', 'integer', 'min:0'],
            'primary_image_id' => ['nullable', 'integer'],
            'delete_image_ids' => ['sometimes', 'array'],
            'delete_image_ids.*' => ['integer'],
        ];

        if ($hasVariants) {
            return array_merge($rules, [
                'variants' => $forUpdate ? ['sometimes', 'array'] : ['required', 'array', 'min:1'],
                'variants.*.id' => ['sometimes', 'integer'],
                'variants.*.sku' => ['nullable', 'string', 'max:100'],
                'variants.*.size' => ['nullable', 'numeric', 'min:0'],
                'variants.*.size_unit_id' => ['nullable', 'integer', 'exists:size_units,id'],
                'variants.*.weight' => ['nullable', 'numeric', 'min:0'],
                'variants.*.weight_unit_id' => ['nullable', 'integer', 'exists:weight_units,id'],
                'variants.*.color' => ['nullable', 'string', 'max:100'],
                'variants.*.quantity' => ['required_with:variants', 'integer', 'gt:0'],
                'variants.*.amount' => ['required_with:variants', 'numeric', 'gt:0'],
                'variants.*.currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
                'variants.*.status' => ['sometimes', Rule::in(['active', 'inactive'])],
                'variants.*.featured' => ['sometimes', 'boolean'],
            ]);
        }

        return array_merge($rules, [
            'quantity' => $forUpdate ? ['sometimes', 'integer', 'min:0'] : ['required', 'integer', 'gt:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'amount' => $forUpdate ? ['sometimes', 'numeric', 'gt:0'] : ['required', 'numeric', 'gt:0'],
            'color' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'numeric', 'min:0'],
            'size_unit_id' => ['nullable', 'integer', 'exists:size_units,id'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit_id' => ['nullable', 'integer', 'exists:weight_units,id'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'quantity.required' => 'Quantity is required.',
            'quantity.gt' => 'Quantity must be greater than 0.',
            'amount.required' => 'Amount is required.',
            'amount.gt' => 'Amount must be greater than 0.',
            'variants.required' => 'Add at least one variant.',
            'variants.min' => 'Add at least one variant.',
            'images.*.max' => 'Each image must be 20 MB or smaller.',
            'images.*.mimes' => 'Images must be a JPEG, PNG, GIF or WebP file.',
        ];
    }

    /**
     * Human-readable translation of PHP upload failures (legacy's
     * `failedValidation` behaviour): when post_max_size is exceeded the
     * request arrives with empty input, and an individual oversized upload
     * fails before the mime/size rules ever run.
     */
    private function uploadProblem(Request $request): ?JsonResponse
    {
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        $postMax = (int) $this->iniBytes((string) ini_get('post_max_size'));

        if ($contentLength > 0
            && $postMax > 0
            && $contentLength > $postMax
            && $request->all() === []
            && $request->allFiles() === []) {
            $message = sprintf(
                'The upload was rejected because the request is larger than the server allows (post_max_size=%s). Reduce the file size and try again.',
                ini_get('post_max_size'),
            );

            return $this->error($message, 422, ['images' => [$message]]);
        }

        foreach ((array) $request->file('images', []) as $index => $file) {
            if ($file instanceof UploadedFile && ! $file->isValid()) {
                $message = $this->uploadErrorMessage($file, (int) $index);

                return $this->error($message, 422, ['images.'.$index => [$message]]);
            }
        }

        return null;
    }

    private function uploadErrorMessage(UploadedFile $file, int $index): string
    {
        $reason = match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE => 'the file exceeds the maximum size allowed by the server',
            UPLOAD_ERR_FORM_SIZE => 'the file exceeds the maximum size allowed by the form',
            UPLOAD_ERR_PARTIAL => 'the file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'no file data was received',
            UPLOAD_ERR_NO_TMP_DIR => 'the temporary upload directory is missing on the server',
            UPLOAD_ERR_CANT_WRITE => 'the server failed to write the file to disk',
            UPLOAD_ERR_EXTENSION => 'a PHP extension stopped the upload',
            default => 'the upload failed due to an unknown server error',
        };

        return sprintf(
            'Image #%d ("%s") could not be uploaded because %s. (upload_max_filesize=%s, post_max_size=%s)',
            $index + 1,
            $file->getClientOriginalName() ?: 'uploaded file',
            $reason,
            ini_get('upload_max_filesize'),
            ini_get('post_max_size'),
        );
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
