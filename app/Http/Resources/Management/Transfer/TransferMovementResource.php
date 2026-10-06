<?php

namespace App\Http\Resources\Management\Transfer;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-15 — one ledger movement row on the transfer detail.
 *
 * The type is a `StockMovementType` enum value on write; the `BackedEnum`
 * branch keeps older rows whose column was read back un-cast rendering the
 * same as before.
 *
 * @property StockMovement $resource
 */
final class TransferMovementResource extends JsonResource
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
            'type' => $movement->type instanceof \BackedEnum ? $movement->type->value : $movement->type,
            'product' => $movement->product?->name,
            'quantity' => (int) $movement->quantity,
            'balance_before' => $movement->balance_before !== null ? (int) $movement->balance_before : null,
            'balance_after' => $movement->balance_after !== null ? (int) $movement->balance_after : null,
            'performed_by' => $movement->performedBy?->name,
            'created_at' => $movement->created_at?->toISOString(),
        ];
    }
}
