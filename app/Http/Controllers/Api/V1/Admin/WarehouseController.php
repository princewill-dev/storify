<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\WarehouseStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListWarehousesRequest;
use App\Http\Resources\Admin\WarehouseDetailResource;
use App\Http\Resources\Admin\WarehouseMovementResource;
use App\Http\Resources\Admin\WarehouseResource;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Repositories\Admin\WarehouseRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AD-14 — platform warehouse oversight (WS14).
 *
 * Legacy shipped two read-only screens: a platform-wide directory with a
 * status/search filter and a detail page carrying stock metrics, the
 * business/owner card, the sections table and the last 15 stock movements.
 * Both are reproduced here, platform-scoped (the admin audience's tenant is
 * the platform) and gated by `admin.warehouses` plus the platform-role guard
 * that keeps tenant-held admin tokens out.
 *
 * Improvements over legacy, per the audit:
 *  - The directory can actually return deleted rows when the `Deleted`
 *    filter is picked, and it carries the same per-row stock totals the
 *    management grid shows instead of a bare stock-location count.
 *  - `low_stock_count` restores the legacy `0 < quantity <= 10` band that
 *    WS-7 also restored on the dashboard — not the `min_quantity` rule the
 *    management screens use, which answers a different question
 *    ("below its own reorder point").
 *  - Movements report the direction relative to this warehouse (`in`/`out`)
 *    so the SPA can sign the quantity correctly; legacy coloured every
 *    non-`added` type red and could not tell transferred/adjusted apart.
 *  - The sections table shows the products assigned to a section: the new
 *    stack's sections never hold stock-location rows (only stores and
 *    warehouses are morph locations), so legacy's "stock items count" has no
 *    equivalent to read.
 *
 * The directory filters live in App\Http\Requests\Admin\ListWarehousesRequest,
 * the reads (directory, per-status totals, detail loads, recent movements) in
 * App\Repositories\Admin\WarehouseRepository, and the payloads in the Admin
 * warehouse resources; this controller keeps the HTTP contract, the
 * platform guard, the envelope and the pagination meta.
 *
 * Legacy logged `admin_warehouses_viewed` / `admin_warehouse_show_viewed`;
 * those log lines are kept alongside the WS-1 route-access audit row.
 */
class WarehouseController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly WarehouseRepository $warehouses,
    ) {}

    public function index(ListWarehousesRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        Log::info('admin_warehouses_viewed', ['user_id' => $request->user()?->id]);

        $filters = $request->validated();

        $warehouses = $this->warehouses->paginate($filters);
        $byStatus = $this->warehouses->statusCounts();

        return $this->ok(
            [
                'warehouses' => WarehouseResource::collection($warehouses->getCollection())->resolve($request),
                'statuses' => collect(WarehouseStatus::cases())->map(fn (WarehouseStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])->all(),
                'stats' => [
                    'total' => array_sum($byStatus),
                    'by_status' => $byStatus,
                ],
            ],
            null,
            200,
            $this->paginationMeta($warehouses),
        );
    }

    public function show(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizePlatformAdmin();

        Log::info('admin_warehouse_show_viewed', ['warehouse_id' => $warehouse->id]);

        $warehouse = $this->warehouses->loadForDetail($warehouse);

        return $this->ok([
            'warehouse' => WarehouseDetailResource::make($warehouse, WarehouseRepository::LOW_STOCK_THRESHOLD)->resolve($request),
            'recent_movements' => $this->warehouses->recentMovements($warehouse)
                ->map(fn (StockMovement $movement) => WarehouseMovementResource::make($movement, $warehouse)->resolve($request))
                ->values()
                ->all(),
        ]);
    }
}
