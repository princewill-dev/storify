<?php

namespace App\Http\Resources\Management;

use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-13 — the board's nine status counters.
 *
 * The legacy counters — Total/Pending/Dispatched/Delivered were rendered,
 * the other five were computed and discarded. All nine are returned so the
 * list can surface them without a second shape. Counts are cast to int;
 * total is the running sum of the nine.
 *
 * @property Collection<string, int> $resource
 */
final class OrderParityStatsResource extends JsonResource
{
    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        $counts = $this->resource;

        $stats = ['total' => 0];

        foreach (OrderStatus::cases() as $status) {
            $stats[$status->value] = (int) ($counts[$status->value] ?? 0);
            $stats['total'] += $stats[$status->value];
        }

        return $stats;
    }
}
