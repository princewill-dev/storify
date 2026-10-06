<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\BankReconciliation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-37 — completed reconciliation payload (list panel and completion
 * response).
 *
 * @mixin BankReconciliation
 */
final class BankReconciliationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'statement_closing_balance_kobo' => (int) $this->statement_closing_balance_kobo,
            'cleared_balance_kobo' => (int) $this->cleared_balance_kobo,
            'difference_kobo' => (int) $this->difference_kobo,
            'status' => $this->status,
            'completed_at' => $this->completed_at?->toISOString(),
            'ledger_account' => $this->ledgerAccount ? [
                'id' => $this->ledgerAccount->id,
                'code' => $this->ledgerAccount->code,
                'name' => $this->ledgerAccount->name,
            ] : null,
            'store_bank' => $this->storeBank ? [
                'id' => $this->storeBank->id,
                'bank_name' => $this->storeBank->bank_name,
                'masked_account_number' => $this->storeBank->masked_account_number,
            ] : null,
        ];
    }
}
