<?php

namespace App\Services\Management\Product;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductFileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The base ProductController's write workflows and its warehouse-assignment
 * rule.
 *
 * Every workflow keeps the exact write order the controller used — base save,
 * gallery removes, digital-file removes, gallery uploads, variant sync,
 * digital-file uploads — with the same DB::transaction boundary around them.
 * The transaction belongs to this layer, never to the repository. The
 * controller keeps the HTTP shape: status codes, message strings and the
 * envelope.
 *
 * Deliberate details carried over, not "fixed":
 *
 * 1. A first gallery upload lands non-primary: after a null `max(position)`
 *    the next position is 1, so the `position === 0` test can never be true.
 *    Legacy behaved the same way.
 * 2. `syncVariants()` always inserts (this slice never upserted): an update
 *    that sends `variants` deletes the old rows first, so ids churn.
 * 3. Deleting via `primary_image_id` that matches no row still clears every
 *    row's primary flag — there is no existence check, as before.
 * 4. The warehouse rule judges a physical product against the warehouses the
 *    caller can reach, and exists because the legacy form refused to save a
 *    physical product without one. Digital products are exempt: they hold no
 *    stock.
 */
final class ProductService
{
    /**
     * @param  array<string, mixed>  $data  the validated create payload
     */
    public function create(Request $request, User $user, array $data): Product
    {
        $isDigital = $request->boolean('is_digital');

        $data['business_id'] = $user->business_id;
        $data['is_digital'] = $isDigital;
        $data['featured'] = $request->boolean('featured');
        $data['is_taxable'] = $request->boolean('is_taxable', true);
        $data['cod_available'] = $isDigital ? false : $request->boolean('cod_available', true);
        $data['has_variants'] = $request->boolean('has_variants');

        return DB::transaction(function () use ($request, $data) {
            $product = Product::create($data);

            $this->syncImages($request, $product);
            $this->syncVariants($request, $product);

            if ($request->hasFile('digital_files')) {
                app(ProductFileService::class)->storeFiles($product, $request->file('digital_files'));
            }

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $data  the validated update payload, with
     *                                      the boolean flags the controller
     *                                      already normalised
     */
    public function update(Request $request, Product $product, array $data): void
    {
        DB::transaction(function () use ($request, $product, $data) {
            $product->update($data);

            if ($request->filled('delete_image_ids')) {
                foreach ($product->images()->whereIn('id', (array) $request->input('delete_image_ids'))->get() as $image) {
                    $this->deleteImageFile($image);
                    $image->delete();
                }
            }

            if ($request->filled('delete_file_ids')) {
                app(ProductFileService::class)->deleteFiles($product, (array) $request->input('delete_file_ids'));
            }

            $this->syncImages($request, $product);
            $this->syncVariants($request, $product, replace: $request->filled('variants'));

            if ($request->hasFile('digital_files')) {
                app(ProductFileService::class)->storeFiles($product, $request->file('digital_files'));
            }
        });
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

    /**
     * A physical product must belong to a warehouse the business can reach.
     *
     * The legacy form refused to save one without a warehouse ("Please assign
     * the product to a warehouse"), and the new API accepted it silently —
     * leaving products that receiving, transfers and stock counts could not
     * touch. Digital products are exempt: they hold no stock.
     *
     * This registration is shadowed by ProductFormController (modules load
     * last, and Laravel keys identical method+URI pairs by that key), so the
     * live contract is ProductFormService::assignmentError(). Both agree that
     * an unassigned product is refused; the live one no longer usually gets
     * the chance, because ProductFormService::withDefaultWarehouse() fills the
     * fallback in first.
     */
    public function warehouseAssignmentError(User $user, ?int $warehouseId, bool $isDigital): ?string
    {
        if ($isDigital) {
            return null;
        }

        if (! $warehouseId) {
            return 'Assign the product to a warehouse.';
        }

        $allowed = $user
            ->accessibleWarehouses()
            ->whereKey($warehouseId)
            ->exists();

        return $allowed ? null : 'Invalid warehouse selection.';
    }

    private function syncImages(Request $request, Product $product): void
    {
        if (! $request->hasFile('images')) {
            return;
        }

        $position = (int) $product->images()->max('position');
        $position = $position < 0 ? 0 : $position + 1;
        $hasPrimary = $product->images()->exists();

        foreach ($request->file('images') as $file) {
            $path = $file->store('products/images', 'public');

            ProductImage::create([
                'product_id' => $product->id,
                'path' => $path,
                'is_primary' => ! $hasPrimary && $position === 0,
                'position' => $position++,
            ]);

            $hasPrimary = true;
        }

        if ($request->filled('primary_image_id')) {
            $product->images()->update(['is_primary' => false]);
            $product->images()->where('id', $request->integer('primary_image_id'))->update(['is_primary' => true]);
        }
    }

    private function syncVariants(Request $request, Product $product, bool $replace = false): void
    {
        if (! $request->filled('variants')) {
            return;
        }

        if ($replace) {
            $product->variants()->delete();
        }

        foreach ((array) $request->input('variants', []) as $variant) {
            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $variant['sku'] ?? null,
                'color' => $variant['color'] ?? null,
                'size' => $variant['size'] ?? null,
                'quantity' => (int) ($variant['quantity'] ?? 0),
                'amount' => (float) ($variant['amount'] ?? 0),
                'status' => $variant['status'] ?? 'active',
                'featured' => false,
            ]);
        }
    }

    /**
     * The storage delete both image-removal paths used, moved as one copy. The
     * row goes regardless of the disk outcome, matching the inline handler.
     */
    private function deleteImageFile(ProductImage $image): void
    {
        try {
            Storage::disk('public')->delete($image->path);
        } catch (\Throwable $e) {
            // Non-fatal: the record is removed regardless.
        }
    }
}
