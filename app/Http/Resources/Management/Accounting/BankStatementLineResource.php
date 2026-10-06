<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\BankStatementLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-37 — statement line payload.
 *
 * The matched journal line is only included when the relation was eager
 * loaded; a freshly updated line (match/unmatch/ignore responses) carries no
 * relation, so `matched_journal_line` stays null there — the controller's
 * previous behaviour.
 *
 * @mixin BankStatementLine
 */
final class BankStatementLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $matched = $this->relationLoaded('matchedJournalLine') ? $this->matchedJournalLine : null;

        return [
            'id' => $this->id,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'description' => $this->description,
            'reference' => $this->reference,
            'amount_kobo' => (int) $this->amount_kobo,
            'status' => $this->status,
            'matched_journal_line' => $matched ? [
                'id' => $matched->id,
                'entry_number' => $matched->entry?->entry_number,
                'entry_date' => $matched->entry?->entry_date?->toDateString(),
                'description' => $matched->description,
                'debit_kobo' => (int) $matched->debit_kobo,
                'credit_kobo' => (int) $matched->credit_kobo,
            ] : null,
        ];
    }
}
