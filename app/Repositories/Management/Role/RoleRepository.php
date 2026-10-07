<?php

namespace App\Repositories\Management\Role;

use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Query building for the base RoleController's role matrix.
 *
 * Reads only: this layer never opens a transaction and never calls abort() —
 * the controller owns the 403 for a role of another business.
 *
 * Deliberately separate from the WS-20 slice that replaces these URIs on the
 * shared routes (RoleParityController, registered by
 * routes/api/v1/management/ws20-staff-roles.php): that contract hides the
 * platform `admin.*` permissions from the catalogue, ships a `protected` flag
 * on every row and probes names through validation rules; this base slice
 * lists every permission, carries no flag and answers the duplicate-name probe
 * with its own 422 envelope. The two contracts are not interchangeable, so the
 * reads are not merged.
 */
final class RoleRepository
{
    /**
     * The role matrix read model: the business's own roles with their
     * permission names and user counts, plus the permission dictionary the
     * form offers.
     *
     * The role half is tenancy-scoped — dropping the business clause would
     * show another tenant's roles — which is what earns this composition a
     * named place. The permission dictionary is global by nature; it is read
     * here so the whole index read model lives in one layer.
     *
     * @return array{roles: Collection<int, Role>, permissions: array<int, string>}
     */
    public function catalogForBusiness(?int $businessId): array
    {
        $roles = Role::query()
            ->where('business_id', $businessId)
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderBy('name')
            ->get();

        $permissions = Permission::query()
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();

        return ['roles' => $roles, 'permissions' => $permissions];
    }

    /**
     * The duplicate-name probe behind the controller's 422 refusal. Scoped to
     * the business on purpose: a name is only taken inside the caller's own
     * role list.
     */
    public function nameExists(?int $businessId, string $name): bool
    {
        return Role::query()
            ->where('business_id', $businessId)
            ->where('name', $name)
            ->exists();
    }
}
