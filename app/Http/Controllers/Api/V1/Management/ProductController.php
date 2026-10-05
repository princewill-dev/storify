<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\ProductFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $storeIds = $this->accessibleStoreIds($request);

        $products = Product::query()
            ->where('business_id', $this->user($request)->business_id)
            ->whereIn('store_id', $storeIds)
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', $request->integer('store_id')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->boolean('digital_only'), fn ($q) => $q->where('is_digital', true))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('product_code', 'like', $term)
                    ->orWhere('brand', 'like', $term));
            })
            ->with(['images' => fn ($q) => $q->orderBy('position')])
            ->latest()
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $products->getCollection()->map(fn (Product $product) => $this->payload($product))->values()->all(),
            null,
            200,
            $this->paginationMeta($products)
        );
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $product->load(['images' => fn ($q) => $q->orderBy('position'), 'files', 'variants', 'category', 'store']);

        return $this->ok(['product' => $this->payload($product, detailed: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate($this->rules());

        $storeIds = $this->accessibleStoreIds($request);

        if (! in_array((int) $data['store_id'], $storeIds->all(), true)) {
            return $this->error('Invalid store selection.', 422);
        }

        $data['business_id'] = $user->business_id;
        $data['is_digital'] = $request->boolean('is_digital');
        $data['featured'] = $request->boolean('featured');
        $data['is_taxable'] = $request->boolean('is_taxable', true);
        $data['cod_available'] = $data['is_digital'] ? false : $request->boolean('cod_available', true);
        $data['has_variants'] = $request->boolean('has_variants');

        $product = DB::transaction(function () use ($request, $data) {
            $product = Product::create($data);

            $this->syncImages($request, $product);
            $this->syncVariants($request, $product);

            if ($request->hasFile('digital_files')) {
                app(ProductFileService::class)->storeFiles($product, $request->file('digital_files'));
            }

            return $product;
        });

        $product->load(['images', 'files', 'variants']);

        return $this->ok(['product' => $this->payload($product, detailed: true)], 'Product created.', 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $data = $request->validate($this->rules(forUpdate: true));

        if ($request->has('is_digital')) {
            $data['is_digital'] = $request->boolean('is_digital');
        }
        if ($request->has('featured')) {
            $data['featured'] = $request->boolean('featured');
        }
        if ($request->has('is_taxable')) {
            $data['is_taxable'] = $request->boolean('is_taxable');
        }
        if ($request->has('has_variants')) {
            $data['has_variants'] = $request->boolean('has_variants');
        }

        DB::transaction(function () use ($request, $product, $data) {
            $product->update($data);

            if ($request->filled('delete_image_ids')) {
                foreach ($product->images()->whereIn('id', (array) $request->input('delete_image_ids'))->get() as $image) {
                    try {
                        Storage::disk('public')->delete($image->path);
                    } catch (\Throwable $e) {
                    }
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

        $product->load(['images', 'files', 'variants']);

        return $this->ok(['product' => $this->payload($product->fresh(), detailed: true)], 'Product updated.');
    }

    public function updateStatus(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        $product->update(['status' => $data['status']]);

        return $this->ok(['product' => $this->payload($product->fresh())], 'Product status updated.');
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        DB::transaction(function () use ($product) {
            foreach ($product->images as $image) {
                try {
                    Storage::disk('public')->delete($image->path);
                } catch (\Throwable $e) {
                }
                $image->delete();
            }

            app(ProductFileService::class)->deleteAllFiles($product);
            $product->delete();
        });

        return $this->ok([], 'Product deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $forUpdate = false): array
    {
        $digitalMimes = implode(',', config('digital.allowed_mimes', ['pdf', 'zip']));
        $digitalMaxKb = (int) config('digital.max_upload_kb', 102400);
        $presence = $forUpdate ? 'sometimes' : 'required';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'store_id' => [$presence, 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'brand' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'amount' => [$presence, 'numeric', 'gt:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bulk_quantity' => ['nullable', 'integer', 'min:1'],
            'bulk_price' => ['nullable', 'numeric', 'min:0'],
            'is_digital' => ['sometimes', 'boolean'],
            'is_taxable' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'has_variants' => ['sometimes', 'boolean'],
            'cod_available' => ['sometimes', 'boolean'],
            'download_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'download_expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'images.*' => ['nullable', 'mimes:jpeg,jpg,png,gif,webp', 'max:20480'],
            'digital_files.*' => ["nullable", "file", "mimes:{$digitalMimes}", "max:{$digitalMaxKb}"],
            'delete_image_ids' => ['sometimes', 'array'],
            'delete_image_ids.*' => ['integer'],
            'delete_file_ids' => ['sometimes', 'array'],
            'delete_file_ids.*' => ['integer'],
            'primary_image_id' => ['nullable', 'integer'],
            'variants' => ['sometimes', 'array'],
            'variants.*.id' => ['sometimes', 'integer'],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            'variants.*.color' => ['nullable', 'string', 'max:100'],
            'variants.*.size' => ['nullable', 'numeric', 'min:0'],
            'variants.*.quantity' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.amount' => ['required_with:variants', 'numeric', 'gt:0'],
            'variants.*.status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
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
     * @return array<string, mixed>
     */
    private function payload(Product $product, bool $detailed = false): array
    {
        $data = [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'slug' => $product->slug,
            'brand' => $product->brand,
            'amount' => (float) $product->amount,
            'quantity' => (int) $product->quantity,
            'is_digital' => (bool) $product->is_digital,
            'is_taxable' => (bool) $product->is_taxable,
            'status' => $product->status,
            'featured' => (bool) $product->featured,
            'store_id' => $product->store_id,
            'category_id' => $product->category_id,
            'image_url' => $product->primaryImage()?->path
                ? asset('storage/'.$product->primaryImage()->path)
                : null,
            'created_at' => $product->created_at?->toISOString(),
        ];

        if ($detailed) {
            $data['description'] = $product->description;
            $data['cost_price'] = $product->cost_price !== null ? (float) $product->cost_price : null;
            $data['bulk_quantity'] = $product->bulk_quantity;
            $data['bulk_price'] = $product->bulk_price !== null ? (float) $product->bulk_price : null;
            $data['discount_percentage'] = $product->discount_percentage !== null ? (float) $product->discount_percentage : null;
            $data['download_limit'] = $product->download_limit;
            $data['download_expiry_days'] = $product->download_expiry_days;
            $data['variants'] = $product->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'amount' => (float) $variant->amount,
                'quantity' => (int) $variant->quantity,
                'status' => $variant->status,
            ])->values()->all();
            $data['files'] = $product->files->map(fn ($file) => [
                'id' => $file->id,
                'original_name' => $file->original_name,
                'size' => (int) $file->size,
                'formatted_size' => $file->formatted_size,
            ])->values()->all();
            $data['images'] = $product->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => asset('storage/'.$image->path),
                'is_primary' => (bool) $image->is_primary,
            ])->values()->all();
        }

        return $data;
    }

    private function authorizeProduct(Request $request, Product $product): void
    {
        $user = $this->user($request);

        if ((int) $product->business_id !== (int) $user->business_id
            || ! $user->accessibleStores()->whereKey($product->store_id)->exists()) {
            abort(403, 'You do not have access to this product.');
        }
    }
}
