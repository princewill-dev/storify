<?php

namespace App\Http\Resources\Admin;

use App\Models\ActivityLog;
use App\Services\ActivityRecorder;
use App\Support\Csv\CsvExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * WS-1 — the `export=csv` representation of the activity-log endpoint.
 *
 * Same filtered rows as the JSON list, streamed through chunkById so a full
 * trail stays flat in memory.
 */
final class ActivityLogExportResource
{
    /**
     * @var array<int, string>
     */
    private const HEADERS = [
        'When', 'User', 'Action', 'Description', 'Subject',
        'Old values', 'New values', 'Metadata', 'Business', 'IP', 'User agent',
    ];

    /**
     * @param  Builder<ActivityLog>  $query
     */
    public static function stream(Builder $query): StreamedResponse
    {
        $filename = 'activity-logs-'.now()->format('Y-m-d-His').'.csv';

        return CsvExporter::stream($filename, self::HEADERS, function ($handle) use ($query) {
            $query->chunkById(500, function (Collection $logs) use ($handle) {
                foreach ($logs as $log) {
                    CsvExporter::writeRow($handle, array_map(self::cell(...), [
                        $log->created_at?->toDateTimeString() ?? '',
                        $log->user?->name ?? '—',
                        $log->action,
                        $log->description ?? '',
                        $log->subject_type !== null ? class_basename($log->subject_type).' #'.$log->subject_id : '',
                        // Redacted exactly like the JSON payload: rows written
                        // before ActivityRecorder existed may carry raw secrets.
                        json_encode($log->old_values === null ? [] : ActivityRecorder::redact($log->old_values), JSON_UNESCAPED_SLASHES),
                        json_encode($log->new_values === null ? [] : ActivityRecorder::redact($log->new_values), JSON_UNESCAPED_SLASHES),
                        json_encode(ActivityRecorder::redact($log->metadata ?? []), JSON_UNESCAPED_SLASHES),
                        $log->business?->name ?? '',
                        $log->ip_address ?? '',
                        $log->user_agent ?? '',
                    ]));
                }
            });
        });
    }

    /**
     * Guard against spreadsheet formula injection when an export is opened in
     * Excel/Sheets: a description beginning "=" or "@" must stay text.
     */
    private static function cell(mixed $value): string
    {
        $text = (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }
}
