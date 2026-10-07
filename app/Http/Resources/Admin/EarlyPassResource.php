<?php

namespace App\Http\Resources\Admin;

use App\Models\EarlyPass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-11 (admin console) — the early-pass row.
 *
 * Field names, types and order match the payload this endpoint has always
 * returned (exact-JSON assertions depend on them): the cap and the counts are
 * cast at the edge, `max_uses`/`remaining_uses` are null for an unlimited
 * pass, and the timestamps are ISO-8601 strings or null.
 *
 * Every call site loads the usage count (`withCount`/`loadCount`) before the
 * row is shaped; the fallback keeps the shape correct if one ever forgets.
 *
 * @property-read EarlyPass $resource
 */
class EarlyPassResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EarlyPass $pass */
        $pass = $this->resource;

        $usageCount = (int) ($pass->usages_count ?? $pass->usages()->count());
        $maxUses = $pass->max_uses !== null ? (int) $pass->max_uses : null;
        $exhausted = $maxUses !== null && $usageCount >= $maxUses;

        return [
            'id' => $pass->id,
            'code' => $pass->code,
            'description' => $pass->description,
            'is_active' => (bool) $pass->is_active,
            // Same rule as the model's isAvailable(), computed from the count
            // we already loaded instead of firing a query per row.
            'is_available' => (bool) $pass->is_active && ! $exhausted,
            'is_exhausted' => $exhausted,
            'max_uses' => $maxUses,
            'usage_count' => $usageCount,
            'remaining_uses' => $maxUses !== null ? max(0, $maxUses - $usageCount) : null,
            'usage_label' => $usageCount.' / '.($maxUses ?? 'unlimited'),
            'can_delete' => $usageCount === 0,
            'created_at' => $pass->created_at?->toISOString(),
        ];
    }
}
