<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Repositories\Admin\ProductRepository;
use App\Services\ActivityRecorder;
use App\Services\ProductFileService;
use App\Services\StockLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * WS-15 (admin console) — the product catalogue write workflows.
 *
 * Every mutation pairs its table writes with the audit row inside one
 * transaction (the recorder contract: a rejected audit row must take the
 * mutation down with it), and each workflow keeps the exact write order the
 * controller used — image rows before variant sync, stock ledger before both,
 * delete's best-effort file cleanup before the row goes. The controller keeps
 * the HTTP shape: validation, the 422 copy and the response echo.
 *
 * Stock (from the WS-15 provenance notes): a created single-SKU product with
 * quantity > 0 gets the legacy store stock location and the "Product created
 * (Admin) — initial stock" ledger entry, so receiving, transfers and stock
 * counts have a balance to work from. Variant products keep legacy behaviour
 * (no base quantity). The initial-entry path is skipped only when a location
 * for the product and store already exists with stock (firstOrCreate +
 * idempotent ledger).
 *
 * Variant sync (same notes): existing rows are updated in place by id, new
 * ones are created, and rows missing from the payload are deleted. Legacy
 * skipped the delete step whenever no incoming row carried an id, so
 * replacing a variant list left every old row behind; here the payload is
 * authoritative once `has_variants` is on, and a forged variant id from
 * another product is refused instead of updated. The refusals are 422s by
 * design (anti-id-probing), raised here with abort() so they keep their exact
 * status and message.
 */
final class ProductCatalogueService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the validated create payload
     */
    public function create(Request $request, array $data, Store $store): Product
    {
        return DB::transaction(function () use ($request, $data, $store) {
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
    }

    /**
     * @param  array<string, mixed>  $data  the validated update payload
     */
    public function update(Request $request, Product $product, array $data, ?Store $store): void
    {
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
    }

    public function updateStatus(Product $product, string $status): void
    {
        $previous = $product->status;

        DB::transaction(function () use ($product, $status, $previous) {
            $product->update(['status' => $status]);

            ActivityRecorder::record(
                action: 'product_status_updated',
                description: $status === 'active'
                    ? "Product {$product->name} activated."
                    : "Product {$product->name} deactivated.",
                subject: $product,
                old: ['status' => $previous],
                new: ['status' => $status],
            );
        });
    }

    public function delete(Product $product): void
    {
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

        $location = $this->products->firstOrCreateStockLocation($product);

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
}
