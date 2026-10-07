<?php

namespace App\Http\Resources\Management\StoreWebMetrics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WS-35 — the web-orders chart.
 *
 * A static presenter rather than a JsonResource instance: the input is the
 * raw `bucket => count` map the repository's GROUP BY returns, plus the
 * window and bucket the controller decided, and the presenter zero-fills the
 * series so a quiet day or month draws as zero instead of vanishing (the
 * legacy six-month series did the same).
 */
final class WebOrderSeriesResource
{
    /**
     * @param  Collection<string, int|string>  $counts  raw `bucket => count` aggregate map
     * @return array{0: array<int, array{month: string, label: string, count: int}>, 1: array{from: string, to: string, bucket: string}}
     */
    public static function from(Collection $counts, Carbon $from, Carbon $to, string $bucket): array
    {
        $spansYears = $from->year !== $to->year;
        $series = [];

        $cursor = $bucket === 'day' ? $from->copy()->startOfDay() : $from->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($to)) {
            $key = $cursor->format($bucket === 'day' ? 'Y-m-d' : 'Y-m');

            $series[] = [
                'month' => $key,
                'label' => $cursor->format(self::labelFormat($bucket, $spansYears)),
                'count' => (int) ($counts[$key] ?? 0),
            ];

            if ($bucket === 'day') {
                $cursor->addDay();
            } else {
                $cursor->addMonthNoOverflow();
            }
        }

        return [
            $series,
            [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'bucket' => $bucket,
            ],
        ];
    }

    private static function labelFormat(string $bucket, bool $spansYears): string
    {
        if ($bucket === 'day') {
            return $spansYears ? 'M j, Y' : 'M j';
        }

        return $spansYears ? 'M Y' : 'M';
    }
}
