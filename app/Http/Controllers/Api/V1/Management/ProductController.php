<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Product\StoreProductRequest;
use App\Http\Requests\Management\Product\UpdateProductRequest;
use App\Http\Requests\Management\Product\UpdateProductStatusRequest;
use App\Http\Resources\Management\Product\ProductDetailResource;
use App\Http\Resources\Management\Product\ProductResource;
use App\Models\Product;
use App\Repositories\Management\Product\ProductRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\Product\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The base management products API — list, show, create, update, status and
 * delete.
 *
 * Layering: the HTTP shape (status codes, message strings, the envelope,
 * pagination meta) stays here; the create/update/status payload rules live in
 * the Management\Product FormRequests, the list query in
 * App\Repositories\Management\Product\ProductRepository, the write workflows
 * and their transaction boundaries in App\Services\Management\Product\ProductService,
 * and the two row shapes in App\Http\Resources\Management\Product\ProductResource
 * / ProductDetailResource.
 *
 * Every URI this controller declares is re-registered later by the WS-14 and
 * WS-25 modules (ProductFormController / ProductListController), which load
 * after this group's own routes, so at runtime those richer handlers serve the
 * requests; this slice's methods remain the in-repo fallback (see
 * routes/api/v1/management/ws14-product-form.php) and keep their own contract —
 * the layer docblocks spell out where the two disagree — rather than being
 * converged into that work.
 *
 * Provenance kept with the code it explains:
 *  - the create store check stays a 422 "Invalid store selection." — a foreign
 *    or deleted store id must not read as "exists but forbidden" (deliberate
 *    anti-id-probing), so it lives in the controller body and not in the
 *    FormRequest's rules;
 *  - the product guard stays in the controller body so its 403 keeps its place
 *    in the refusal order (route-binding 404 first), rather than moving into
 *    FormRequest::authorize();
 *  - the index deliberately has no FormRequest: this slice never validated its
 *    list filters, and rules would turn inputs it used to answer (per_page=500
 *    reaching the paginator) into 422s.
 */
class ProductController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly ProductRepository $repository,
        private readonly ProductService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // The same filled()/integer()/string()/boolean() reading the inline
        // query used, so a non-numeric store_id or a blank q keeps the meaning
        // it had before the query moved to the repository.
        $filters = [
            'store_id' => $request->filled('store_id') ? $request->integer('store_id') : null,
            'warehouse_id' => $request->filled('warehouse_id') ? $request->integer('warehouse_id') : null,
            'status' => $request->filled('status') ? (string) $request->string('status') : null,
            'category_id' => $request->filled('category_id') ? $request->integer('category_id') : null,
            'digital_only' => $request->boolean('digital_only'),
            'q' => $request->filled('q') ? (string) $request->string('q') : null,
            'per_page' => $request->integer('per_page', 20),
        ];

        $products = $this->repository->paginateForUser($this->user($request), $filters);

        return $this->ok(
            ProductResource::collection($products->getCollection())->resolve(),
            null,
            200,
            $this->paginationMeta($products)
        );
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $product->load(['images' => fn ($q) => $q->orderBy('position'), 'files', 'variants', 'category', 'store']);

        return $this->ok(['product' => ProductDetailResource::make($product)->resolve()]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validated();

        $storeIds = $this->accessibleStoreIds($request);

        // 422 by design, not 403 — see the class docblock.
        if (! in_array((int) $data['store_id'], $storeIds->all(), true)) {
            return $this->error('Invalid store selection.', 422);
        }

        if ($message = $this->service->warehouseAssignmentError($user, $data['warehouse_id'] ?? null, $request->boolean('is_digital'))) {
            return $this->error($message, 422, ['warehouse_id' => [$message]]);
        }

        $product = $this->service->create($request, $user, $data);

        $product->load(['images', 'files', 'variants']);

        return $this->ok(['product' => ProductDetailResource::make($product)->resolve()], 'Product created.', 201);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $data = $request->validated();

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

        // Judge the product as it will be after this update, not as it is now.
        $isDigital = $data['is_digital'] ?? (bool) $product->is_digital;
        $warehouseId = array_key_exists('warehouse_id', $data) ? $data['warehouse_id'] : $product->warehouse_id;

        if ($message = $this->service->warehouseAssignmentError($this->user($request), $warehouseId, $isDigital)) {
            return $this->error($message, 422, ['warehouse_id' => [$message]]);
        }

        $this->service->update($request, $product, $data);

        $product->load(['images', 'files', 'variants']);

        return $this->ok(['product' => ProductDetailResource::make($product->fresh())->resolve()], 'Product updated.');
    }

    public function updateStatus(UpdateProductStatusRequest $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $data = $request->validated();

        $product->update(['status' => $data['status']]);

        return $this->ok(['product' => ProductResource::make($product->fresh())->resolve()], 'Product status updated.');
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $this->service->delete($product);

        return $this->ok([], 'Product deleted.');
    }

    /**
     * The business + reachable-store shape TenantGuard already encodes, with
     * the same check order and the same 403 message the private method it
     * replaces carried.
     */
    private function authorizeProduct(Request $request, Product $product): void
    {
        app(TenantGuard::class)->authorizeBusinessAndStore(
            $product,
            $this->user($request),
            (int) $product->store_id,
            'You do not have access to this product.',
        );
    }
}
