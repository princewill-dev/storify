<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\ProductBulkDeleteRequest;
use App\Http\Requests\Management\ProductBulkStatusRequest;
use App\Http\Requests\Management\ProductBulkUpdateRequest;
use App\Http\Requests\Management\ProductListIndexRequest;
use App\Http\Resources\Management\ProductListResource;
use App\Models\Product;
use App\Repositories\Management\ProductListRepository;
use App\Services\Management\ProductBulkService;
use Illuminate\Http\JsonResponse;

/**
 * WS-25 — Products List, Bulk Actions & Detail.
 *
 * The screens this serves: the All Products table (row selection, filters,
 * per-page, low-stock highlight, variant price range and discount display) and
 * the three bulk workflows legacy had and the new stack had lost — bulk edit
 * (price/stock/status), bulk activate/deactivate and bulk delete with image and
 * digital-file cleanup (audit gaps #1, #2, #3, #4).
 *
 * This controller owns the `GET products` URI, re-registered from the route
 * module so the list gains the filters legacy supported server-side but never
 * exposed (`from`/`to`, the 10/50/100 per-page whitelist) plus the low-stock
 * and section filters the screens need. Its row payload is a strict superset of
 * the WS-14 list payload, so every existing consumer keeps working; the
 * detail/create/edit payloads stay with ProductFormController.
 *
 * Two legacy behaviours are deliberately not carried over:
 *
 * 1. Legacy's bulk edit took `$request->input('products')` and matched ids with
 *    a bare `whereIn`, so a parallel request could tick a row between the check
 *    and the write. Every row here goes through the same per-product access
 *    check as a single edit, inside one transaction.
 * 2. Legacy's bulk paths were silent about ids the caller could not reach, and
 *    "0 product(s) updated" gave no way to tell "nothing changed" from "nothing
 *    of mine was in that list". Every response reports what was skipped.
 *
 * Layer map: this class keeps the HTTP shape — status codes, message strings,
 * the envelope and pagination meta. Validation lives in
 * app/Http/Requests/Management, query building and the access scope in
 * ProductListRepository, the bulk write workflows with their transaction
 * boundaries in ProductBulkService, and response shaping in
 * ProductListResource.
 */
class ProductListController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly ProductListRepository $repository,
        private readonly ProductBulkService $bulk,
    ) {}

    /**
     * The product list: legacy's filters, the two it supported but never
     * surfaced, and the stock/price display fields the table columns need.
     */
    public function index(ProductListIndexRequest $request): JsonResponse
    {
        $page = $this->repository->paginateForUser(
            $this->user($request),
            $request->validated(),
            $request->boolean('digital_only'),
            // Presence, not truthiness: `has_variants=0` filters on false, an
            // absent parameter does not filter at all.
            $request->has('has_variants') ? $request->boolean('has_variants') : null,
            $request->boolean('low_stock'),
        )->withQueryString();

        return $this->ok(
            $page->getCollection()->map(fn (Product $product) => (new ProductListResource($product))->toArray($request))->values()->all(),
            null,
            200,
            $this->paginationMeta($page) + ['low_stock_threshold' => ProductListRepository::LOW_STOCK_THRESHOLD],
        );
    }

    /**
     * Bulk edit — legacy `bulkUpdate`. Each row carries its own optional
     * amount/quantity/stock_quantity/status; blank fields leave the product as
     * it is, exactly as the legacy modal left untouched inputs alone.
     */
    public function bulkUpdate(ProductBulkUpdateRequest $request): JsonResponse
    {
        $data = $request->validated();

        $requestedIds = collect($data['products'])->pluck('id')->map(fn ($id) => (int) $id)->unique();

        // One query, scoped to what the caller can actually reach; anything
        // else is reported back as skipped rather than silently dropped.
        $products = $this->repository->productsForUpdate($this->user($request), $requestedIds)->keyBy('id');

        $skipped = $requestedIds->diff($products->keys()->map(fn ($id) => (int) $id))->values()->all();

        $result = $this->bulk->applyEdits($products, $data['products']);

        $message = $result['updated_ids'] === [] && $result['rejected'] !== []
            ? 'No products were updated.'
            : count($result['updated_ids']).' product(s) updated successfully.';

        return $this->ok([
            'updated' => count($result['updated_ids']),
            'updated_ids' => $result['updated_ids'],
            'skipped_ids' => $skipped,
            'rejected' => $result['rejected'],
        ], $message);
    }

    /**
     * Bulk activate/deactivate — legacy `bulkStatus`.
     */
    public function bulkStatus(ProductBulkStatusRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $this->user($request);

        $requestedIds = collect($data['product_ids'])->map(fn ($id) => (int) $id)->unique();

        // Read the reachable set first and report the count from it: a mass
        // update() only returns rows whose value actually changed, so an
        // already-active selection would answer "0 product(s) activated".
        $ids = $this->repository->reachableIds($user, $requestedIds);

        // The ids were read through the access scope above; the business scope
        // on the write keeps a row that changed hands in between out of reach.
        $this->bulk->applyStatus($user, $ids, $data['status']);

        $label = $data['status'] === 'active' ? 'activated' : 'deactivated';

        return $this->ok([
            'updated' => $ids->count(),
            'updated_ids' => $ids->map(fn ($id) => (int) $id)->values()->all(),
            'skipped_ids' => $requestedIds->diff($ids->map(fn ($id) => (int) $id))->values()->all(),
        ], $ids->count()." product(s) {$label}.");
    }

    /**
     * Bulk delete — legacy `bulkDestroy`. Stored images and digital files go
     * with the row, and the delete runs in a transaction so a failure part-way
     * cannot leave a half-cleaned catalog.
     */
    public function bulkDelete(ProductBulkDeleteRequest $request): JsonResponse
    {
        $data = $request->validated();

        $requestedIds = collect($data['product_ids'])->map(fn ($id) => (int) $id)->unique();

        $products = $this->repository->productsForDelete($this->user($request), $requestedIds);

        $deletedIds = $this->bulk->deleteProducts($products);

        return $this->ok([
            'deleted' => count($deletedIds),
            'deleted_ids' => $deletedIds,
            'skipped_ids' => $requestedIds->diff($deletedIds)->values()->all(),
        ], count($deletedIds).' product(s) deleted.');
    }
}
