<?php

namespace App\Http\Resources\Management\Transfer;

use App\Models\StockLocation;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-15 — the roadmap's `stock-locations` read payload: the location
 * reference, the positive-quantity rows and their unit total.
 *
 * @property null $resource
 */
final class StockLocationsPayloadResource extends JsonResource
{
    /**
     * @param  Collection<int, StockLocation>  $rows
     */
    public function __construct(
        private readonly string $class,
        private readonly Store|Warehouse $location,
        private readonly Collection $rows,
    ) {
        parent::__construct(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'location' => LocationRefResource::for($this->class, $this->location, $request),
            'stock_locations' => StockLocationResource::collection($this->rows)->resolve($request),
            'total_units' => (int) $this->rows->sum('quantity'),
        ];
    }
}
