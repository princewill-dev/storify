<?php

namespace App\Http\Resources\Management\Dashboard;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-28 — one row of the recent-staff panel (the five newest staff members,
 * with their Spatie role names).
 *
 * @property-read User $resource
 */
final class RecentStaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $member */
        $member = $this->resource;

        return [
            'id' => $member->id,
            'name' => $member->name,
            'status' => $member->status,
            'roles' => $member->roles->pluck('name')->values()->all(),
        ];
    }
}
