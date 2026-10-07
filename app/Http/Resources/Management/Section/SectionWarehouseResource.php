<?php

namespace App\Http\Resources\Management\Section;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-36 — the warehouse block the index and the detail embed: the
 * controller's inline `warehouseSummary()` verbatim, field names and order
 * included.
 *
 * @property Warehouse $resource
 */
final class SectionWarehouseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $warehouse = $this->resource;

        return [
            'id' => $warehouse->id,
            'warehouse_code' => $warehouse->warehouse_code,
            'name' => $warehouse->name,
            'status' => $warehouse->status->value,
            'city' => $warehouse->city,
            'state' => $warehouse->state,
        ];
    }
}
