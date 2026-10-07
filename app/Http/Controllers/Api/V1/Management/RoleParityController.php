<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\RoleParityStoreRequest;
use App\Http\Requests\Management\RoleParityUpdateRequest;
use App\Http\Resources\Management\RoleParityResource;
use App\Repositories\Management\RoleParityRepository;
use App\Services\Management\RoleParityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 *
 * The read queries (the business-scoped role list and the permission catalog)
 * live in RoleParityRepository together with the protected-role policy the
 * resource's `protected` flag shares; the create/edit workflows and their
 * transaction boundaries live in RoleParityService; the role payload lives in
 * RoleParityResource and the request rules in the two FormRequests beside it.
 * This class keeps the HTTP contract: status codes (201/404/409), refusal
 * messages, the envelope and the order of the guards.
 */
class RoleParityController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly RoleParityRepository $repository,
        private readonly RoleParityService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok([
            'roles' => RoleParityResource::collection($this->repository->rolesFor($this->user($request)))
                ->resolve($request),
            'permissions' => $this->repository->permissionCatalog()->all(),
        ]);
    }

    public function store(RoleParityStoreRequest $request): JsonResponse
    {
        $role = $this->service->create($this->user($request), $request->validated());

        return $this->ok([
            'role' => (new RoleParityResource($role))->resolve($request),
        ], 'Role created.', 201);
    }

    public function update(RoleParityUpdateRequest $request, Role $role): JsonResponse
    {
        $this->authorizeRole($request, $role);

        $data = $request->validated();

        if (isset($data['name'])
            && $data['name'] !== $role->name
            && RoleParityRepository::isProtected($role->name)) {
            return $this->error('This is a protected system role and cannot be renamed.', 409);
        }

        $this->service->update($role, $data);

        return $this->ok(['role' => (new RoleParityResource($role->fresh()))->resolve($request)], 'Role updated.');
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authorizeRole($request, $role);

        if (RoleParityRepository::isProtected($role->name)) {
            return $this->error('This is a protected system role and cannot be deleted.', 409);
        }

        if ($role->users()->exists()) {
            return $this->error('Reassign the users with this role first.', 409);
        }

        $role->delete();

        return $this->ok([], 'Role deleted.');
    }

    /**
     * A role of another business is invisible: a 404, not a 403. That is
     * deliberate anti-id-probing and the reason this stays private here
     * rather than moving to TenantGuard, whose business shapes abort 403.
     */
    private function authorizeRole(Request $request, Role $role): void
    {
        if ((int) $role->business_id !== (int) $this->user($request)->business_id) {
            abort(404);
        }
    }
}
