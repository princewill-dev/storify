<?php

namespace App\Http\Resources\Admin;

use App\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-12 — the platform receiving-bank-account row.
 *
 * Field names, types and order match the payload this endpoint has always
 * returned (exact-JSON assertions depend on them): the stored path stays
 * internal and the public `logo_url` is absolute, `sort_order` and `is_active`
 * are cast at the edge, and the timestamps are ISO-8601 strings or null.
 *
 * @property-read BankAccount $resource
 */
class BankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BankAccount $account */
        $account = $this->resource;

        return [
            'id' => $account->id,
            'bank_name' => $account->bank_name,
            'account_number' => $account->account_number,
            'account_name' => $account->account_name,
            'logo_url' => $account->logo ? asset('storage/'.$account->logo) : null,
            'sort_order' => (int) $account->sort_order,
            'is_active' => (bool) $account->is_active,
            'created_at' => $account->created_at?->toISOString(),
            'updated_at' => $account->updated_at?->toISOString(),
        ];
    }
}
