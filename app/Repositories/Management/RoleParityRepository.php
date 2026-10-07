<?php

namespace App\Repositories\Management;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * WS-20 — query building for the roles surface.
 *
 * The business-scoped role list (with its permission matrix and user counts)
 * and the permission catalog the create/edit matrix renders live here. Reads
 * and query building only: the transaction boundaries belong to
 * RoleParityService and the abort()/409 calls to RoleParityController.
 *
 * The protected-role policy sits here beside the role data, mirroring
 * AdminAccountRepository::SUPER_ADMIN_ROLE, because both the controller's
 * refusals and the resource's `protected` flag must read one list.
 */
final class RoleParityRepository
{
    /**
     * System roles seeded per business. Deleting or renaming them breaks
     * permission defaults, so both are refused.
     */
    public const PROTECTED_ROLES = ['Super Admin', 'Developer', 'Store Associate'];

    /**
     * Case-insensitive, as the controller's isProtected() always was.
     */
    public static function isProtected(string $name): bool
    {
        return in_array(strtolower($name), array_map('strtolower', self::PROTECTED_ROLES), true);
    }

    /**
     * The business' roles in the name order the catalog has always rendered,
     * with the permission matrix and user counts eager-loaded.
     *
     * @return EloquentCollection<int, Role>
     */
    public function rolesFor(User $user): EloquentCollection
    {
        return Role::query()
            ->where('business_id', $user->business_id)
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderBy('name')
            ->get();
    }

    /**
     * Business roles cannot grant the platform `admin.*` abilities (admin
     * routes additionally require an admin-audience token), so the catalog
     * hides them rather than rendering 20 dead one-item groups.
     *
     * @return Collection<int, string>
     */
    public function permissionCatalog(): Collection
    {
        return Permission::query()
            ->where('name', 'not like', 'admin.%')
            ->orderBy('name')
            ->pluck('name')
            ->values();
    }
}
