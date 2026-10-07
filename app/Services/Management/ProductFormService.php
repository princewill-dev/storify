<?php

namespace App\Services\Management;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Section;
use App\Models\User;
use App\Repositories\Management\ProductRepository;
use App\Services\Accounting\InventoryCostingService;
use App\Services\ActivityLogger;
use App\Services\Management\Warehouse\DefaultWarehouseResolver;
use App\Services\ProductFileService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * WS-14 — the product form's write workflows and assignment rules.
 *
 * Every workflow keeps the exact write order the controller used — base save,
 * bulk pricing, cost-price costing only when a cost price was actually
 * submitted, gallery removes, gallery uploads, variant sync, digital files —
 * with the same DB::transaction boundary around them. The controller keeps the
 * HTTP shape: status codes, message strings and the envelope.
 *
 * Deliberate details carried over:
 *
 * 1. `syncBulkPricing()` writes via forceFill() because the bulk pair used to
 *    be missing from Product::$fillable, so create()/update() discarded both
 *    keys without a word. The write stays direct and inside the caller's
 *    transaction either way.
 * 2. The first image a product ever receives becomes primary — the old
 *    `position === 0` test after a null `max(position)` cast could never be
 *    true, so every upload landed non-primary (verify pass #1).
 * 3. Variants upsert by id: rows sent with an id keep their identity, new
 *    rows are created, missing rows are deleted (audit #6). Disabling
 *    variants deletes the rows, as legacy did.
 * 4. A deleted primary image promotes the next one so the thumbnail stays
 *    deterministic.
 *
 * The assignment rule is also here: store/warehouse/section/category
 * ownership checks. The update path used to accept any store id and only
 * `authorizeProduct()` guarded the product as it stood, so a product could be
 * moved anywhere (verify pass #3).
 */
final class ProductFormService
{
    public function __construct(
        private readonly ProductRepository $repository,
        private readonly DefaultWarehouseResolver $defaultWarehouses,
    ) {}

    /**
     * Fill in the warehouse (and its section) when the caller chose neither,
     * so assignmentError() has something concrete to check.
     *
     * Kept separate from assignmentError() because that method is a validator
     * and callers reasonably expect it not to write. Resolving first also
     * means the rejection itself is untouched: when the resolver declines —
     * a platform admin with no business, restricted staff — the payload is
     * returned unchanged and assignmentError() produces the same 422 it always
     * did.
     *
     * array_key_exists, not ??, matches assignmentError(): the SPA posts
     * warehouse_id as '', which ConvertEmptyStringsToNull turns into an
     * explicit null key.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withDefaultWarehouse(User $user, array $data, ?Product $product, bool $isDigital): array
    {
        if ($isDigital) {
            return $data;
        }

        $warehouseId = array_key_exists('warehouse_id', $data) ? $data['warehouse_id'] : $product?->warehouse_id;
        $sectionId = array_key_exists('section_id', $data) ? $data['section_id'] : $product?->section_id;

        // Something was chosen — by this request or on the product already.
        // Never override a real choice.
        if ($warehouseId || $sectionId) {
            return $data;
        }

        $warehouse = $this->defaultWarehouses->resolve($user);

        if (! $warehouse) {
            return $data;
        }

        $data['warehouse_id'] = $warehouse->id;

        // Only on this path. Setting section_id when the caller picked a
        // warehouse of their own would trip Product::saving's "warehouse_id
        // follows section_id" sync and overwrite their choice with the
        // section's warehouse.
        $data['section_id'] = $warehouse->sections()
            ->where('status', '!=', Section::STATUS_DELETED)
            ->orderBy('id')
            ->value('id');

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data  the validated create payload
     */
    public function create(Request $request, User $user, array $data, bool $hasVariants, bool $isDigital): Product
    {
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

        return $product;
    }

    /**
     * @param  array<string, mixed>  $data  the validated update payload
     */
    public function update(Request $request, User $user, Product $product, array $data, bool $hasVariants, bool $isDigital): void
    {
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
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            foreach ($product->images as $image) {
                $this->deleteImageFile($image);
                $image->delete();
            }

            app(ProductFileService::class)->deleteAllFiles($product);
            $product->delete();
        });
    }

    public function setPrimaryImage(Product $product, ProductImage $image): void
    {
        DB::transaction(function () use ($product, $image) {
            $product->images()->update(['is_primary' => false]);
            $image->update(['is_primary' => true]);
        });
    }

    public function destroyImage(Product $product, ProductImage $image): void
    {
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
    }

    /**
     * Store/warehouse/section/category ownership checks. The update path used
     * to accept any store id and only `authorizeProduct()` guarded the product
     * as it stood, so a product could be moved anywhere (verify pass #3).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>|null
     */
    public function assignmentError(User $user, array $data, ?Product $product, bool $isDigital): ?array
    {
        $storeId = array_key_exists('store_id', $data) ? $data['store_id'] : $product?->store_id;
        if ($storeId !== null && ! in_array((int) $storeId, $this->repository->storeIds($user)->all(), true)) {
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
     * Bulk pricing is the one pair of columns the Product model did not list
     * in `$fillable` (the model file is shared with other workstreams and is
     * not this controller's to change), so `create()`/`update()` discarded
     * both keys without a word — the form accepted them and nothing was ever
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
}
