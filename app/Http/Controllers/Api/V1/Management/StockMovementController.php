<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\StockMovementIndexRequest;
use App\Http\Resources\Management\StockMovementIndexResource;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\Management\StockMovementRepository;
use Illuminate\Http\JsonResponse;

/**
 * WS-29 — the read side of the stock ledger (audit §3.3).
 *
 * POS sales, storefront checkout, order returns and transfer dispatch/receive
 * already write `StockMovement` rows with balances, references and the acting
 * user; there was no way to read them. Legacy only ever showed a fixed list of
 * the latest 20 on a warehouse's Activity tab — this endpoint keeps that
 * shape (signed quantity, balance before/after, performed-by) and adds the
 * paging and filters the legacy screen never had.
 *
 * The layers under that read model: the filters validate in
 * StockMovementIndexRequest, the scoped query — business/location tenancy,
 * filters, eager loads, ordering, pagination and the filter-picker options —
 * lives in App\Repositories\Management\StockMovementRepository, and the
 * payloads in StockMovementResource / StockMovementIndexResource. This
 * controller keeps the HTTP shape: the from/to cross-check, the access guards,
 * the envelope, the status code and the pagination meta. No service was
 * extracted: this is a read-only, single-table read model with no transaction,
 * no workflow and no side effect.
 *
 * Two pieces deliberately stay in this body, both so their order is unchanged:
 * the `from` > `to` cross-check (it answers with the ApiController envelope,
 * after the rules) and the warehouse/store/product access guards — 403s that
 * must not move into FormRequest::authorize(), which would run them before the
 * rules.
 */
class StockMovementController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(private readonly StockMovementRepository $movements) {}

    public function index(StockMovementIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
            return $this->error('The from date must be on or before the to date.', 422);
        }

        $user = $this->user($request);

        $warehouse = $this->optionalWarehouse($user, $filters['warehouse_id'] ?? null);
        $store = $this->optionalStore($user, $filters['store_id'] ?? null);
        $product = isset($filters['product_id']) ? $this->resolveProduct($user, (int) $filters['product_id']) : null;

        $page = $this->movements->paginateForUser($user, $filters, $warehouse, $store, $product);
        $options = $this->movements->filterLocations($user);

        return $this->ok(
            (new StockMovementIndexResource($page->getCollection(), $options['warehouses'], $options['stores']))->resolve($request),
            null,
            200,
            $this->paginationMeta($page),
        );
    }

    /**
     * A warehouse id or code outside the user's circle is refused with the
     * pre-refactor 403, not silently emptied. The tenancy-scoped lookup lives
     * in the repository; the refusal stays here.
     */
    private function optionalWarehouse(User $user, mixed $value): ?Warehouse
    {
        if ($value === null || $value === '') {
            return null;
        }

        $warehouse = $this->movements->findAccessibleWarehouse($user, $value);

        if (! $warehouse) {
            abort(403, 'You do not have access to this warehouse.');
        }

        return $warehouse;
    }

    /**
     * Same contract as optionalWarehouse(), matching the store code column.
     */
    private function optionalStore(User $user, mixed $value): ?Store
    {
        if ($value === null || $value === '') {
            return null;
        }

        $store = $this->movements->findAccessibleStore($user, $value);

        if (! $store) {
            abort(403, 'You do not have access to this store.');
        }

        return $store;
    }

    private function resolveProduct(User $user, int $id): Product
    {
        $product = $this->movements->findBusinessProduct($user, $id);

        if (! $product) {
            abort(403, 'You do not have access to this product.');
        }

        return $product;
    }
}
