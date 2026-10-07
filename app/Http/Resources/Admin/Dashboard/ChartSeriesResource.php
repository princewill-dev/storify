<?php

namespace App\Http\Resources\Admin\Dashboard;

use Illuminate\Support\Collection;

/**
 * WS7 — the dashboard's chart windows.
 *
 * A static presenter rather than a JsonResource instance: the input is the
 * raw aggregate map the repository returns (date => total from a GROUP BY, or
 * month => total from the six-month loop), and each builder fixes the row
 * shape the charts expect.
 *
 * The daily builder zero-fills the fixed 7/30/90-day window so the chart gets
 * one point per day rather than the 30–90 queries legacy ran per window; the
 * monthly builder keeps the oldest-first order the repository's loop produced.
 */
final class ChartSeriesResource
{
    /**
     * Zero-fill a `date => total` map into the fixed window the chart expects.
     *
     * @param  Collection<string, mixed>  $totals
     * @return array<int, array{date: string, total: float|int}>
     */
    public static function daily(Collection $totals, int $days, bool $money): array
    {
        $series = [];

        for ($daysAgo = $days - 1; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo)->toDateString();
            $total = $totals[$date] ?? 0;

            $series[] = [
                'date' => $date,
                'total' => $money ? (float) $total : (int) $total,
            ];
        }

        return $series;
    }

    /**
     * @param  array<string, float|int>  $totals  month (Y-m) => total, oldest first
     * @return array<int, array{month: string, total: float|int}>
     */
    public static function monthly(array $totals): array
    {
        $series = [];

        foreach ($totals as $month => $total) {
            $series[] = [
                'month' => $month,
                'total' => $total,
            ];
        }

        return $series;
    }
}
