<?php

namespace App\Http\Resources\Storefront;

use App\Models\StoreBank;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bank-account option in the storefront checkout — the controller's
 * inline map moved verbatim: field names, order and types unchanged. Only the
 * store's verified accounts reach this class
 * (CheckoutRepository::verifiedBankAccountsFor).
 */
final class CheckoutBankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StoreBank $bank */
        $bank = $this->resource;

        return [
            'id' => $bank->id,
            'bank_name' => $bank->bank_name,
            'account_number' => $bank->account_number,
            'account_name' => $bank->account_name,
        ];
    }
}
