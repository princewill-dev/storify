<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\DeliveryRoute;
use App\Models\OrderDelivery;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WS-26 — dispatches board.
 *
 * The legacy screen (`Management\DispatchesController@index`) was a read-only
 * board over `OrderDelivery`: four metric cards, a free-text search across
 * driver / tracking / order number, a filter modal (status, store, date range)
 * with an active-filter count, and a table whose rows link back to the order.
 * This controller is the same read model — legacy never advanced delivery
 * statuses from the screen (only order fulfilment actions produced them), so
 * nothing here mutates. Rows are created by WS-12's dispatch action.
 *
 * Scoping note: legacy computed its metric cards from a raw business-wide
 * `OrderDelivery` query, so restricted staff saw counts for stores they cannot
 * open. Every read here runs through `accessibleQuery()`, which applies both
 * the business and the accessible-store scope.
 */
class DispatchController extends ApiController
{
    use ResolvesManagementContext;

    /**
     * The eight delivery states the legacy filter modal offered. The column
     * comment on `order_deliveries.status` lists the same set.
     */
    public const STATUSES = [
        'pending',
        'assigned',
        'picked_up',
        'in_transit',
        'out_for_delivery',
        'delivered',
        'failed',
        'returned',
    ];

    /** Legacy grouped these two as "Pending". */
    private const PENDING_STATUSES = ['pending', 'assigned'];

    /** Legacy grouped these three as "In Transit". */
    private const IN_TRANSIT_STATUSES = ['picked_up', 'in_transit', 'out_for_delivery'];

    /** Legacy grouped these two as its computed-but-unused "Failed" counter. */
    private const FAILED_STATUSES = ['failed', 'returned'];

    /** A delivery is "open" while it is in none of these. Drives WS-34's nav badge. */
    private const CLOSED_STATUSES = ['delivered', 'failed', 'returned'];

    /**
     * The board: filterable delivery rows plus the metric cards and the
     * filter-modal option lists the legacy view received from its controller.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            // Legacy's search box posted `search`; accept it as an alias so the
            // endpoint is shape-compatible with old bookmarks and integrations.
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'store_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Legacy's filter modal named these date_from/date_to.
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // A store id is only ever trusted after checking it against the stores
        // this user can reach — a foreign id is refused, not silently emptied.
        if (! empty($filters['store_id'])) {
            $this->authorizeStoreId($request, (int) $filters['store_id']);
        }

        $search = $filters['q'] ?? $filters['search'] ?? null;
        $from = $filters['from'] ?? $filters['date_from'] ?? null;
        $to = $filters['to'] ?? $filters['date_to'] ?? null;

        $dispatches = $this->accessibleQuery($request)
            ->with([
                'order:id,order_number,status,store_id,customer_id,source,total',
                'order.store:id,name',
                'order.customer:id,first_name,last_name',
                'deliveryRoute:id,area,state',
            ])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->whereHas(
                'order',
                fn ($order) => $order->where('store_id', $storeId),
            ))
            ->when($search, function ($q, $term) {
                $like = '%'.$term.'%';

                // Legacy's search matched driver name, tracking number and the
                // linked order number.
                $q->where(fn ($inner) => $inner->where('driver_name', 'like', $like)
                    ->orWhere('tracking_number', 'like', $like)
                    ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', $like)));
            })
            ->when($from, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($to, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            [
                'dispatches' => $dispatches->getCollection()
                    ->map(fn (OrderDelivery $delivery) => $this->summary($delivery))
                    ->all(),
                'stats' => $this->stats($request),
                'stores' => $this->storeOptions($request),
                'statuses' => array_map(
                    fn (string $status) => ['value' => $status, 'label' => $this->label($status)],
                    self::STATUSES,
                ),
            ],
            null,
            200,
            $this->paginationMeta($dispatches),
        );
    }

    /**
     * Deliveries for every store this user can reach. Excluding soft-deleted
     * orders via the relation also keeps a deleted order's delivery off the
     * board, the way WS-06 keeps deleted warehouses out of its lists.
     */
    private function accessibleQuery(Request $request): Builder
    {
        return OrderDelivery::query()
            ->where('business_id', $this->user($request)->business_id)
            ->whereHas('order', fn (Builder $order) => $order->whereIn('store_id', $this->accessibleStoreIds($request)));
    }

    /**
     * The legacy metric cards, business-wide (not narrowed by the active
     * filters — same as the orders board), but store-scoped for restricted
     * staff. `open` follows the legacy sidebar badge definition
     * (`status NOT IN (delivered, failed, returned)`) for WS-34 to consume.
     *
     * @return array<string, int>
     */
    private function stats(Request $request): array
    {
        $query = $this->accessibleQuery($request);

        $counts = (clone $query)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $sum = fn (array $statuses) => (int) array_sum(
            array_map(fn (string $status) => (int) ($counts[$status] ?? 0), $statuses),
        );

        $total = (int) $counts->sum();
        $failed = $sum(self::FAILED_STATUSES);

        return [
            'total' => $total,
            'pending' => $sum(self::PENDING_STATUSES),
            'in_transit' => $sum(self::IN_TRANSIT_STATUSES),
            // Legacy measured this on the actual delivery time, not the status
            // change time — a dispatch delivered yesterday but touched today
            // must not count.
            'delivered_today' => (clone $query)
                ->where('status', 'delivered')
                ->whereDate('actual_delivery_at', today())
                ->count(),
            // Computed by legacy and never rendered; the board surfaces it.
            'failed' => $failed,
            'open' => $total - $sum(self::CLOSED_STATUSES),
        ];
    }

    /**
     * Filter-modal store list — the same accessible, non-deleted stores legacy
     * passed to its view.
     *
     * @return array<int, array<string, mixed>>
     */
    private function storeOptions(Request $request): array
    {
        return $this->user($request)->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            // Qualified because restricted staff read this through the
            // staff_assignments pivot, whose own `id` would be ambiguous.
            ->get(['stores.id', 'stores.name'])
            ->map(fn (Store $store) => ['id' => $store->id, 'name' => $store->name])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(OrderDelivery $delivery): array
    {
        $order = $delivery->order;
        $status = $order?->status instanceof OrderStatus ? $order->status->value : $order?->status;

        return [
            'id' => $delivery->id,
            'status' => $delivery->status,
            'status_label' => $this->label($delivery->status),
            'order' => $order ? [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $status,
                'status_label' => $order->status_label,
                'is_pos' => $order->isPos(),
                'customer_name' => $order->customer?->full_name,
                'total' => (float) $order->total,
            ] : null,
            'store' => $order?->store ? [
                'id' => $order->store->id,
                'name' => $order->store->name,
            ] : null,
            'driver_name' => $delivery->driver_name,
            'driver_phone' => $delivery->driver_phone,
            'tracking_number' => $delivery->tracking_number,
            'current_location' => $delivery->current_location,
            'route' => $delivery->deliveryRoute ? $this->routeLabel($delivery->deliveryRoute) : null,
            'estimated_delivery_at' => $delivery->estimated_delivery_at?->toISOString(),
            'actual_delivery_at' => $delivery->actual_delivery_at?->toISOString(),
            'delivery_notes' => $delivery->delivery_notes,
            'return_reason' => $delivery->return_reason,
            'created_at' => $delivery->created_at?->toISOString(),
        ];
    }

    /** Legacy's badge text: `ucfirst(str_replace('_', ' ', $status))`. */
    private function label(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }

    private function routeLabel(DeliveryRoute $route): ?string
    {
        $label = collect([$route->area, $route->state])->filter()->implode(', ');

        return $label !== '' ? $label : null;
    }

    private function authorizeStoreId(Request $request, int $storeId): void
    {
        if (! $this->user($request)->accessibleStores()->whereKey($storeId)->exists()) {
            abort(403, 'You do not have access to this store.');
        }
    }
}
