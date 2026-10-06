<?php

namespace App\Http\Resources\Admin\Accounting;

use App\Models\JournalEntry;
use App\Support\Csv\CsvExporter;
use App\Support\Money\Naira;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * WS-13 — the journal CSV representation (improvement on legacy — no export
 * existed). Same filtered rows as the list; money is written as decimal naira
 * strings, exactly the accountant-friendly convention the management report
 * exports use, so `platform-journal.csv` opens next to them.
 */
final class JournalExportResource
{
    /** @var array<int, string> */
    private const HEADERS = ['Entry', 'Date', 'Reference', 'Memo', 'Status', 'Lines', 'Debits (NGN)', 'Credits (NGN)'];

    /**
     * @param  Collection<int, JournalEntry>  $entries
     */
    public static function stream(Collection $entries): StreamedResponse
    {
        return CsvExporter::stream('platform-journal.csv', self::HEADERS, function ($handle) use ($entries) {
            foreach ($entries as $entry) {
                CsvExporter::writeRow($handle, [
                    $entry->entry_number,
                    $entry->entry_date?->toDateString(),
                    $entry->reference,
                    $entry->memo,
                    $entry->status,
                    (int) $entry->lines_count,
                    Naira::decimalFromKobo((int) $entry->total_debits),
                    Naira::decimalFromKobo((int) $entry->total_credits),
                ]);
            }
        });
    }
}
