<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\StoreCustomerIndexRequest;
use App\Http\Resources\Management\StoreCustomerResource;
use App\Models\Store;
use App\Repositories\Management\StoreCustomerRepository;
use Illuminate\Http\JsonResponse;

/**
 * WS-19 — the store detail "Customers" tab API (legacy
 * `Management\StoreTabController@customers`).
 *
 * The legacy tab listed the distinct customers who had bought from a store,
 * each with a per-store order count and a "unique buyers" metric. The store
 * detail UI is WS-27's; this endpoint is the data it consumes.
 *
 * It also carries the count `StoreController@show` has always emitted as null:
 * the Store model has no `customers` relation, so the payload is the only
 * place that number can come from without editing that controller.
 *
 * Layering: the filters and their status normalisation live in
 * StoreCustomerIndexRequest, the buyers predicate and the list/total queries
 * in App\Repositories\Management\StoreCustomerRepository, and the row shape in
 * StoreCustomerResource. This controller keeps the HTTP contract — the store
 * guard, the envelope, the status code and the pagination meta. No service was
 * extracted: the endpoint is read-only, with no transaction, workflow or side
 * effect. The store block is envelope identity, not a row shape — three scalar
 * fields with only the `store_code` key naming to carry.
 */
class StoreCustomerController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(private readonly StoreCustomerRepository $repository) {}

    public function index(StoreCustomerIndexRequest $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $filters = $request->validated();

        $customers = $this->repository->paginateForStore($store, $filters);

        return $this->ok(
            [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'store_code' => $store->store_id,
                ],
                'customers' => StoreCustomerResource::collection($customers->getCollection())->resolve($request),
                'customers_count' => $this->repository->countBuyers($store),
            ],
            null,
            200,
            $this->paginationMeta($customers),
        );
    }
}
