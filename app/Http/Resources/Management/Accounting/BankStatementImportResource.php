<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\BankStatementImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-37 — statement import payload.
 *
 * `lines_count`/`unmatched_count`/`matched_count` come from a `withCount`
 * when the list query provided one and fall back to counting the relation
 * otherwise, exactly as the controller did before extraction.
 *
 * @mixin BankStatementImport
 */
final class BankStatementImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'statement_date' => $this->statement_date?->toDateString(),
            'status' => $this->status,
            'file_name' => $this->file_path ? basename($this->file_path) : null,
            'opening_balance_kobo' => $this->opening_balance_kobo !== null ? (int) $this->opening_balance_kobo : null,
            'closing_balance_kobo' => $this->closing_balance_kobo !== null ? (int) $this->closing_balance_kobo : null,
            'lines_count' => (int) ($this->lines_count ?? $this->lines()->count()),
            'unmatched_count' => (int) ($this->unmatched_count ?? $this->lines()->where('status', 'unmatched')->count()),
            'matched_count' => (int) ($this->matched_count ?? $this->lines()->where('status', 'matched')->count()),
            'ledger_account' => $this->ledgerAccount ? [
                'id' => $this->ledgerAccount->id,
                'code' => $this->ledgerAccount->code,
                'name' => $this->ledgerAccount->name,
                'subtype' => $this->ledgerAccount->subtype,
            ] : null,
            'store_bank' => $this->storeBank ? [
                'id' => $this->storeBank->id,
                'bank_name' => $this->storeBank->bank_name,
                'masked_account_number' => $this->storeBank->masked_account_number,
            ] : null,
            'imported_by' => $this->relationLoaded('importedBy') ? $this->importedBy?->name : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
