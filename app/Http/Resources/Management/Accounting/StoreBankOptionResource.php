<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\StoreBank;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-37 — linked store bank option for the import modal picker. The account
 * number is masked by the model accessor, never returned raw.
 *
 * @mixin StoreBank
 */
final class StoreBankOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_name' => $this->bank_name,
            'masked_account_number' => $this->masked_account_number,
        ];
    }
}
