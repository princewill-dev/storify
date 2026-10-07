<?php

namespace App\Http\Resources\Management;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff row as the list and the write responses render it — StaffController's
 * private payload() moved verbatim.
 *
 * Field names, types and order are frozen: the directory list uses the base
 * shape, while show() and the invite/edit echoes called `payload(..., detailed:
 * true)` and now call detailed(), which appends the stores, warehouses and
 * permissions blocks in that same order.
 *
 * The relation reads are deliberately left lazy (no loadMissing()): the list
 * eager-loads roles + assignedStores, show() loads the full set, and the
 * write echoes render fresh() rows — exactly the query pattern payload() had,
 * so each endpoint keeps the loads it always performed.
 */
final class StaffResource extends JsonResource
{
    private bool $detailed = false;

    public function detailed(bool $detailed = true): static
    {
        $this->detailed = $detailed;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        // Spatie resolves role and permission names against the team context;
        // the payload has always set it to the row's own business first.
        setPermissionsTeamId($user->business_id);

        $data = [
            'id' => $user->id,
            'account_code' => $user->account_code,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'photo_url' => $user->photo_path ? asset('storage/'.$user->photo_path) : null,
            'roles' => $user->getRoleNames()->values()->all(),
            'last_login_at' => $user->last_login_at?->toISOString(),
            'invited_at' => $user->invited_at?->toISOString(),
        ];

        if ($this->detailed) {
            $data['stores'] = $user->assignedStores->map(fn ($store) => ['id' => $store->id, 'name' => $store->name])->values()->all();
            $data['warehouses'] = $user->assignedWarehouses->map(fn ($warehouse) => ['id' => $warehouse->id, 'name' => $warehouse->name])->values()->all();
            $data['permissions'] = $user->getAllPermissions()->pluck('name')->values()->all();
        }

        return $data;
    }
}
