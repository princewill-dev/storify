<?php

namespace App\Http\Resources\Management;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-20 — a staff row as the directory renders it.
 *
 * Field names, types and order are the controller's inline `summary()` moved
 * verbatim; tests assert this payload. The first statement keeps summary()'s
 * deliberate side effect: Spatie resolves role names against the team
 * context, so it is set from the row's own business before they are read.
 * `stores_count`/`warehouses_count` are the withCount aliases on the list and
 * the relation counts on the write echoes, exactly as the controller read
 * them.
 */
class StaffParityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $member */
        $member = $this->resource;

        setPermissionsTeamId($member->business_id);

        return [
            'id' => $member->id,
            'account_code' => $member->account_code,
            'name' => $member->name,
            'email' => $member->email,
            'phone' => $member->phone,
            'status' => $member->status,
            'photo_url' => $member->photo_path ? asset('storage/'.$member->photo_path) : null,
            'roles' => $member->getRoleNames()->values()->all(),
            'is_owner' => $member->isBusinessOwner(),
            'stores_count' => (int) ($member->stores_count ?? $member->assignedStores->count()),
            'warehouses_count' => (int) ($member->warehouses_count ?? $member->assignedWarehouses->count()),
            'has_pin' => $member->pos_pin !== null,
            'last_login_at' => $member->last_login_at?->toISOString(),
            'invited_at' => $member->invited_at?->toISOString(),
            'accepted_at' => $member->accepted_at?->toISOString(),
        ];
    }
}
