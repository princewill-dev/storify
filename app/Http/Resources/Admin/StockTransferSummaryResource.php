<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Management\Transfer\LocationRefResource;
use App\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AD-14 — the platform transfer directory row, also the header block the
 * delegated detail payload starts from.
 *
 * The location reference shape is the shared `LocationRefResource` the
 * management list uses: the morph type carried on the row is the authority,
 * with the loaded instance as the second source of truth — byte-for-byte the
 * private `locationRef()` this row used to carry.
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
            'items_count' => (int) ($transfer->items_count ?? 0),
            'total_units' => (int) ($transfer->total_units ?? 0),
            'requested_by' => $transfer->requester?->name,
            'business' => $transfer->business ? [
                'id' => $transfer->business->id,
                'name' => $transfer->business->name,
                'business_code' => $transfer->business->business_code,
            ] : null,
            'created_at' => $transfer->created_at?->toISOString(),
            'updated_at' => $transfer->updated_at?->toISOString(),
        ];
    }
}
