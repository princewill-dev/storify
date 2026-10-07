<?php

namespace App\Http\Resources\Management\Dashboard;

use App\Enums\TransferStatus;
use App\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-28 — one row of the transfers panel (the ten newest pending/approved
 * transfers touching the scope).
 *
 * The status normaliser is the defensive one the controller carried: the row
 * may arrive with the enum already cast or as the raw column string.
 *
 * @property-read StockTransfer $resource
 */
final class PendingTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StockTransfer $transfer */
        $transfer = $this->resource;

        return [
            'id' => $transfer->id,
            'transfer_code' => $transfer->transfer_code,
            'from' => $transfer->fromLocation?->name,
            'to' => $transfer->toLocation?->name,
            'items_count' => (int) $transfer->items_count,
            'status' => $transfer->status instanceof TransferStatus ? $transfer->status->value : $transfer->status,
            'created_at' => $transfer->created_at?->toISOString(),
        ];
    }
}
