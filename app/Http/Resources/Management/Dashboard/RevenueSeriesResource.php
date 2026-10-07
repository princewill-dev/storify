<?php

namespace App\Http\Resources\Management\Dashboard;

use Illuminate\Support\Collection;

/**
 * WS-28 — the dashboard's revenue chart rows.
 *
 * A static presenter rather than a JsonResource instance: the input is the raw
 * `month`/`total` aggregate collection the repository's GROUP BY returns, and
 * the presenter fixes the row shape the chart reads.
 */
final class RevenueSeriesResource
{
    /**
     * @param  Collection<int, object>  $rows  raw `month`/`total` aggregate rows
     * @return array<int, array{month: string, total: float}>
     */
    public static function from(Collection $rows): array
    {
        return $rows
            ->map(fn ($row) => ['month' => $row->month, 'total' => round((float) $row->total, 2)])
            ->values()
            ->all();
    }
}
