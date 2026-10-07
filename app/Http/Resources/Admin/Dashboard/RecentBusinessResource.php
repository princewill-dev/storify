<?php

namespace App\Http\Resources\Admin\Dashboard;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS7 — one row of the recent-businesses feed (eight latest, platform-wide).
 *
 * @property-read Business $resource
 */
final class RecentBusinessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Business $business */
        $business = $this->resource;

        return [
            'id' => $business->id,
            'name' => $business->name,
            'business_code' => $business->business_code,
            'status' => $business->status,
            'owner' => $business->owner?->name,
            'created_at' => $business->created_at?->toISOString(),
        ];
    }
}
