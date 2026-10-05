<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use App\Models\Category;
use App\Models\DeliveryRoute;
use App\Models\Product;
use App\Models\Service;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends ApiController
{
    use ResolvesStorefrontContext;

    public function home(string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $featured = Product::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->where('featured', true)
            ->with(['images' => fn ($q) => $q->orderBy('position')])
            ->latest()
            ->limit(8)
            ->get();

        $categories = Category::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->withCount('products')
            ->orderBy('name')
            ->limit(12)
            ->get();

        return $this->ok([
            'store' => $this->storePayload($store),
            'featured_products' => $featured->map(fn (Product $product) => $this->productPayload($product))->values()->all(),
            'categories' => $categories->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'products_count' => $category->products_count,
            ])->values()->all(),
        ]);
    }

    public function products(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $products = Product::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('brand', 'like', $term)
                    ->orWhere('product_code', 'like', $term));
            })
            ->when($request->string('sort') === 'price_asc', fn ($q) => $q->orderBy('amount'))
            ->when($request->string('sort') === 'price_desc', fn ($q) => $q->orderByDesc('amount'))
            ->when(! $request->filled('sort'), fn ($q) => $q->latest())
            ->with(['images' => fn ($q) => $q->orderBy('position')])
            ->paginate((int) $request->integer('per_page', 24));

        return $this->ok(
            $products->getCollection()->map(fn (Product $product) => $this->productPayload($product))->values()->all(),
            null,
            200,
            $this->paginationMeta($products)
        );
    }

    public function show(string $store, string $slugOrCode): JsonResponse
    {
        $store = $this->resolveStore($store);

        $code = str_contains($slugOrCode, '-') ? substr($slugOrCode, strrpos($slugOrCode, '-') + 1) : $slugOrCode;

        $product = Product::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('slug', $slugOrCode)->orWhere('product_code', $slugOrCode)->orWhere('product_code', $code))
            ->with(['images' => fn ($q) => $q->orderBy('position'), 'files', 'variants', 'category'])
            ->firstOrFail();

        $related = Product::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->where('id', '!=', $product->id)
            ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
            ->with(['images' => fn ($q) => $q->orderBy('position')])
            ->limit(4)
            ->get();

        return $this->ok([
            'product' => $this->productPayload($product, detailed: true),
            'related' => $related->map(fn (Product $product) => $this->productPayload($product))->values()->all(),
        ]);
    }

    public function categories(string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        return $this->ok([
            'categories' => Category::query()
                ->where('store_id', $store->id)
                ->where('status', 'active')
                ->withCount('products')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'products_count' => $category->products_count,
                ])->values()->all(),
        ]);
    }

    public function services(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $services = Service::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->with(['images', 'currency'])
            ->latest()
            ->paginate((int) $request->integer('per_page', 24));

        return $this->ok(
            $services->getCollection()->map(fn (Service $service) => $this->servicePayload($service))->values()->all(),
            null,
            200,
            $this->paginationMeta($services)
        );
    }

    public function serviceShow(string $store, string $slugOrCode): JsonResponse
    {
        $store = $this->resolveStore($store);

        $code = str_contains($slugOrCode, '-') ? substr($slugOrCode, strrpos($slugOrCode, '-') + 1) : $slugOrCode;

        $service = Service::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('slug', $slugOrCode)->orWhere('service_code', $slugOrCode)->orWhere('service_code', $code))
            ->with(['images', 'currency'])
            ->firstOrFail();

        return $this->ok(['service' => $this->servicePayload($service, detailed: true)]);
    }

    public function deliveryRoutes(string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        return $this->ok([
            'delivery_routes' => DeliveryRoute::query()
                ->where('store_id', $store->id)
                ->where('active', true)
                ->get()
                ->map(fn (DeliveryRoute $route) => [
                    'id' => $route->id,
                    'state' => $route->state,
                    'area' => $route->area,
                    'fee' => (int) $route->fee,
                    'delivery_days' => $route->delivery_days,
                ])->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function servicePayload(Service $service, bool $detailed = false): array
    {
        $data = [
            'id' => $service->id,
            'service_code' => $service->service_code,
            'name' => $service->name,
            'slug' => $service->slug,
            'amount' => (float) $service->amount,
            'currency' => $service->currency?->symbol ?? '₦',
            'image_url' => $service->primaryImage()?->path
                ? asset('storage/'.$service->primaryImage()->path)
                : null,
        ];

        if ($detailed) {
            $data['description'] = $service->description;
            $data['images'] = $service->images->map(fn ($image) => asset('storage/'.$image->path))->values()->all();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(Store $store): array
    {
        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'slug' => $store->slug,
            'description' => $store->description,
            'address' => $store->address,
            'support_email' => $store->support_email,
            'support_phone' => $store->support_phone,
            'logo_url' => $store->logo_path ? asset('storage/'.$store->logo_path) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(Product $product, bool $detailed = false): array
    {
        $data = [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'slug' => $product->slug,
            'brand' => $product->brand,
            'amount' => (float) $product->amount,
            'is_digital' => (bool) $product->is_digital,
            'in_stock' => $product->is_digital || (int) $product->quantity > 0,
            'quantity' => (int) $product->quantity,
            'image_url' => $product->primaryImage()?->path
                ? asset('storage/'.$product->primaryImage()->path)
                : null,
        ];

        if ($detailed) {
            $data['description'] = $product->description;
            $data['is_taxable'] = (bool) $product->is_taxable;
            $data['images'] = $product->images->map(fn ($image) => asset('storage/'.$image->path))->values()->all();
            $data['variants'] = $product->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'color' => $variant->color,
                'amount' => (float) $variant->amount,
                'in_stock' => (int) $variant->quantity > 0,
            ])->values()->all();
        }

        return $data;
    }
}
