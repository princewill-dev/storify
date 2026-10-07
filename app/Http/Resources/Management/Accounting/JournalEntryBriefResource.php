<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-37 — the four-field journal-entry brief the settings screen and the
 * opening-balance/year-close responses embed (null when there is no entry).
 *
 * @property-read JournalEntry $resource
 */
final class JournalEntryBriefResource extends JsonResource
{
    /**
     * @return array<string, mixed>|null
     */
    public static function brief(?JournalEntry $entry): ?array
    {
        return $entry ? (new self($entry))->resolve() : null;
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
        ];
    }
}
