<?php

namespace App\Http\Resources\Management\Role;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * A role as the base RoleController's create and update answers render it —
 * the controller's inline array verbatim, field names, order and types
 * included (id, name, permissions).
 *
 * Deliberately separate from RoleListResource, the index row: that one inserts
 * `users_count` between `name` and `permissions`, and the write payload must
 * not grow that field.
 *
 * `permissions` is read off the model exactly where the inline array read it —
 * lazily from the model returned by create (after syncPermissions), and from
 * the freshly-read model on update. Reading the same relation at the same
 * point in the sequence keeps the payload identical.
 *
 * @property-read Role $resource
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Role $role */
        $role = $this->resource;

        return [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->values()->all(),
        ];
    }
}
