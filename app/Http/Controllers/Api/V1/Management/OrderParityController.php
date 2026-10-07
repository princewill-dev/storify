<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\OrderIndexRequest;
use App\Http\Requests\Management\UpdateOrderRequest;
use App\Http\Resources\Management\OrderParityDetailResource;
use App\Http\Resources\Management\OrderParityStatsResource;
use App\Http\Resources\Management\OrderParitySummaryResource;
use App\Models\Order;
use App\Repositories\Management\OrderParityRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\OrderEditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-13 — orders list & detail parity.
 *
 * Lives beside OrderController because that controller's list/show/status
 * endpoints are shared with other workstreams and must not change shape. This
 * one carries what the legacy screens rendered and the base payloads dropped:
 * item counts, payment-method label, split-payment legs, delivery tracking,
 * activity timeline, bank proof-of-payment, and the order edit slice (pricing
 * and notes with a server-side total recompute).
 *
 * The shared route file binds orders/{order} before feature modules load, so
 * these endpoints deliberately sit on their own paths (orders/board/list,
 * orders/{order}/detail, …) rather than shadowing the base ones. The SPA is
 * the only consumer of the base list, so it reads this deeper payload instead.
 *
 * The list query, status aggregate, detail eager loads and activity feed live
 * in OrderParityRepository, the edit workflow with its transaction boundary in
 * OrderEditService, and the payloads in OrderParitySummaryResource,
 * OrderParityDetailResource and OrderParityStatsResource; this controller keeps
 * the HTTP contract (status codes, message strings, envelope, pagination meta)
 * and the guards.
 */
class OrderParityController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly OrderParityRepository $repository,
        private readonly OrderEditService $editor,
        private readonly TenantGuard $tenantGuard,
    ) {}

    public function index(OrderIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        // Legacy's per-store route filtered on the store *code* string while
        // the column holds the numeric id, so the filter silently matched
        // nothing. The id is validated against the stores this user can reach
        // and a foreign id is refused rather than quietly returning an empty
        // page.
        if (isset($filters['store_id'])) {
            $this->authorizeStoreId($request, (int) $filters['store_id']);
        }

        $orders = $this->repository->listQuery($this->user($request), $filters)
            ->paginate($filters['per_page'] ?? 20);

        return $this->ok(
            ['orders' => OrderParitySummaryResource::collection($orders->getCollection())->resolve($request)],
            null,
            200,
            $this->paginationMeta($orders),
        );
    }

    /**
     * The nine legacy status counters; the payload shape lives in
     * OrderParityStatsResource.
     */
    public function stats(Request $request): JsonResponse
    {
        $counts = $this->repository->statusCounts($this->user($request));

        return $this->ok(['stats' => (new OrderParityStatsResource($counts))->resolve($request)]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        return $this->ok(['order' => $this->detail($request, $order)]);
    }

    public function edit(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        return $this->ok(['order' => $this->detail($request, $order)]);
    }

    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $this->editor->update($order, $request->validated(), $request);

        return $this->ok(['order' => $this->detail($request, $order->fresh())], 'Order updated.');
    }

    /**
     * Detail read model for one order: the relations are eager-loaded by the
     * repository and the timeline handed to the resource.
     *
     * @return array<string, mixed>
     */
    private function detail(Request $request, Order $order): array
    {
        $order = $this->repository->loadDetail($order);

        return (new OrderParityDetailResource($order, $this->repository->recentActivity($order)))->resolve($request);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $this->tenantGuard->authorizeBusinessAndStore(
            $order,
            $this->user($request),
            (int) $order->store_id,
            'You do not have access to this order.',
        );
    }

    private function authorizeStoreId(Request $request, int $storeId): void
    {
        $this->tenantGuard->authorizeStoreId(
            $this->user($request),
            $storeId,
            'You do not have access to this store.',
        );
    }
}
