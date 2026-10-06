<?php

namespace App\Http\Resources\Management\Transfer;

use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-15 — the From/To picker payload: every accessible warehouse and store
 * with the stock each holds, plus the slim id/code/name option lists.
 *
 * A store has no state column, so its `city` is the physical address and its
 * `state` is null — the shape the pre-refactor endpoint rendered from the
 * legacy create form.
 */
final class TransferLocationsResource extends JsonResource
{
    /**
     * @param  Collection<int, Warehouse>  $warehouses
     * @param  Collection<int, Store>  $stores
     */
    public function __construct(
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
        return [
            'locations' => [
                ...$this->warehouses->map(fn (Warehouse $warehouse) => [
                    'type' => 'warehouse',
                    'id' => $warehouse->id,
                    'code' => $warehouse->warehouse_code,
                    'name' => $warehouse->name,
                    'city' => $warehouse->city,
                    'state' => $warehouse->state,
                    'stock_units' => (int) ($warehouse->stock_units ?? 0),
                    'product_count' => (int) ($warehouse->product_count ?? 0),
                ])->all(),
                ...$this->stores->map(fn (Store $store) => [
                    'type' => 'store',
                    'id' => $store->id,
                    'code' => $store->store_id,
                    'name' => $store->name,
                    'city' => $store->physical_address,
                    'state' => null,
                    'stock_units' => (int) ($store->stock_units ?? 0),
                    'product_count' => (int) ($store->product_count ?? 0),
                ])->all(),
            ],
            'warehouses' => $this->warehouses->map(fn (Warehouse $warehouse) => ['id' => $warehouse->id, 'code' => $warehouse->warehouse_code, 'name' => $warehouse->name])->values()->all(),
            'stores' => $this->stores->map(fn (Store $store) => ['id' => $store->id, 'code' => $store->store_id, 'name' => $store->name])->values()->all(),
        ];
    }
}
