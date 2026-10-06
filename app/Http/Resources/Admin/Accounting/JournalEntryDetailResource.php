<?php

namespace App\Http\Resources\Admin\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-13 — a single platform entry: lines, posted totals and the reversal
 * banner pair (the entry this one reverses, and the entry that reverses it,
 * looked up by the repository).
 *
 * @property-read JournalEntry $resource
 */
final class JournalEntryDetailResource extends JsonResource
{
    private ?JournalEntry $reversedBy = null;

    public function withReversedBy(?JournalEntry $reversedBy): static
    {
        $this->reversedBy = $reversedBy;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var JournalEntry $entry */
        $entry = $this->resource;

        $lines = $entry->lines->map(fn (JournalLine $line) => [
            'id' => $line->id,
            'account_id' => $line->ledger_account_id,
            'account_code' => $line->account?->code,
            'account_name' => $line->account?->name,
            'description' => $line->description,
            'debit_kobo' => (int) $line->debit_kobo,
            'credit_kobo' => (int) $line->credit_kobo,
        ])->values()->all();

        $totalDebits = array_sum(array_column($lines, 'debit_kobo'));
        $totalCredits = array_sum(array_column($lines, 'credit_kobo'));

        return [
            'id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'entry_date' => $entry->entry_date?->toDateString(),
            'memo' => $entry->memo,
            'reference' => $entry->reference,
            'status' => $entry->status,
            'posted_at' => $entry->posted_at?->toISOString(),
            'created_at' => $entry->created_at?->toISOString(),
            'fiscal_period' => $entry->fiscalPeriod ? [
                'id' => $entry->fiscalPeriod->id,
                'name' => $entry->fiscalPeriod->name,
            ] : null,
            'posted_by' => $entry->postedBy ? [
                'id' => $entry->postedBy->id,
                'name' => $entry->postedBy->name,
            ] : null,
            'lines' => $lines,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'balanced' => $totalDebits === $totalCredits,
            'reversal' => [
                'is_reversal' => $entry->reversal_of_id !== null,
                'reversal_of' => $entry->reversalOf ? [
                    'id' => $entry->reversalOf->id,
                    'entry_number' => $entry->reversalOf->entry_number,
                ] : null,
                'reversed_by' => $this->reversedBy ? [
                    'id' => $this->reversedBy->id,
                    'entry_number' => $this->reversedBy->entry_number,
                ] : null,
                'voided_at' => $entry->voided_at?->toISOString(),
            ],
        ];
    }
}
