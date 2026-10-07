<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * WS-10 (admin console) — one assignable platform role option.
 *
 * The invite/role-change selects consume `id` + `name` only; the ordered list
 * comes from AdminAccountRepository::assignableRoles().
 *
 * @property-read Role $resource
 */
final class AdminRoleResource extends JsonResource
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
        ];
    }
}
