<?php

namespace App\Http\Resources\Admin;

use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Repositories\Admin\WarehouseRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AD-14 (WS14) — one ledger movement row on the warehouse detail.
 *
 * Movements report the direction relative to this warehouse (`in`/`out`) so
 * the SPA can sign and colour the quantity correctly; legacy coloured every
 * non-`added` type red and could not tell transferred/adjusted apart. The
 * direction and the signed quantity are per-row maths over the warehouse
 * passed in by the controller — the rows themselves are read by
 * {@see WarehouseRepository::recentMovements()}.
 *
 * @property-read StockMovement $resource
 */
final class WarehouseMovementResource extends JsonResource
{
    public function __construct(StockMovement $movement, private readonly Warehouse $warehouse)
    {
        parent::__construct($movement);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StockMovement $movement */
        $movement = $this->resource;

        $incoming = $movement->to_location_type === Warehouse::class
            && (int) $movement->to_location_id === (int) $this->warehouse->id;
        $quantity = (int) $movement->quantity;

        return [
            'id' => $movement->id,
            'movement_code' => $movement->movement_code,
            'product' => $movement->product?->name,
            'type' => (string) $movement->type,
            'direction' => $incoming ? 'in' : 'out',
            'quantity' => $quantity,
            'signed_quantity' => $incoming ? $quantity : -$quantity,
            'balance_after' => $movement->balance_after !== null ? (int) $movement->balance_after : null,
            'performed_by' => $movement->performedBy?->name,
            'created_at' => $movement->created_at?->toISOString(),
        ];
    }
}
