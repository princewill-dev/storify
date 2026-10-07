<?php

namespace App\Http\Resources\Management\Warehouse;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A warehouse list row — the controller's inline `summary()` verbatim, field
 * names, order and types included. Tests assert exact JSON, so nothing here is
 * "tidied": the five counts keep their `?? 0` defaults (the list loads them
 * with `withCount`/`withSum`) and the status keeps its enum value string.
 *
 * @property Warehouse $resource
 */
final class WarehouseSummaryResource extends JsonResource
{
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
            'status' => $warehouse->status->value,
            'sections_count' => (int) ($warehouse->sections_count ?? 0),
            'staff_count' => (int) ($warehouse->assigned_staff_count ?? 0),
            'product_count' => (int) ($warehouse->product_count ?? 0),
            'total_stock' => (int) ($warehouse->total_stock ?? 0),
            'low_stock_count' => (int) ($warehouse->low_stock_count ?? 0),
        ];
    }
}
