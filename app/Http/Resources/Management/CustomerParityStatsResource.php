<?php

namespace App\Http\Resources\Management;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-19 — the four detail stat tiles.
 *
 * Wraps the SQL aggregate CustomerParityRepository sums (decimal money column,
 * never a PHP float) and names the spend basis in the payload, because legacy
 * had three different definitions of the tile. Types keep the controller's
 * casts: counts are ints, `total_spent` a float. `spend_basis` is a consumed
 * label, not a computed value — it stays verbatim.
 *
 * @property object|null $resource
 */
final class CustomerParityStatsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $aggregate = $this->resource;

        return [
            'total_orders' => (int) ($aggregate->total_orders ?? 0),
            'completed_orders' => (int) ($aggregate->completed_orders ?? 0),
            'pending_orders' => (int) ($aggregate->pending_orders ?? 0),
            'total_spent' => (float) ($aggregate->total_spent ?? 0),
            'spend_basis' => 'completed orders',
        ];
    }
}
