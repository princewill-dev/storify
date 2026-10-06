<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\Admin\Concerns\SerializesAdminProducts;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListProductsRequest;
use App\Http\Requests\Admin\ProductWriteRequest;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Http\Requests\Admin\UpdateProductStatusRequest;
use App\Http\Resources\Admin\ProductFormOptionsResource;
use App\Models\Product;
use App\Models\Store;
use App\Repositories\Admin\ProductRepository;
use App\Services\Admin\ProductCatalogueService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

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
 *   instead of leaking "The images.0 failed to upload." Those pre-checks run
 *   here, before validation, so an oversized upload is never reported as a
 *   rule failure.
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
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin FormRequest
 * classes (resolved by {@see validateOrJson()} after the platform-admin guard
 * and the upload pre-checks, so the refusal order above is unchanged), the
 * queries in `ProductRepository`, the write workflows and their transaction
 * boundaries in `ProductCatalogueService`, and the form-options shaping in
 * `ProductFormOptionsResource`.
 */
class ProductController extends ApiController
{
    use EnsuresPlatformAdmin;
    use SerializesAdminProducts;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductCatalogueService $catalogue,
    ) {}

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

        $this->products->loadForDetail($product);

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

        $model = $this->products->findInStoreByCode($store, $product);

        $this->products->loadForDetail($model);

        return $this->ok(['product' => $this->productDetailPayload($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($problem = $this->uploadProblem($request)) {
            return $problem;
        }

        $data = $this->validateOrJson($request, StoreProductRequest::class);

        $store = $this->products->liveStore((int) $data['store_id']);

        if (! $store) {
            return $this->error('Choose a store that still exists.', 422, ['store_id' => ['Choose a store that still exists.']]);
        }

        if ($message = $this->categoryMismatch($data['category_id'] ?? null, $store->id)) {
            return $this->error($message, 422, ['category_id' => [$message]]);
        }

        $product = $this->catalogue->create($request, $data, $store);

        $this->products->loadForDetail($product);

        return $this->ok(['product' => $this->productDetailPayload($product)], 'Product created.', 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($problem = $this->uploadProblem($request)) {
            return $problem;
        }

        $data = $this->validateOrJson($request, UpdateProductRequest::class);

        $store = $product->store;

        if (array_key_exists('store_id', $data) && (int) $data['store_id'] !== (int) $product->store_id) {
            $store = $this->products->liveStore((int) $data['store_id']);

            if (! $store) {
                return $this->error('Choose a store that still exists.', 422, ['store_id' => ['Choose a store that still exists.']]);
            }
        }

        // Judge the category against the store the product will belong to.
        $categoryId = array_key_exists('category_id', $data) ? $data['category_id'] : $product->category_id;

        if ($message = $this->categoryMismatch($categoryId, $store?->id)) {
            return $this->error($message, 422, ['category_id' => [$message]]);
        }

        $this->catalogue->update($request, $product, $data, $store);

        $this->products->loadForDetail($product);

        return $this->ok(['product' => $this->productDetailPayload($product)], 'Product updated.');
    }

    public function updateStatus(Request $request, Product $product): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $this->validateOrJson($request, UpdateProductStatusRequest::class);

        $this->catalogue->updateStatus($product, $data['status']);

        $this->products->loadForDetail($product);

        return $this->ok(
            ['product' => $this->productListRow($product)],
            $data['status'] === 'active' ? 'Product activated.' : 'Product deactivated.',
        );
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->catalogue->delete($product);

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

        return $this->ok(
            (new ProductFormOptionsResource($this->products->formOptions(), ProductWriteRequest::IMAGE_MAX_KB))->resolve(),
        );
    }

    private function listResponse(Request $request, ?Store $store): JsonResponse
    {
        $filters = $this->validateOrJson($request, ListProductsRequest::class);

        $perPage = (int) ($filters['per_page'] ?? 10);

        $storeId = $store?->id;

        // The legacy URL-only store scope matches the numeric id or the public
        // `st_…` id.
        if ($storeId === null && ($filters['store_id'] ?? null) !== null && $filters['store_id'] !== '') {
            $storeId = $this->products->resolveStoreId((string) $filters['store_id']);

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
        }

        $products = $this->products->paginateCatalogue($filters, $storeId, $perPage);

        return $this->ok(
            $products->getCollection()->map(fn (Product $product) => $this->productListRow($product))->values()->all(),
            null,
            200,
            $this->paginationMeta($products),
        );
    }

    /**
     * A category can only be attached to its own store — legacy accepted any
     * category id, so a product could sit in a category the storefront never
     * rendered. The message is the API contract; the predicate lives in the
     * repository.
     */
    private function categoryMismatch(?int $categoryId, ?int $storeId): ?string
    {
        if (! $categoryId || ! $storeId) {
            return null;
        }

        return $this->products->categoryBelongsToStore($categoryId, $storeId)
            ? null
            : 'The selected category does not belong to this store.';
    }

    /**
     * Validate like `Request::validate()`, but make sure an API client that
     * did not negotiate JSON still gets the `{message, errors}` envelope.
     * Laravel's default validation failure redirects the caller back to a web
     * form that does not exist for this API — which is what a plain multipart
     * upload (no `Accept: application/json`) hits when an image is rejected
     * or a field fails its rule. Requests that do ask for JSON keep the
     * framework's native rendering.
     *
     * The rules and the custom messages live in the Admin FormRequest classes
     * (one per payload). The class is resolved here rather than type-hinted on
     * the action because the platform-admin guard and the upload pre-checks
     * above must run first: a type-hinted FormRequest is validated by the
     * container before the controller body runs, which would reorder the
     * documented refusals (403 before 422; human-readable upload copy before
     * rule failures).
     *
     * @param  class-string<FormRequest>  $requestClass
     * @return array<string, mixed>
     */
    private function validateOrJson(Request $request, string $requestClass): array
    {
        try {
            return app($requestClass)->validated();
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            throw new HttpResponseException(
                $this->error($exception->getMessage(), 422, $exception->errors()),
            );
        }
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
