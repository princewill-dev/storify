<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the WS-23 journal list (`GET accounting/journal`).
 *
 * This is the flat row shape the shared endpoint already returned, so
 * `data` can keep it and existing consumers keep working.
 */
final class JournalEntrySummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var JournalEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'entry_date' => $entry->entry_date?->toDateString(),
            'memo' => $entry->memo,
            'reference' => $entry->reference,
            'status' => $entry->status,
            'lines_count' => (int) $entry->lines_count,
            'total_debits' => (int) ($entry->total_debits ?? 0),
            'total_credits' => (int) ($entry->total_credits ?? 0),
        ];
    }
}
