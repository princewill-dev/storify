<?php

namespace App\Http\Resources\Management\StockVisibility;

use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-29 — the min-level editor payload: the reconciled definition, the three
 * header counts, the page of stock-location rows and the filter options.
 *
 * The rows arrive already built by the shared StockVisibilityService row
 * shape and the counts are the repository's whole-filtered-set counts; this
 * resource only assembles the payload, keys and order exactly as the
 * controller emitted them.
 */
final class StockLevelsResource extends JsonResource
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{total: int, low_stock: int, out_of_stock: int}  $counts
     * @param  array<string, mixed>  $definition
     * @param  Collection<int, Warehouse>  $warehouses
     * @param  Collection<int, Store>  $stores
     * @param  array<int, array{value: string, label: string}>  $states
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $counts,
        private readonly array $definition,
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
        return [
            'definition' => $this->definition,
            'counts' => $this->counts,
            'stock_levels' => $this->rows,
            'filters' => (new StockFilterOptionsResource($this->warehouses, $this->stores, $this->states))->resolve($request),
        ];
    }
}
