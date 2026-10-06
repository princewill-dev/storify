<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\ProductIndexRequest;
use App\Http\Requests\Management\ProductStatusRequest;
use App\Http\Requests\Management\StoreProductRequest;
use App\Http\Requests\Management\UpdateProductRequest;
use App\Http\Resources\Management\ProductDetailResource;
use App\Http\Resources\Management\ProductFormOptionsResource;
use App\Http\Resources\Management\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Repositories\Management\ProductRepository;
use App\Services\Management\ProductFormService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
 *
 * The HTTP shape — status codes, message strings, the envelope, pagination
 * meta — is all that lives here: validation is in
 * app/Http/Requests/Management, queries in ProductRepository, the write
 * workflows and their transaction boundaries in ProductFormService, and
 * response shaping in ProductResource / ProductDetailResource /
 * ProductFormOptionsResource.
 */
class ProductFormController extends ApiController
{
    use ResolvesManagementContext;

    private ?string $defaultCurrency = null;

    public function __construct(
        private readonly ProductRepository $repository,
        private readonly ProductFormService $service,
    ) {}

    /**
     * The product list: filters, stock math, price display and currency, all
     * scoped to what the caller can reach. Store-less (warehouse-only) rows
     * are included rather than hidden — see the class docblock.
     *
     * At runtime WS-25's ProductListController re-registers the same
     * method+URI from its own module file, which loads later, so its superset
     * handler serves this URI; this method remains the in-repo fallback.
     */
    public function index(ProductIndexRequest $request): JsonResponse
    {
        $products = $this->repository->paginateForUser(
            $this->user($request),
            $request->validated(),
            $request->boolean('digital_only'),
        );

        return $this->ok(
            $products->getCollection()->map(fn (Product $product) => $this->productPayload($request, $product))->values()->all(),
            null,
            200,
            $this->paginationMeta($products),
        );
    }

    /**
     * The widened detail payload the edit view (and any future detail page)
     * renders — see ProductDetailResource for the fields.
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $product->load($this->repository->detailRelations());

        return $this->ok(['product' => $this->productPayload($request, $product, detailed: true)]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $isDigital = $request->boolean('is_digital');
        $hasVariants = $request->boolean('has_variants');

        $data = $request->validated();

        if ($error = $this->service->assignmentError($user, $data, null, $isDigital)) {
            return $this->error($this->firstError($error), 422, $error);
        }

        $product = $this->service->create($request, $user, $data, $hasVariants, $isDigital);

        return $this->ok(
            ['product' => $this->productPayload($request, $product->fresh()->load($this->repository->detailRelations()), detailed: true)],
            'Product created.',
            201,
        );
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $user = $this->user($request);
        $hasVariants = $request->has('has_variants') ? $request->boolean('has_variants') : (bool) $product->has_variants;
        $isDigital = $request->has('is_digital') ? $request->boolean('is_digital') : (bool) $product->is_digital;

        $data = $this->validatedInput(UpdateProductRequest::class);

        if ($error = $this->service->assignmentError($user, $data, $product, $isDigital)) {
            return $this->error($this->firstError($error), 422, $error);
        }

        $this->service->update($request, $user, $product, $data, $hasVariants, $isDigital);

        return $this->ok(
            ['product' => $this->productPayload($request, $product->fresh()->load($this->repository->detailRelations()), detailed: true)],
            'Product updated.',
        );
    }

    public function updateStatus(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $data = $this->validatedInput(ProductStatusRequest::class);

        $product->update(['status' => $data['status']]);

        return $this->ok(['product' => $this->productPayload($request, $product->fresh())], 'Product status updated.');
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $this->service->delete($product);

        return $this->ok([], 'Product deleted.');
    }

    /**
     * Everything the product form needs to render its pickers in one call:
     * stores, warehouses with their sections, categories, currencies, size and
     * weight units, and the digital-download defaults.
     */
    public function formOptions(Request $request): JsonResponse
    {
        $options = $this->repository->formOptions($this->user($request));

        return $this->ok((new ProductFormOptionsResource($options))->toArray($request));
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

        $this->service->setPrimaryImage($product, $image);

        return $this->ok([
            'product' => $this->productPayload($request, $product->fresh()->load($this->repository->detailRelations()), detailed: true),
        ], 'Primary image updated.');
    }

    public function destroyImage(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        if ((int) $image->product_id !== (int) $product->id) {
            abort(404, 'Image not found for this product.');
        }

        $this->service->destroyImage($product, $image);

        return $this->ok([
            'product' => $this->productPayload($request, $product->fresh()->load($this->repository->detailRelations()), detailed: true),
        ], 'Image removed.');
    }

    /**
     * Products the caller may manage: business scope, then a three-way
     * fallback — reachable store, reachable warehouse, or a fully detached row
     * for anyone who is not restricted staff.
     *
     * Deliberately not TenantGuard::allowsProduct(): that helper treats a
     * detached product as reachable only when it is digital, where these
     * legacy rows have always been manageable by non-restricted staff.
     * Merging the two would turn a working edit into a 403.
     */
    private function authorizeProduct(Request $request, Product $product): void
    {
        $user = $this->user($request);

        if ((int) $product->business_id !== (int) $user->business_id) {
            abort(403, 'You do not have access to this product.');
        }

        if ($product->store_id) {
            // Mirrors the legacy check: the store must be reachable and not
            // soft-deleted, or the product is out of scope.
            if ($this->repository->storeIds($user)->contains((int) $product->store_id)) {
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
     * Build a product payload, resolving the memoized currency fallback lazily
     * — a product that carries its own currency never runs the lookup, exactly
     * as the in-controller payload behaved.
     *
     * @return array<string, mixed>
     */
    private function productPayload(Request $request, Product $product, bool $detailed = false): array
    {
        $resource = $detailed
            ? new ProductDetailResource($product, fn (): string => $this->defaultCurrencyCode($request))
            : new ProductResource($product, fn (): string => $this->defaultCurrencyCode($request));

        return $resource->toArray($request);
    }

    /**
     * Request-scoped memo for the platform default currency.
     */
    private function defaultCurrencyCode(Request $request): string
    {
        return $this->defaultCurrency ??= $this->repository->defaultCurrencyCode($this->user($request));
    }

    /**
     * Resolve a FormRequest through the container so it runs against the
     * current request (FormRequestServiceProvider merges the bound request in
     * on resolution). Used where a guard must run before validation:
     * type-hinting the request would validate before the method body and
     * invert this controller's 403-before-422 order for products belonging to
     * another business.
     *
     * @param  class-string<FormRequest>  $requestClass
     * @return array<string, mixed>
     */
    private function validatedInput(string $requestClass): array
    {
        /** @var FormRequest $formRequest */
        $formRequest = app($requestClass);

        return $formRequest->validated();
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
