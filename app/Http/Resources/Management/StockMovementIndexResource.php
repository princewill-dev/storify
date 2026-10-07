<?php

namespace App\Http\Resources\Management;

use App\Enums\StockMovementType;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-29 — the stock-movement ledger payload: the page of rows, the type
 * options and the location filter options the legacy screen never had.
 *
 * The rows arrive already scoped and eager-loaded from
 * StockMovementRepository::paginateForUser(); the warehouse/store options are
 * the accessible, not-deleted sets from StockMovementRepository::filterLocations().
 * Shaping issues no queries of its own, and the key order — movements, types,
 * filters — is the pre-refactor payload contract.
 */
final class StockMovementIndexResource extends JsonResource
{
    /**
     * @param  Collection<int, StockMovement>  $movements
     * @param  Collection<int, Warehouse>  $warehouses
     * @param  Collection<int, Store>  $stores
     */
    public function __construct(
        private readonly Collection $movements,
        private readonly Collection $warehouses,
        private readonly Collection $stores,
    ) {
        parent::__construct(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $warehouses = $this->warehouses->map(fn (Warehouse $warehouse) => [
            'id' => $warehouse->id,
            'code' => $warehouse->warehouse_code,
            'name' => $warehouse->name,
        ]);

        $stores = $this->stores->map(fn (Store $store) => [
            'id' => $store->id,
            'code' => $store->store_id,
            'name' => $store->name,
        ]);

        return [
            'movements' => $this->movements
                ->map(fn (StockMovement $movement) => StockMovementResource::make($movement)->resolve($request))
                ->values()
                ->all(),
            'types' => array_map(fn (StockMovementType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ], StockMovementType::cases()),
            'filters' => [
                'warehouses' => $warehouses->values()->all(),
                'stores' => $stores->values()->all(),
                'locations' => [
                    ...$warehouses->map(fn (array $warehouse) => [
                        'type' => 'warehouse',
                        'id' => $warehouse['id'],
                        'code' => $warehouse['code'],
                        'name' => $warehouse['name'],
                    ])->values()->all(),
                    ...$stores->map(fn (array $store) => [
                        'type' => 'store',
                        'id' => $store['id'],
                        'code' => $store['code'],
                        'name' => $store['name'],
                    ])->values()->all(),
                ],
            ],
        ];
    }
}
