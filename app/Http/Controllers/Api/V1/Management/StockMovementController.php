<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\StockMovementType;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * WS-29 — the read side of the stock ledger (audit §3.3).
 *
 * POS sales, storefront checkout, order returns and transfer dispatch/receive
 * already write `StockMovement` rows with balances, references and the acting
 * user; there was no way to read them. Legacy only ever showed a fixed list of
 * the latest 20 on a warehouse's Activity tab — this endpoint keeps that
 * shape (signed quantity, balance before/after, performed-by) and adds the
 * paging and filters the legacy screen never had.
 */
class StockMovementController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'warehouse_id' => ['nullable', 'string', 'max:64'],
            'store_id' => ['nullable', 'string', 'max:64'],
            'stock_location_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(array_map(fn (StockMovementType $type) => $type->value, StockMovementType::cases()))],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
            return $this->error('The from date must be on or before the to date.', 422);
        }

        $warehouse = $this->optionalWarehouse($request, $filters['warehouse_id'] ?? null);
        $store = $this->optionalStore($request, $filters['store_id'] ?? null);
        $product = isset($filters['product_id']) ? $this->resolveProduct($request, (int) $filters['product_id']) : null;

        $query = $this->scopedQuery($request)
            ->when($warehouse, fn (Builder $inner, Warehouse $filter) => $inner->whereHas('stockLocation', fn (Builder $location) => $location
                ->where('locationable_type', Warehouse::class)
                ->where('locationable_id', $filter->id)))
            ->when($store, fn (Builder $inner, Store $filter) => $inner->whereHas('stockLocation', fn (Builder $location) => $location
                ->where('locationable_type', Store::class)
                ->where('locationable_id', $filter->id)))
            ->when(
                $filters['stock_location_id'] ?? null,
                fn (Builder $inner, $locationId) => $inner->where('stock_location_id', (int) $locationId),
            )
            ->when($product, fn (Builder $inner, Product $filter) => $inner->where('product_id', $filter->id))
            ->when($filters['type'] ?? null, fn (Builder $inner, $type) => $inner->where('type', $type))
            ->when($filters['q'] ?? null, function (Builder $inner, string $term) {
                $inner->where(function (Builder $search) use ($term) {
                    $search->where('movement_code', 'like', "%{$term}%")
                        ->orWhere('notes', 'like', "%{$term}%")
                        ->orWhereHas('product', fn (Builder $product) => $product
                            ->where('name', 'like', "%{$term}%")
                            ->orWhere('product_code', 'like', "%{$term}%"));
                });
            })
            ->when($filters['from'] ?? null, fn (Builder $inner, $from) => $inner->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $inner, $to) => $inner->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->with([
                'product.images',
                'productVariant:id,variant_code',
                'stockLocation.locationable',
                'fromLocation',
                'toLocation',
                'performedBy',
                'reference',
            ]);

        $direction = ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';

        $movements = $query
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return $this->ok(
            [
                'movements' => $movements->getCollection()->map(fn (StockMovement $movement) => $this->row($movement))->all(),
                'types' => array_map(fn (StockMovementType $type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ], StockMovementType::cases()),
                'filters' => $this->filterOptions($request),
            ],
            null,
            200,
            $this->paginationMeta($movements),
        );
    }

    /**
     * Movements are business-scoped and then narrowed to the locations the
     * user can actually see — a restricted staff member's history stops at
     * their assigned stores and warehouses.
     */
    private function scopedQuery(Request $request): Builder
    {
        $user = $this->user($request);

        $storeIds = $user->accessibleStoreIds();
        $warehouseIds = $user->accessibleWarehouseIds();

        return StockMovement::query()
            ->where('business_id', $user->business_id)
            ->whereHas('stockLocation', function (Builder $query) use ($storeIds, $warehouseIds) {
                $query->where(function (Builder $hosts) use ($storeIds, $warehouseIds) {
                    $hosts->where(function (Builder $stores) use ($storeIds) {
                        $stores->where('locationable_type', Store::class)->whereIn('locationable_id', $storeIds);
                    })->orWhere(function (Builder $warehouses) use ($warehouseIds) {
                        $warehouses->where('locationable_type', Warehouse::class)->whereIn('locationable_id', $warehouseIds);
                    });
                });
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function row(StockMovement $movement): array
    {
        $location = $movement->stockLocation;
        $type = (string) $movement->type;
        $direction = $this->direction($movement, $location);
        $quantity = (int) $movement->quantity;

        return [
            'id' => $movement->id,
            'movement_code' => $movement->movement_code,
            'type' => $type,
            'type_label' => StockMovementType::tryFrom($type)?->label() ?? Str::headline($type),
            'direction' => $direction,
            'quantity' => $quantity,
            // Legacy printed the raw quantity; signing it makes an OUT row
            // readable at a glance without reading the badge.
            'signed_quantity' => $direction === 'out' ? -$quantity : $quantity,
            'balance_before' => (int) $movement->balance_before,
            'balance_after' => (int) $movement->balance_after,
            'product' => $movement->product ? [
                'id' => $movement->product->id,
                'name' => $movement->product->name,
                'product_code' => $movement->product->product_code,
                'is_digital' => (bool) $movement->product->is_digital,
                'image_url' => $movement->product->primaryImage()?->path
                    ? asset('storage/'.$movement->product->primaryImage()->path)
                    : null,
            ] : null,
            'variant_label' => $movement->productVariant?->variant_code,
            'location' => $this->locationRef($location?->locationable),
            'counterpart' => $type === StockMovement::TYPE_TRANSFERRED
                ? $this->locationRef($direction === 'out' ? $movement->toLocation : $movement->fromLocation)
                : null,
            'reference' => $this->reference($movement),
            'performed_by' => $movement->performedBy ? [
                'id' => $movement->performedBy->id,
                'name' => $movement->performedBy->name,
                'account_code' => $movement->performedBy->account_code,
            ] : null,
            'notes' => $movement->notes,
            'created_at' => $movement->created_at?->toISOString(),
        ];
    }

    /**
     * "in"/"out" is derived from the movement's own location against its
     * from/to sides, not from the type: a transfer writes two rows of the same
     * type, one on each side of the move.
     */
    private function direction(StockMovement $movement, ?StockLocation $location): string
    {
        if ($location) {
            $isSource = $movement->from_location_type !== null
                && $movement->from_location_type === $location->locationable_type
                && (int) $movement->from_location_id === (int) $location->locationable_id;

            $isDestination = $movement->to_location_type === $location->locationable_type
                && (int) $movement->to_location_id === (int) $location->locationable_id;

            if ($isSource && ! $isDestination) {
                return 'out';
            }

            if ($isDestination && ! $isSource) {
                return 'in';
            }
        }

        return (string) $movement->type === StockMovement::TYPE_ADDED ? 'in' : 'out';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reference(StockMovement $movement): ?array
    {
        if (! $movement->reference_type) {
            return null;
        }

        $class = class_basename($movement->reference_type);
        $payload = [
            'type' => Str::snake($class),
            'id' => $movement->reference_id,
            'label' => Str::headline($class).' #'.$movement->reference_id,
            'code' => null,
        ];

        if ($movement->reference instanceof StockTransfer) {
            $payload['code'] = $movement->reference->transfer_code;
            $payload['label'] = $movement->reference->transfer_code;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function locationRef(?object $host): ?array
    {
        if ($host instanceof Warehouse) {
            return ['type' => 'warehouse', 'id' => $host->id, 'code' => $host->warehouse_code, 'name' => $host->name];
        }

        if ($host instanceof Store) {
            return ['type' => 'store', 'id' => $host->id, 'code' => $host->store_id, 'name' => $host->name];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(Request $request): array
    {
        $user = $this->user($request);

        $warehouses = $user->accessibleWarehouses()
            ->where('status', '!=', Warehouse::STATUS_DELETED)
            // Qualified: restricted staff resolve through a morphedByMany whose
            // pivot also has an `id`, so a bare column list is ambiguous.
            ->orderBy('name')
            ->get(['warehouses.id', 'warehouse_code', 'name'])
            ->map(fn (Warehouse $warehouse) => [
                'id' => $warehouse->id,
                'code' => $warehouse->warehouse_code,
                'name' => $warehouse->name,
            ]);

        $stores = $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get(['stores.id', 'store_id', 'name'])
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'code' => $store->store_id,
                'name' => $store->name,
            ]);

        return [
            'warehouses' => $warehouses->all(),
            'stores' => $stores->all(),
            'locations' => [
                ...$warehouses->map(fn (array $warehouse) => [
                    'type' => 'warehouse',
                    'id' => $warehouse['id'],
                    'code' => $warehouse['code'],
                    'name' => $warehouse['name'],
                ])->all(),
                ...$stores->map(fn (array $store) => [
                    'type' => 'store',
                    'id' => $store['id'],
                    'code' => $store['code'],
                    'name' => $store['name'],
                ])->all(),
            ],
        ];
    }

    private function optionalWarehouse(Request $request, mixed $value): ?Warehouse
    {
        if ($value === null || $value === '') {
            return null;
        }

        $query = $this->user($request)->accessibleWarehouses()
            ->where('status', '!=', Warehouse::STATUS_DELETED);

        $warehouse = is_numeric($value)
            ? (clone $query)->whereKey((int) $value)->first()
            : (clone $query)->where('warehouse_code', $value)->first();

        if (! $warehouse) {
            abort(403, 'You do not have access to this warehouse.');
        }

        return $warehouse;
    }

    private function optionalStore(Request $request, mixed $value): ?Store
    {
        if ($value === null || $value === '') {
            return null;
        }

        $query = $this->user($request)->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED);

        $store = is_numeric($value)
            ? (clone $query)->whereKey((int) $value)->first()
            : (clone $query)->where('store_id', $value)->first();

        if (! $store) {
            abort(403, 'You do not have access to this store.');
        }

        return $store;
    }

    private function resolveProduct(Request $request, int $id): Product
    {
        $product = Product::query()
            ->where('business_id', $this->user($request)->business_id)
            ->whereKey($id)
            ->first();

        if (! $product) {
            abort(403, 'You do not have access to this product.');
        }

        return $product;
    }
}
