<?php

namespace App\Http\Resources\Management\StockVisibility;

use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-29 — the low-stock drill-down payload: the reconciled definition, the tab
 * counts, the merged page of location + product-fallback rows and the filter
 * options.
 *
 * The rows arrive already merged, ordered and paginated (the controller owns
 * the HTTP pagination), each one shaped by the shared
 * StockVisibilityService row builders. The counts block is the four raw counts
 * — location rows and product fallbacks for each of the two states — combined
 * here the same way the pre-refactor controller combined them, keys and order
 * included.
 */
final class LowStockIndexResource extends JsonResource
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  the current page, already sorted
     * @param  array<string, mixed>  $definition
     * @param  Collection<int, Warehouse>  $warehouses
     * @param  Collection<int, Store>  $stores
     * @param  array<int, array{value: string, label: string}>  $states
     */
    public function __construct(
        private readonly array $rows,
        private readonly int $lowLocationCount,
        private readonly int $outLocationCount,
        private readonly int $lowProductCount,
        private readonly int $outProductCount,
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
        $low = $this->lowLocationCount + $this->lowProductCount;
        $out = $this->outLocationCount + $this->outProductCount;

        return [
            'definition' => $this->definition,
            'counts' => [
                'attention' => $low + $out,
                'low_stock' => $low,
                'out_of_stock' => $out,
                'location_rows' => $this->lowLocationCount + $this->outLocationCount,
                'product_rows' => $this->lowProductCount + $this->outProductCount,
            ],
            'low_stock' => $this->rows,
            'filters' => (new StockFilterOptionsResource($this->warehouses, $this->stores, $this->states))->resolve($request),
        ];
    }
}
