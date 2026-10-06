<?php

namespace App\Http\Resources\Management\Transfer;

use App\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-15 — the transfer list row, shared with the detail payload.
 *
 * `items_count`/`total_units` prefer the `withCount`/`withSum` aggregates the
 * repository selects for the list; when they are absent (a freshly created
 * transfer) the loaded/queried items are counted instead, exactly as the
 * pre-refactor `summary()` did.
 *
 * @property StockTransfer $resource
 */
final class StockTransferSummaryResource extends JsonResource
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
            'status' => $transfer->status->value,
            'status_label' => $transfer->status->label(),
            'from' => LocationRefResource::for($transfer->from_location_type, $transfer->fromLocation, $request),
            'to' => LocationRefResource::for($transfer->to_location_type, $transfer->toLocation, $request),
            'items_count' => (int) ($transfer->items_count ?? $transfer->items->count()),
            'total_units' => (int) ($transfer->total_units ?? $transfer->items->sum('quantity')),
            'requested_by' => $transfer->requester?->name,
            'created_at' => $transfer->created_at?->toISOString(),
            'updated_at' => $transfer->updated_at?->toISOString(),
        ];
    }
}
