<?php

namespace App\Http\Resources\Management\PaymentSettings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-11 — a bank account as the store-side payment-method modal's assigned and
 * available lists render it. The account number is only ever exposed masked
 * (the model accessor), never the stored value.
 */
final class StoreBankAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'bank_name' => $this->resource->bank_name,
            'account_name' => $this->resource->account_name,
            'masked_account_number' => $this->resource->masked_account_number,
            'is_primary' => (bool) $this->resource->is_primary,
            'is_verified' => (bool) $this->resource->is_verified,
        ];
    }
}
