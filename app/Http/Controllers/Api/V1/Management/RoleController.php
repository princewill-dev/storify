<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Role\StoreRoleRequest;
use App\Http\Requests\Management\Role\UpdateRoleRequest;
use App\Http\Resources\Management\Role\RoleListResource;
use App\Http\Resources\Management\Role\RoleResource;
use App\Repositories\Management\Role\RoleRepository;
use App\Services\Access\TenantGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * The base management roles API — the role catalogue, create, update and
 * delete.
 *
 * Layering: the HTTP shape (status codes, message strings, the envelope) stays
 * here; the index read model in App\Repositories\Management\Role\RoleRepository;
 * the row shapes in App\Http\Resources\Management\Role\RoleListResource (index)
 * and App\Http\Resources\Management\Role\RoleResource (the create/update
 * payload); the payload rules in the Management\Role FormRequests; the
 * business-scoping check in TenantGuard.
 *
 * No service on purpose: store and update pair one role with its own
 * permission pivot through Spatie's syncPermissions, with no transaction
 * boundary, ledger entry, mail or notification to coordinate. The richer
 * sibling that replaces these URIs on the shared routes (RoleParityController,
 * registered by routes/api/v1/management/ws20-staff-roles.php) wraps the same
 * pair in DB::transaction; the base slice never did, and adding one here would
 * be a behaviour change, not a move.
 *
 * Provenance kept with the code it explains:
 *  - the duplicate-name refusal stays the controller's own 422 envelope
 *    message ('A role with that name already exists.') rather than a `unique`
 *    validation rule — the parity slice answers that case with validation
 *    errors instead, and the two contracts are not interchangeable;
 *  - the permission rules deliberately carry no `exists` check: a bogus
 *    permission name still reaches Spatie and throws, exactly as before;
 *  - the Super Admin delete refusal (case-insensitive) stays a 409 here, ahead
 *    of the users-exists 409, in that order;
 *  - the tenant guard stays in the controller body ahead of each write, not in
 *    middleware or FormRequest::authorize(); extracting the payload rules means
 *    an unauthorised AND malformed update now fails validation first (422) —
 *    accepted codebase-wide, with 403 preserved for valid payloads and
 *    route-binding 404 still preceding both;
 *  - setPermissionsTeamId() stays in store() at the same point, before the
 *    role row exists and before its permission pivot is written — the pivot is
 *    team-scoped, and update() has never called it (the team.context
 *    middleware has already set the caller's business as the team there).
 */
class RoleController extends ApiController
{
    use ResolvesManagementContext;

    private const ACCESS_DENIED = 'You do not have access to this role.';

    public function __construct(
        private readonly RoleRepository $repository,
        private readonly TenantGuard $tenantGuard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // No (int) cast: the pre-refactor query passed the raw attribute, so a
        // null business_id stayed `whereNull` rather than narrowing to 0.
        $catalog = $this->repository->catalogForBusiness($this->user($request)->business_id);

        return $this->ok([
            'roles' => RoleListResource::collection($catalog['roles'])->resolve($request),
            'permissions' => $catalog['permissions'],
        ]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        // Raw attribute, not (int): the pre-refactor code passed it straight to
        // the scope, the duplicate probe, setPermissionsTeamId() and create().
        $businessId = $this->user($request)->business_id;

        $data = $request->validated();

        // 422 with this envelope message by design, not a validation rule —
        // see the class docblock.
        if ($this->repository->nameExists($businessId, $data['name'])) {
            return $this->error('A role with that name already exists.', 422);
        }

        setPermissionsTeamId($businessId);

        $role = Role::create([
            'name' => $data['name'],
            'business_id' => $businessId,
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($data['permissions']);

        return $this->ok(
            ['role' => (new RoleResource($role))->resolve($request)],
            'Role created.',
            201
        );
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $this->authorizeRole($request, $role);

        $data = $request->validated();

        if (isset($data['name'])) {
            $role->update(['name' => $data['name']]);
        }

        if (isset($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        return $this->ok(
            ['role' => (new RoleResource($role->fresh()))->resolve($request)],
            'Role updated.'
        );
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

    /**
     * The business-only tenant shape, with the same check order and the same
     * 403 message the private method it replaces carried.
     */
    private function authorizeRole(Request $request, Role $role): void
    {
        $this->tenantGuard->authorizeBusiness($role, $this->user($request), self::ACCESS_DENIED);
    }
}
