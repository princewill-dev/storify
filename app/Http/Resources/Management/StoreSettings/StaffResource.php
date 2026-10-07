<?php

namespace App\Http\Resources\Management\StoreSettings;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-04 — a staff row as the settings workspace lists it (assigned and
 * available) and as the assign response echoes it:
 * StoreSettingsController::staffPayload() moved verbatim.
 *
 * Deliberately NOT App\Http\Resources\Management\StaffResource: that row
 * carries phone, status, photo and timestamps, and this one must not grow
 * them — the two are not interchangeable.
 *
 * `roles` stays guarded by relationLoaded(): a caller that has not loaded the
 * relation keeps the empty list instead of triggering a lazy query, exactly
 * as before. Every settings call site does load it (`assignedStaff.roles`,
 * `->with('roles')`, `$staff->load('roles')`).
 *
 * @property User $resource
 */
final class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = $this->resource;

        return [
            'id' => $staff->id,
            'account_code' => $staff->account_code,
            'name' => $staff->name,
            'email' => $staff->email,
            'roles' => $staff->relationLoaded('roles') ? $staff->roles->pluck('name')->values()->all() : [],
        ];
    }
}
