<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * WS-20 — roles and the permission matrix.
 *
 * Repairs the three validation holes the audit found in the thin controller
 * beside this one: permission names are checked with `exists` (a bogus name
 * used to 500), renames are checked for per-business uniqueness (a rename to
 * a duplicate used to 500 on the DB index) and every role the platform
 * protects — Super Admin, Developer, Store Associate — refuses deletion and
 * renaming instead of only Super Admin.
 */
class RoleParityController extends ApiController
{
    use ResolvesManagementContext;

    /**
     * System roles seeded per business. Deleting or renaming them breaks
     * permission defaults, so both are refused.
     */
    public const PROTECTED_ROLES = ['Super Admin', 'Developer', 'Store Associate'];

    public function index(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        $roles = Role::query()
            ->where('business_id', $businessId)
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'users_count' => $role->users_count ?? 0,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
                'protected' => $this->isProtected($role->name),
            ])->values()->all();

        // Business roles cannot grant the platform `admin.*` abilities (admin
        // routes additionally require an admin-audience token), so the catalog
        // hides them rather than rendering 20 dead one-item groups.
        $permissions = Permission::query()
            ->where('name', 'not like', 'admin.%')
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();

        return $this->ok(['roles' => $roles, 'permissions' => $permissions]);
    }

    public function store(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('roles', 'name')->where('business_id', $businessId),
            ],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        setPermissionsTeamId($businessId);

        $role = DB::transaction(function () use ($data, $businessId) {
            $role = Role::create([
                'name' => $data['name'],
                'business_id' => $businessId,
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($data['permissions']);

            return $role;
        });

        return $this->ok([
            'role' => $this->payload($role),
        ], 'Role created.', 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->authorizeRole($request, $role);

        $businessId = $this->user($request)->business_id;

        $data = $request->validate([
            'name' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('roles', 'name')->where('business_id', $businessId)->ignore($role->id),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        if (isset($data['name'])
            && $data['name'] !== $role->name
            && $this->isProtected($role->name)) {
            return $this->error('This is a protected system role and cannot be renamed.', 409);
        }

        DB::transaction(function () use ($role, $data) {
            if (isset($data['name'])) {
                $role->update(['name' => $data['name']]);
            }

            if (isset($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }
        });

        return $this->ok(['role' => $this->payload($role->fresh())], 'Role updated.');
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authorizeRole($request, $role);

        if ($this->isProtected($role->name)) {
            return $this->error('This is a protected system role and cannot be deleted.', 409);
        }

        if ($role->users()->exists()) {
            return $this->error('Reassign the users with this role first.', 409);
        }

        $role->delete();

        return $this->ok([], 'Role deleted.');
    }

    private function authorizeRole(Request $request, Role $role): void
    {
        if ((int) $role->business_id !== (int) $this->user($request)->business_id) {
            abort(404);
        }
    }

    private function isProtected(string $name): bool
    {
        return in_array(strtolower($name), array_map('strtolower', self::PROTECTED_ROLES), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Role $role): array
    {
        $role->loadMissing('permissions:id,name');

        return [
            'id' => $role->id,
            'name' => $role->name,
            'users_count' => $role->users()->count(),
            'permissions' => $role->permissions->pluck('name')->values()->all(),
            'protected' => $this->isProtected($role->name),
        ];
    }
}
