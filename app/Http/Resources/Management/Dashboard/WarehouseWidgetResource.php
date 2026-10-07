<?php

namespace App\Http\Resources\Management\Dashboard;

use App\Enums\WarehouseStatus;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-28 — one row of the warehouses panel.
 *
 * `product_count` arrives from the repository's stocked-product read: both the
 * card count and this list read stock-location rows, the one source of truth
 * (WS-06 stopped the legacy grid from disagreeing with its own count).
 *
 * @property-read Warehouse $resource
 */
final class WarehouseWidgetResource extends JsonResource
{
    public function __construct(Warehouse $warehouse, private readonly int $productCount)
    {
        parent::__construct($warehouse);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Warehouse $warehouse */
        $warehouse = $this->resource;

        return [
            'id' => $warehouse->id,
            'warehouse_code' => $warehouse->warehouse_code,
            'name' => $warehouse->name,
            'city' => $warehouse->city,
            'state' => $warehouse->state,
            'is_active' => $warehouse->status === WarehouseStatus::ACTIVE,
            'product_count' => $this->productCount,
        ];
    }
}
