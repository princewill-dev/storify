<?php

namespace App\Http\Resources\Management\Warehouse;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The warehouse detail header stats — the controller's inline `stats` array
 * verbatim, field names and order included.
 *
 * Every figure reads the warehouse's own loaded collections, never a fresh
 * query: the repository's `loadForShow()` guarantees `stockLocations`,
 * `sections` and `assignedStaff` are present before this resolves.
 *
 * @property Warehouse $resource
 */
final class WarehouseStatsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Warehouse $warehouse */
        $warehouse = $this->resource;

        return [
            'total_stock' => (int) $warehouse->stockLocations->sum('quantity'),
            // The legacy grid was Product-driven while its count was
            // StockLocation-driven, so the two could disagree. Both read
            // from the same collection here.
            'product_count' => $warehouse->stockLocations->where('quantity', '>', 0)->count(),
            'low_stock_count' => $warehouse->stockLocations->filter->isLowStock()->count(),
            'sections_count' => $warehouse->sections->count(),
            'staff_count' => $warehouse->assignedStaff->count(),
        ];
    }
}
