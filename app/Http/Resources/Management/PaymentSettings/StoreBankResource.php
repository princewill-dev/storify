<?php

namespace App\Http\Resources\Management\PaymentSettings;

use App\Models\StoreBank;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-11 — a business bank account as the payment-settings screen renders it.
 * The account number is only ever exposed masked; the assigned-store count is
 * scoped to the stores the acting user can reach.
 */
class StoreBankResource extends JsonResource
{
    public function __construct(StoreBank $bank, private readonly int $assignedStoresCount)
    {
        parent::__construct($bank);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'bank_name' => $this->resource->bank_name,
            'bank_code' => $this->resource->bank_code,
            'account_name' => $this->resource->account_name,
            'masked_account_number' => $this->resource->masked_account_number,
            'is_primary' => (bool) $this->resource->is_primary,
            'is_verified' => (bool) $this->resource->is_verified,
            'assigned_stores_count' => $this->assignedStoresCount,
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
