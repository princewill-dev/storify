<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The WS-23 journal entry detail payload: entry metadata, period and poster
 * stamps, the reversal links in both directions and the line list with the
 * balanced totals.
 *
 * The forward reversal link is looked up by JournalRepository::reversedBy()
 * and handed in — this resource only shapes what it is given.
 */
final class JournalEntryDetailResource extends JsonResource
{
    private ?JournalEntry $reversedBy;

    public function __construct(JournalEntry $entry, ?JournalEntry $reversedBy)
    {
        parent::__construct($entry);

        $this->reversedBy = $reversedBy;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var JournalEntry $entry */
        $entry = $this->resource;

        $debits = (int) $entry->lines->sum('debit_kobo');
        $credits = (int) $entry->lines->sum('credit_kobo');

        return [
            'id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'entry_date' => $entry->entry_date?->toDateString(),
            'memo' => $entry->memo,
            'reference' => $entry->reference,
            'status' => $entry->status,
            'fiscal_period' => $entry->fiscalPeriod?->name,
            'posted_by' => $entry->postedBy?->name,
            'posted_at' => $entry->posted_at?->toISOString(),
            'voided_at' => $entry->voided_at?->toISOString(),
            'reversal_of' => $entry->reversalOf ? [
                'id' => $entry->reversalOf->id,
                'entry_number' => $entry->reversalOf->entry_number,
            ] : null,
            'reversed_by' => $this->reversedBy ? [
                'id' => $this->reversedBy->id,
                'entry_number' => $this->reversedBy->entry_number,
            ] : null,
            'total_debits' => $debits,
            'total_credits' => $credits,
            'is_balanced' => $debits === $credits,
            'lines' => $entry->lines->map(fn (JournalLine $line) => [
                'id' => $line->id,
                'ledger_account_id' => $line->ledger_account_id,
                'account' => $line->account?->name,
                'account_code' => $line->account?->code,
                'description' => $line->description,
                'debit' => (int) $line->debit_kobo,
                'credit' => (int) $line->credit_kobo,
            ])->values()->all(),
        ];
    }
}
