<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\TransferStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\InventoryOverviewRequest;
use App\Http\Resources\Management\Inventory\InventoryOverviewResource;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\Management\InventoryOverviewRepository;
use App\Repositories\Management\StockMovementRepository;
use App\Repositories\Management\StockTransferRepository;
use App\Services\StockVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * WS-59 — the inventory overview.
 *
 * One read that answers "how is my inventory doing?": current stock and its
 * health, movement activity in a 7/30/90-day window, the value split by
 * warehouse and category, the most valuable products, the low-stock list and
 * the transfer pipeline.
 *
 * The static figures are not computed here. `StockVisibilityService::metrics()`
 * owns stock value, units, the low/out definition and the per-warehouse rows,
 * so this screen cannot disagree with Low Stock, Warehouses or the dashboard.
 * The repository adds only the movement series and the two money breakdowns.
 *
 * ## Permissions
 *
 * The route is catalogue-visible (`products view`) because stock value and the
 * low-stock list are the same data Low Stock publishes. Blocks the viewer
 * cannot act on are omitted from the payload rather than returned empty, the
 * WS-28 convention:
 *
 *   - movements + per-warehouse rows  → `warehouses view`
 *   - transfer pipeline                → `transfers view`
 *
 * An empty block and an unpermitted one would render identically otherwise,
 * and "no movements" is a very different statement from "you may not see
 * movements".
 *
 * Transfers are business-wide by design: a transfer request is a single
 * workflow object whose two sides may be different stores or warehouses, so
 * narrowing the pipeline by one side of the filter would tell only half the
 * story.
 */
class InventoryOverviewController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly StockVisibilityService $visibility,
        private readonly InventoryOverviewRepository $overview,
        private readonly StockMovementRepository $movements,
        private readonly StockTransferRepository $transfers,
    ) {}

    public function index(InventoryOverviewRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $days = $request->window();

        $from = Carbon::now()->subDays($days - 1)->startOfDay();
        $to = Carbon::now();

        // The window immediately before this one, day for day — the same rule
        // as dashboard/trend. Today is counted in the current window and has
        // no counterpart in the previous one.
        $previousTo = $from->copy()->subSecond();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

        // Access-checked before any query runs: an id outside the circle is a
        // 403, never a silently empty scope.
        $warehouse = $this->optionalWarehouse($user, $request->input('warehouse_id'));
        $store = $this->optionalStore($user, $request->input('store_id'));

        $storeId = $store?->id;
        $warehouseId = $warehouse?->id;

        $summary = $this->visibility->metrics($user, $storeId, $warehouseId, 5);
        $locationCount = (int) $summary['stock']['locations'];
        $lowLocations = (int) $summary['low_stock']['locations'];
        $outLocations = (int) $summary['low_stock']['out_of_stock_locations'];

        $payload = [
            'range' => [
                'days' => $days,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'definition' => $summary['definition'],
            'stock' => [
                ...$summary['stock'],
                'health' => [
                    // The three states partition the locations: the service
                    // counts low and out, so "in stock" is what remains.
                    'in_stock' => max(0, $locationCount - $lowLocations - $outLocations),
                    'low_stock' => $lowLocations,
                    'out_of_stock' => $outLocations,
                ],
            ],
            'categories' => InventoryOverviewResource::categories(
                $this->overview->valueByCategory($user, $storeId, $warehouseId),
            ),
            'top_products' => InventoryOverviewResource::topProducts(
                $this->overview->topProductsByValue($user, $storeId, $warehouseId),
            ),
            'low_stock' => $summary['low_stock'],
            'filters' => $this->filterOptions($user),
        ];

        if ($user->can('warehouses view')) {
            $payload['warehouses'] = [
                'units' => $summary['warehouses']['units'],
                'locations' => $summary['warehouses']['locations'],
                'rows' => collect($summary['warehouses']['rows'])
                    ->sortByDesc('units')
                    ->take(5)
                    ->values()
                    ->all(),
            ];

            $payload['movements'] = [
                'series' => InventoryOverviewResource::movementSeries(
                    $this->overview->movementSeries($user, $storeId, $warehouseId, $from, $to),
                    $from,
                    $days,
                ),
                'totals' => $this->overview->movementTotals($user, $storeId, $warehouseId, $from, $to),
                'previous' => $this->overview->movementTotals($user, $storeId, $warehouseId, $previousFrom, $previousTo),
            ];
        }

        if ($user->can('transfers view')) {
            $payload['transfers'] = $this->transferPipeline($user);
        }

        return $this->ok(['overview' => $payload]);
    }

    /**
     * Every transfer status in enum order, plus the two rolling figures the
     * rest of the app already uses: `pending` includes awaiting
     * acknowledgement, and `open` is everything not yet received, rejected or
     * cancelled.
     *
     * @return array{pending: int, approved: int, open: int, by_status: array<int, array{value: string, label: string, count: int}>}
     */
    private function transferPipeline(User $user): array
    {
        $counts = $this->transfers->statusCounts($user);

        $byStatus = [];
        foreach (TransferStatus::cases() as $status) {
            $byStatus[] = [
                'value' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
            ];
        }

        $pending = (int) ($counts[TransferStatus::PENDING->value] ?? 0)
            + (int) ($counts[TransferStatus::AWAITING_ACKNOWLEDGMENT->value] ?? 0);
        $approved = (int) ($counts[TransferStatus::APPROVED->value] ?? 0);
        $dispatched = (int) ($counts[TransferStatus::DISPATCHED->value] ?? 0);

        return [
            'pending' => $pending,
            'approved' => $approved,
            'open' => $pending + $approved + $dispatched,
            'by_status' => $byStatus,
        ];
    }

    /**
     * The filter pickers: every warehouse and store the user can see, with
     * deleted hosts already excluded by the movements repository.
     *
     * @return array{warehouses: array<int, array{id: int, name: string}>, stores: array<int, array{id: int, name: string}>}
     */
    private function filterOptions(User $user): array
    {
        $options = $this->movements->filterLocations($user);

        return [
            'warehouses' => $options['warehouses']
                ->map(fn (Warehouse $warehouse) => ['id' => (int) $warehouse->id, 'name' => $warehouse->name])
                ->values()
                ->all(),
            'stores' => $options['stores']
                ->map(fn (Store $store) => ['id' => (int) $store->id, 'name' => $store->name])
                ->values()
                ->all(),
        ];
    }

    private function optionalWarehouse(User $user, mixed $value): ?Warehouse
    {
        if ($value === null || $value === '') {
            return null;
        }

        $warehouse = $this->movements->findAccessibleWarehouse($user, (int) $value);

        if (! $warehouse) {
            abort(403, 'You do not have access to this warehouse.');
        }

        return $warehouse;
    }

    private function optionalStore(User $user, mixed $value): ?Store
    {
        if ($value === null || $value === '') {
            return null;
        }

        $store = $this->movements->findAccessibleStore($user, (int) $value);

        if (! $store) {
            abort(403, 'You do not have access to this store.');
        }

        return $store;
    }
}
