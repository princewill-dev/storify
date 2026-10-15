<?php

namespace App\Http\Resources\Management\Dashboard;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WS-28 — the dashboard's trend window, shaped for the sparklines and deltas.
 *
 * A static presenter rather than a JsonResource instance, matching
 * `RevenueSeriesResource`: the input is the raw `date => value` maps the
 * repository's GROUP BYs return, and this fixes the row shapes the page reads.
 *
 * The zero-fill is the point of the class. A GROUP BY over `created_at` only
 * returns the days something happened, so a quiet Tuesday is a *missing* row,
 * not a zero. Handed that gap, a sparkline closes the distance between Monday
 * and Wednesday and draws a straight line through a day that had no sales —
 * the trend looks smooth and is wrong. Every window therefore comes back with
 * exactly `$days` rows, one per calendar day, gaps filled with zero.
 *
 * Every metric is cast from the database's own string output to the type the
 * SPA's interfaces declare: revenue to a float, counts to integers. `SUM()` in
 * MySQL returns a string, and an uncast one reaches JSON as `"1200.00"`.
 */
final class TrendSeriesResource
{
    /**
     * Which metrics carry money and which carry counts — the one place the
     * casts are decided, so a metric added to the repository cannot arrive
     * mistyped by being forgotten here.
     */
    private const MONEY_METRICS = ['revenue'];

    /**
     * One row per day in the window, oldest first, every metric present on
     * every day.
     *
     * @param  array<string, Collection<string, mixed>>  $metrics
     * @return array<int, array<string, float|int|string>>
     */
    public static function series(array $metrics, Carbon $from, int $days): array
    {
        // A caller permitted none of the metrics gets an empty window rather
        // than `$days` rows carrying nothing but their dates: there is no
        // sparkline to draw and no total to compare, and a run of day names
        // would say otherwise.
        if ($metrics === []) {
            return [];
        }

        $rows = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $from->copy()->addDays($offset)->toDateString();
            $row = ['date' => $date];

            foreach ($metrics as $metric => $totals) {
                $value = $totals[$date] ?? 0;
                $row[$metric] = in_array($metric, self::MONEY_METRICS, true)
                    ? round((float) $value, 2)
                    : (int) $value;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The window summed to one figure per metric, for the delta to compare
     * against its twin.
     *
     * @param  array<string, Collection<string, mixed>>  $metrics
     * @return array<string, float|int>
     */
    public static function totals(array $metrics): array
    {
        $totals = [];

        foreach ($metrics as $metric => $values) {
            $sum = $values->sum(fn ($value) => (float) $value);

            $totals[$metric] = in_array($metric, self::MONEY_METRICS, true)
                ? round($sum, 2)
                : (int) $sum;
        }

        return $totals;
    }
}
