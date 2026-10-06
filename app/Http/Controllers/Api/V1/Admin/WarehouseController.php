<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\WarehouseStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
 * Legacy logged `admin_warehouses_viewed` / `admin_warehouse_show_viewed`;
 * those log lines are kept alongside the WS-1 route-access audit row.
 */
class WarehouseController extends ApiController
{
    use EnsuresPlatformAdmin;

    /**
     * Legacy flagged `quantity > 0 && quantity <= 10` as low stock before the
     * new stack silently narrowed the band; WS-7 restored the same threshold.
     */
    private const LOW_STOCK_THRESHOLD = 10;

    private const PER_PAGE = 15;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        Log::info('admin_warehouses_viewed', ['user_id' => $request->user()?->id]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                WarehouseStatus::ACTIVE->value,
                WarehouseStatus::INACTIVE->value,
                WarehouseStatus::DELETED->value,
            ])],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in(['name', 'warehouse_code', 'status', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = Warehouse::query()
            ->with(['business:id,name,business_code', 'user:id,name,email'])
            ->withCount(['stockLocations', 'sections'])
            ->withCount(['stockLocations as low_stock_count' => fn ($stock) => $stock
                ->where('quantity', '>', 0)
                ->where('quantity', '<=', self::LOW_STOCK_THRESHOLD)])
            ->withSum('stockLocations as total_stock', 'quantity')
            ->when(
                $filters['status'] ?? null,
                fn ($q, $status) => $q->where('status', $status),
                fn ($q) => $q->where('status', '!=', Warehouse::STATUS_DELETED),
            )
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', "%{$term}%")
                        ->orWhere('warehouse_code', 'like', "%{$term}%")
                        ->orWhereHas('business', fn ($business) => $business->where('name', 'like', "%{$term}%"));
                });
            })
            ->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')
            ->orderBy('id', $filters['direction'] ?? 'asc');

        $warehouses = $query->paginate($filters['per_page'] ?? self::PER_PAGE)->withQueryString();

        $byStatus = Warehouse::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        return $this->ok(
            [
                'warehouses' => $warehouses->getCollection()->map(fn (Warehouse $warehouse) => $this->summary($warehouse))->all(),
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

    public function show(Warehouse $warehouse): JsonResponse
    {
        $this->authorizePlatformAdmin();

        Log::info('admin_warehouse_show_viewed', ['warehouse_id' => $warehouse->id]);

        // Counts and sums mirror the directory's `withCount`/`withSum` shape so
        // both screens read the same numbers; the sections collection is the
        // only extra load the detail needs.
        $warehouse->loadCount(['stockLocations', 'sections'])
            ->loadCount(['stockLocations as low_stock_count' => fn ($stock) => $stock
                ->where('quantity', '>', 0)
                ->where('quantity', '<=', self::LOW_STOCK_THRESHOLD)])
            ->loadSum('stockLocations as total_stock', 'quantity')
            ->load([
                'business:id,name,business_code',
                'user:id,name,email',
                'sections' => fn ($query) => $query->withCount('products')->orderBy('name'),
            ]);

        return $this->ok([
            'warehouse' => [
                ...$this->summary($warehouse),
                'country' => $warehouse->country,
                'contact_person' => $warehouse->contact_person,
                'contact_phone' => $warehouse->contact_phone,
                'description' => $warehouse->description,
                'low_stock_threshold' => self::LOW_STOCK_THRESHOLD,
                'sections' => $warehouse->sections->map(fn ($section) => [
                    'id' => $section->id,
                    'section_code' => $section->section_code,
                    'name' => $section->name,
                    'products_count' => (int) ($section->products_count ?? 0),
                    'status' => $section->status->value,
                    'status_label' => $section->status->label(),
                ])->all(),
            ],
            'recent_movements' => $this->recentMovements($warehouse),
        ]);
    }

    /**
     * The last 15 movements touching this warehouse on either side, with the
     * direction resolved so the SPA can sign and colour the quantity.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentMovements(Warehouse $warehouse): array
    {
        return StockMovement::query()
            ->where(function ($query) use ($warehouse) {
                $query->where(fn ($from) => $from
                    ->where('from_location_type', Warehouse::class)
                    ->where('from_location_id', $warehouse->id))
                    ->orWhere(fn ($to) => $to
                        ->where('to_location_type', Warehouse::class)
                        ->where('to_location_id', $warehouse->id));
            })
            ->with(['product:id,name', 'performedBy:id,name'])
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(function (StockMovement $movement) use ($warehouse) {
                $incoming = $movement->to_location_type === Warehouse::class
                    && (int) $movement->to_location_id === (int) $warehouse->id;
                $quantity = (int) $movement->quantity;

                return [
                    'id' => $movement->id,
                    'movement_code' => $movement->movement_code,
                    'product' => $movement->product?->name,
                    'type' => (string) $movement->type,
                    'direction' => $incoming ? 'in' : 'out',
                    'quantity' => $quantity,
                    'signed_quantity' => $incoming ? $quantity : -$quantity,
                    'balance_after' => $movement->balance_after !== null ? (int) $movement->balance_after : null,
                    'performed_by' => $movement->performedBy?->name,
                    'created_at' => $movement->created_at?->toISOString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Directory row and shared detail header fields.
     *
     * @return array<string, mixed>
     */
    private function summary(Warehouse $warehouse): array
    {
        return [
            'id' => $warehouse->id,
            'warehouse_code' => $warehouse->warehouse_code,
            'name' => $warehouse->name,
            'address' => $warehouse->address,
            'city' => $warehouse->city,
            'state' => $warehouse->state,
            'status' => $warehouse->status->value,
            'status_label' => $warehouse->status->label(),
            'business' => $warehouse->business ? [
                'id' => $warehouse->business->id,
                'name' => $warehouse->business->name,
                'business_code' => $warehouse->business->business_code,
            ] : null,
            'owner' => $warehouse->user ? [
                'id' => $warehouse->user->id,
                'name' => $warehouse->user->name,
                'email' => $warehouse->user->email,
            ] : null,
            'stock_items' => (int) ($warehouse->stock_locations_count ?? 0),
            'sections_count' => (int) ($warehouse->sections_count ?? 0),
            'total_stock' => (int) ($warehouse->total_stock ?? 0),
            'low_stock_count' => (int) ($warehouse->low_stock_count ?? 0),
            'created_at' => $warehouse->created_at?->toISOString(),
        ];
    }
}
