<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Warehouse\WarehouseIndexRequest;
use App\Http\Requests\Management\Warehouse\WarehousePayloadRequest;
use App\Http\Resources\Management\Warehouse\WarehouseDetailResource;
use App\Http\Resources\Management\Warehouse\WarehouseMovementResource;
use App\Http\Resources\Management\Warehouse\WarehouseStatsResource;
use App\Http\Resources\Management\Warehouse\WarehouseSummaryResource;
use App\Models\Warehouse;
use App\Repositories\Management\Warehouse\WarehouseRepository;
use App\Services\Management\Warehouse\WarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-35 — warehouses and the staff assigned to them.
 *
 * A warehouse carries a generated `warehouse_code`, an address block, an
 * active/inactive status and an assigned-staff pivot; it is soft-deleted by
 * flipping `status` to `deleted`, which every read here filters out.
 *
 * Layering: the HTTP shape (status codes, message strings, the envelope,
 * pagination meta), the authorization guards and the 422 refusal stay here;
 * the filters validate in App\Http\Requests\Management\Warehouse, the query
 * building and access scope in
 * App\Repositories\Management\Warehouse\WarehouseRepository, the write
 * workflows with their transaction boundaries and audit rows in
 * App\Services\Management\Warehouse\WarehouseService, and the payload shapes
 * in App\Http\Resources\Management\Warehouse.
 *
 * Three pieces deliberately stay in this body:
 *
 * 1. `authorizeWarehouse()` — the 403s must keep their place in the refusal
 *    order (route-binding 404 first, then the guard), so they must not move
 *    into FormRequest::authorize(). Extraction moves the rules ahead of this
 *    body instead, which is the codebase-wide accepted consequence.
 * 2. The delete's 422 when stock remains — an HTTP refusal in front of the
 *    write, and its check is a single relation predicate
 *    (`stockLocations()->where('quantity', '>', 0)->exists()`); wrapping that
 *    in the repository would be indirection with no benefit.
 * 3. The staff sync rule and the delete/create audit rows moved to the
 *    service with their comments, since they belong to the write workflow.
 */
class WarehouseController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly WarehouseRepository $repository,
        private readonly WarehouseService $service,
    ) {}

    public function index(WarehouseIndexRequest $request): JsonResponse
    {
        $warehouses = $this->repository->paginateForUser($this->user($request), $request->validated());

        return $this->ok(
            ['warehouses' => WarehouseSummaryResource::collection($warehouses->getCollection())->resolve($request)],
            null,
            200,
            $this->paginationMeta($warehouses),
        );
    }

    public function store(WarehousePayloadRequest $request): JsonResponse
    {
        $warehouse = $this->service->create($this->user($request), $request->validated());

        return $this->ok(['warehouse' => $this->detail($request, $warehouse)], 'Warehouse created.', 201);
    }

    public function show(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        // The show page's loads: stockLocations feed the stats and the
        // movements list; sections and assignedStaff feed the detail payload.
        $warehouse = $this->repository->loadForShow($warehouse);

        $movements = $this->repository->recentMovements($warehouse);

        return $this->ok([
            'warehouse' => $this->detail($request, $warehouse),
            'stats' => (new WarehouseStatsResource($warehouse))->resolve($request),
            'recent_movements' => WarehouseMovementResource::collection($movements)->resolve($request),
        ]);
    }

    public function update(WarehousePayloadRequest $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $this->service->update($warehouse, $request->validated());

        return $this->ok(['warehouse' => $this->detail($request, $warehouse->fresh())], 'Warehouse updated.');
    }

    public function destroy(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        if ($warehouse->stockLocations()->where('quantity', '>', 0)->exists()) {
            return $this->error('Move the remaining stock out of this warehouse before deleting it.', 422);
        }

        $this->service->delete($this->user($request), $warehouse);

        return $this->ok([], 'Warehouse deleted.');
    }

    /**
     * The warehouse detail payload — repository loads, resource shape.
     *
     * @return array<string, mixed>
     */
    private function detail(Request $request, Warehouse $warehouse): array
    {
        return (new WarehouseDetailResource($this->repository->loadForDetail($warehouse)))->resolve($request);
    }

    private function authorizeWarehouse(Request $request, Warehouse $warehouse): void
    {
        if (! $this->repository->userCanAccessWarehouse($this->user($request), $warehouse)) {
            abort(403, 'You do not have access to this warehouse.');
        }
    }
}
