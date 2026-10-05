<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends ApiController
{
    use ResolvesManagementContext;

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
            ])->values()->all();

        $permissions = Permission::query()
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
            'name' => ['required', 'string', 'max:100'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
        ]);

        if (Role::where('business_id', $businessId)->where('name', $data['name'])->exists()) {
            return $this->error('A role with that name already exists.', 422);
        }

        setPermissionsTeamId($businessId);

        $role = Role::create([
            'name' => $data['name'],
            'business_id' => $businessId,
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($data['permissions']);

        return $this->ok([
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
            ],
        ], 'Role created.', 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->authorizeRole($request, $role);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]);

        if (isset($data['name'])) {
            $role->update(['name' => $data['name']]);
        }

        if (isset($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        return $this->ok([
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->fresh()->permissions->pluck('name')->values()->all(),
            ],
        ], 'Role updated.');
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authorizeRole($request, $role);

        if (strtolower($role->name) === 'super admin') {
            return $this->error('The Super Admin role cannot be deleted.', 409);
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
            abort(403, 'You do not have access to this role.');
        }
    }
}
