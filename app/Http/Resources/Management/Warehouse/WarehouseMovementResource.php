<?php

namespace App\Http\Resources\Management\Warehouse;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the warehouse detail page's `recent_movements` list — the
 * controller's inline map verbatim, field names, order and types included:
 * `product` and `performed_by` are the relation names, not nested objects, and
 * the two quantities are cast back to int exactly as the controller cast them.
 *
 * The repository's `recentMovements()` eager-loads `product` and
 * `performedBy`, so shaping here issues no queries of its own.
 *
 * @property StockMovement $resource
 */
final class WarehouseMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StockMovement $movement */
        $movement = $this->resource;

        return [
            'id' => $movement->id,
            'movement_code' => $movement->movement_code,
            'type' => $movement->type?->value ?? $movement->type,
            'quantity' => (int) $movement->quantity,
            'balance_after' => (int) $movement->balance_after,
            'product' => $movement->product?->name,
            'performed_by' => $movement->performedBy?->name,
            'created_at' => $movement->created_at?->toISOString(),
        ];
    }
}
