<?php

namespace App\Http\Resources\Admin;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AD-14 (WS14) — the platform warehouse directory row.
 *
 * Also the shared header block the detail payload starts from: the counts and
 * sums the directory carries through `withCount`/`withSum` are the same
 * numbers the detail's metric tiles read, so both screens stay in step.
 *
 * @property-read Warehouse $resource
 */
class WarehouseResource extends JsonResource
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
            'address' => $warehouse->address,
            'city' => $warehouse->city,
            'state' => $warehouse->state,
            'status' => $warehouse->status->value,
            'status_label' => $warehouse->status->label(),
            'business' => $warehouse->business ? [
                'id' => $warehouse->business->id,
                'name' => $warehouse->business->name,
                'business_code' => $warehouse->business->business_code,
            ] : null,
            'owner' => $warehouse->user ? [
                'id' => $warehouse->user->id,
                'name' => $warehouse->user->name,
                'email' => $warehouse->user->email,
            ] : null,
            'stock_items' => (int) ($warehouse->stock_locations_count ?? 0),
            'sections_count' => (int) ($warehouse->sections_count ?? 0),
            'total_stock' => (int) ($warehouse->total_stock ?? 0),
            'low_stock_count' => (int) ($warehouse->low_stock_count ?? 0),
            'created_at' => $warehouse->created_at?->toISOString(),
        ];
    }
}
