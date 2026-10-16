<?php

namespace App\Http\Resources\Management\PaymentSettings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-11 — the compact store summary the store-assignment lists and the
 * payment-mode response render (legacy's store row).
 */
class StoreSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->resource->status;

        return [
            'id' => $this->resource->id,
            'store_id' => $this->resource->store_id,
            'name' => $this->resource->name,
            'status' => $status instanceof \BackedEnum ? $status->value : $status,
            'payment_mode' => $this->resource->payment_mode,
            // Which of the business's accounts this store names for its own
            // takings, or null for the business default. Sent so the payout
            // step can show the current choice rather than defaulting the
            // select to empty and losing it on the next save.
            'payout_bank_id' => $this->resource->payout_bank_id !== null
                ? (int) $this->resource->payout_bank_id
                : null,
        ];
    }
}
