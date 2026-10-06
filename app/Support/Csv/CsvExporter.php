<?php

namespace App\Support\Csv;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streaming CSV export.
 *
 * Two controllers had grown their own copy of the same boilerplate — the
 * streamDownload wrapper, the header row, and the fputcsv argument list whose
 * explicit escape keeps PHP 8.4's deprecation away and follows RFC 4180.
 *
 * Rows are written through a callback rather than materialised, so an export
 * of any size stays flat in memory.
 */
final class CsvExporter
{
    /**
     * @param  array<int, string>  $headers
     * @param  callable(resource): void  $writeRows  receives the open handle
     */
    public static function stream(string $filename, array $headers, callable $writeRows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $writeRows) {
            $handle = fopen('php://output', 'w');

            self::writeRow($handle, $headers);
            $writeRows($handle);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  resource  $handle
     * @param  array<int, mixed>  $row
     */
    public static function writeRow($handle, array $row): void
    {
        fputcsv($handle, $row, ',', '"', '');
    }
}
