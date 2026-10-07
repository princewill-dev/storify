<?php

namespace App\Http\Resources\Management;

use App\Repositories\Management\RoleParityRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * WS-20 — a role row as the catalog and the write responses render it.
 *
 * Field names, types and order are the controller's inline catalog map and
 * payload() moved verbatim. `users_count` is the `withCount('users')` alias
 * on the list and the relation count on the create/update echo, exactly as
 * the controller read it; `permissions` was eager-loaded `:id,name` on the
 * list and is loadMissing'd for the write echoes. `protected` reads the
 * shared policy in RoleParityRepository, the same list the controller's 409
 * refusals use.
 */
final class RoleParityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Role $role */
        $role = $this->resource;

        $role->loadMissing('permissions:id,name');

        return [
            'id' => $role->id,
            'name' => $role->name,
            'users_count' => $role->users_count ?? $role->users()->count(),
            'permissions' => $role->permissions->pluck('name')->values()->all(),
            'protected' => RoleParityRepository::isProtected($role->name),
        ];
    }
}
