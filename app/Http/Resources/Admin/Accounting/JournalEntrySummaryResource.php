<?php

namespace App\Http\Resources\Admin\Accounting;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-13 — one journal list row (dashboard recent entries and the journal
 * table). The aggregate counters come from the list query's withCount/withSum;
 * the model fallbacks keep a row that was loaded without them exact.
 *
 * @property-read JournalEntry $resource
 */
final class JournalEntrySummaryResource extends JsonResource
{
    /**
     * The four-field brief the year-close response embeds for the closing
     * entry (null when the year closed with nothing to post).
     *
     * @return array<string, mixed>|null
     */
    public static function brief(?JournalEntry $entry): ?array
    {
        return $entry ? [
            'id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'entry_date' => $entry->entry_date?->toDateString(),
            'memo' => $entry->memo,
        ] : null;
    }

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
            'lines_count' => (int) ($entry->lines_count ?? $entry->lines()->count()),
            'total_debits' => (int) ($entry->total_debits ?? $entry->totalDebits()),
            'total_credits' => (int) ($entry->total_credits ?? $entry->totalCredits()),
        ];
    }
}
