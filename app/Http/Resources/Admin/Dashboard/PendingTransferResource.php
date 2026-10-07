<?php

namespace App\Http\Resources\Admin\Dashboard;

use App\Enums\TransferStatus;
use App\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS7 — one row of the pending-transfers panel.
 *
 * The status normaliser is the defensive one the controller carried: the row
 * may arrive with the enum already cast or as the raw column string, and the
 * label comes from the enum either way.
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

        $status = $transfer->status instanceof TransferStatus ? $transfer->status : TransferStatus::from($transfer->status);

        return [
            'transfer_code' => $transfer->transfer_code,
            'from' => $transfer->fromLocation?->name,
            'to' => $transfer->toLocation?->name,
            'status' => $status->value,
            'status_label' => $status->label(),
            'created_at' => $transfer->created_at?->toISOString(),
        ];
    }
}
