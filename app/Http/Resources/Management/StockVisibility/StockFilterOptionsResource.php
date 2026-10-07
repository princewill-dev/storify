<?php

namespace App\Http\Resources\Management\StockVisibility;

use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-29 — the filter-picker block shared by the low-stock list and the
 * min-level editor: the state options, the caller's warehouses and stores and
 * the merged type-tagged location list the SPA filters by.
 *
 * The rows arrive already scoped to the caller's accessible set from
 * StockVisibilityRepository::filterLocations() (restricted staff only see
 * their assigned locations); this resource only reshapes them, with the field
 * names, order and types the controller's private filterOptions() emitted.
 */
final class StockFilterOptionsResource extends JsonResource
{
    /**
     * @param  Collection<int, Warehouse>  $warehouses
     * @param  Collection<int, Store>  $stores
     * @param  array<int, array{value: string, label: string}>  $states
     */
    public function __construct(
        private readonly Collection $warehouses,
        private readonly Collection $stores,
        private readonly array $states,
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
            'states' => $this->states,
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
}
