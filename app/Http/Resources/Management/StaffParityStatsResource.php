<?php

namespace App\Http\Resources\Management;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-20 — the directory's four status counters.
 *
 * The counts are grouped by `status` over the same scope as the list (store
 * filter applied) but ignore the q/status filters, so the status tabs keep
 * showing every status' size. Counts are cast to int and total is the running
 * sum; suspended was zero when the list carried no such rows, exactly as the
 * controller emitted it.
 *
 * @property Collection<string, int> $resource
 */
final class StaffParityStatsResource extends JsonResource
{
    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        $counts = $this->resource;

        return [
            'total' => (int) $counts->sum(),
            'active' => (int) ($counts['active'] ?? 0),
            'invited' => (int) ($counts['invited'] ?? 0),
            'suspended' => (int) ($counts['suspended'] ?? 0),
        ];
    }
}
