<?php

namespace App\Services\Management;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * WS-20 — the role write workflows.
 *
 * Create and edit pair the role row with its permission matrix inside one
 * DB::transaction, keeping the exact order the controller used: the team
 * context is set before the transaction in create, and the role row precedes
 * syncPermissions. The controller keeps the HTTP shape (201/409s, refusal
 * messages, envelope) and the tenant guard; delete is a single row write with
 * no transaction and stays there.
 */
final class RoleParityService
{
    /**
     * @param  array<string, mixed>  $data  validated by RoleParityStoreRequest
     */
    public function create(User $user, array $data): Role
    {
        setPermissionsTeamId($user->business_id);

        return DB::transaction(function () use ($user, $data) {
            $role = Role::create([
                'name' => $data['name'],
                'business_id' => $user->business_id,
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($data['permissions']);

            return $role;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated by RoleParityUpdateRequest
     */
    public function update(Role $role, array $data): void
    {
        DB::transaction(function () use ($role, $data) {
            if (isset($data['name'])) {
                $role->update(['name' => $data['name']]);
            }

            if (isset($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }
        });
    }
}
