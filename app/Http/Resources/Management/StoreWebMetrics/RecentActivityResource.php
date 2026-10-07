<?php

namespace App\Http\Resources\Management\StoreWebMetrics;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-35 — one row of the store's recent-activity feed.
 *
 * Field names, types and order are the controller's inline map moved
 * verbatim; the endpoint's tests assert this shape. `user` is the author's
 * name (the repository eager-loads `user:id,name`), null for system rows.
 *
 * @property ActivityLog $resource
 */
final class RecentActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ActivityLog $log */
        $log = $this->resource;

        return [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'user' => $log->user?->name,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toISOString(),
        ];
    }
}
