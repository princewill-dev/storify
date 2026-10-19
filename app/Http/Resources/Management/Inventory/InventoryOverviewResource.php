<?php

namespace App\Http\Resources\Management\Inventory;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WS-59 — the row shapes the inventory overview renders.
 *
 * A static presenter, matching `TrendSeriesResource`: the repository returns
 * raw GROUP BY rows (whose SUM values arrive as strings) and this fixes the
 * types and the field names the SPA reads.
 *
 * The zero-fill is the point of `movementSeries()`. A GROUP BY over
 * `created_at` only returns days on which something happened, so a quiet
 * Tuesday is a missing row rather than a zero. Handed that gap, a chart would
 * join Monday straight to Wednesday and draw a line through a day with no
 * movement. Every window therefore comes back with exactly `$days` rows.
 */
final class InventoryOverviewResource
{
    /**
     * One row per calendar day in the window, oldest first, with both series
     * present on every day.
     *
     * @param  Collection<string, object>  $rows
     * @return array<int, array{date: string, in: int, out: int}>
     */
    public static function movementSeries(Collection $rows, Carbon $from, int $days): array
    {
        $series = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $from->copy()->addDays($offset)->toDateString();
            $row = $rows->get($date);

            $series[] = [
                'date' => $date,
                'in' => (int) ($row->stock_in ?? 0),
                'out' => (int) ($row->stock_out ?? 0),
            ];
        }

        return $series;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, array{id: int, name: string, product_code: string|null, units: int, value_kobo: int, value: float}>
     */
    public static function topProducts(Collection $rows): array
    {
        return $rows->map(function (object $row): array {
            $valueKobo = (int) $row->value_kobo;

            return [
                'id' => (int) $row->product_id,
                'name' => (string) $row->name,
                'product_code' => $row->product_code,
                'units' => (int) $row->units,
                'value_kobo' => $valueKobo,
                'value' => $valueKobo / 100,
            ];
        })->all();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, array{name: string, units: int, value_kobo: int, value: float}>
     */
    public static function categories(Collection $rows): array
    {
        return $rows->map(function (object $row): array {
            $valueKobo = (int) $row->value_kobo;

            return [
                'name' => (string) $row->name,
                'units' => (int) $row->units,
                'value_kobo' => $valueKobo,
                'value' => $valueKobo / 100,
            ];
        })->all();
    }
}
