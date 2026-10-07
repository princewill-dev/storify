<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\StaffParityIndexRequest;
use App\Http\Requests\Management\StaffParityStoreRequest;
use App\Http\Requests\Management\StaffParityUpdateRequest;
use App\Http\Resources\Management\StaffParityDetailResource;
use App\Http\Resources\Management\StaffParityOptionsResource;
use App\Http\Resources\Management\StaffParityResource;
use App\Http\Resources\Management\StaffParityStatsResource;
use App\Models\StaffDocument;
use App\Models\User;
use App\Repositories\Management\StaffParityRepository;
use App\Services\Management\StaffParityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * WS-20 — staff directory, invitations and the staff record.
 *
 * Sits beside the thin StaffController so parallel work keeps its own file;
 * this controller re-registers the same staff URIs with full legacy parity:
 * the owner row, the store filter, invitations that can carry documents and
 * a pre-set password, multi-role reassignment, resend-invite, documents CRUD
 * and removal as a soft deactivation (status = deleted) instead of the hard
 * delete that used to cascade POS sessions and null order attribution.
 *
 * The read model lives in the StaffParity resources, the directory scope,
 * filters and status counters in StaffParityRepository, and the
 * invite/edit/resend workflows with their transaction boundaries and
 * invitation mail in StaffParityService; this class keeps the HTTP contract
 * (status codes, refusal messages, envelope, pagination) and the tenant
 * guards. Suspend, activate, removal and document deletion are single-row
 * writes and stay here. Validation lives in the three FormRequests beside it,
 * including the per-business role rule the legacy global `exists` check
 * lacked.
 */
class StaffParityController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly StaffParityRepository $repository,
        private readonly StaffParityService $service,
    ) {}

    public function index(StaffParityIndexRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $filters = $request->validated();

        $store = null;
        if (! empty($filters['store_id'])) {
            $store = $user->accessibleStores()->where('store_id', $filters['store_id'])->first();

            if (! $store) {
                return $this->error('You do not have access to that store.', 404);
            }
        }

        $staff = $this->repository->listQuery($user, $store, $filters)
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $counts = $this->repository->statusCounts($user, $store);

        return $this->ok(
            StaffParityResource::collection($staff->getCollection())->resolve($request),
            null,
            200,
            [
                ...$this->paginationMeta($staff),
                'stats' => (new StaffParityStatsResource($counts))->resolve($request),
                'store' => $store ? [
                    'id' => $store->id,
                    'store_id' => $store->store_id,
                    'name' => $store->name,
                ] : null,
            ],
        );
    }

    public function show(Request $request, User $staff): JsonResponse
    {
        $this->authorizeMember($request, $staff);

        return $this->ok(['staff' => (new StaffParityDetailResource($staff))->resolve($request)]);
    }

    /**
     * Options for the invite/edit form. Declared as `staff-options` because the
     * shared routes file registers `staff/{staff}` first, which would swallow
     * `staff/options` before this module loads.
     */
    public function options(Request $request): JsonResponse
    {
        $user = $this->user($request);
        setPermissionsTeamId($user->business_id);

        return $this->ok((new StaffParityOptionsResource([
            'roles' => $this->repository->teamRoles($user),
            'stores' => $user->accessibleStores()->get(['id', 'store_id', 'name']),
            'warehouses' => $user->accessibleWarehouses()->get(['id', 'warehouse_code', 'name']),
        ]))->resolve($request));
    }

    public function store(StaffParityStoreRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $staff = $this->service->invite($request, $user, $request->validated(), $request->roleNames());

        Log::info('api.management.staff_invited', [
            'user_id' => $user->id,
            'staff_id' => $staff->id,
        ]);

        return $this->ok(['staff' => (new StaffParityDetailResource($staff->fresh()))->resolve($request)], 'Staff invited.', 201);
    }

    public function update(StaffParityUpdateRequest $request, User $staff): JsonResponse
    {
        $this->authorizeStaffMember($request, $staff);

        $this->service->update(
            $request,
            $this->user($request),
            $staff,
            $request->validated(),
            $request->has('roles') ? $request->roleNames() : null,
        );

        return $this->ok(['staff' => (new StaffParityDetailResource($staff->fresh()))->resolve($request)], 'Staff updated.');
    }

    public function resendInvite(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaffMember($request, $staff);

        if ($staff->status !== 'invited') {
            return $this->error('This staff member has already accepted the invitation.', 409);
        }

        $this->service->resendInvitation($staff);

        Log::info('api.management.staff_invite_resent', [
            'user_id' => $this->user($request)->id,
            'staff_id' => $staff->id,
        ]);

        return $this->ok(['staff' => (new StaffParityDetailResource($staff->fresh()))->resolve($request)], 'Invitation resent.');
    }

    public function suspend(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaffMember($request, $staff);

        if ($staff->status !== 'active') {
            return $this->error('Only an active staff member can be suspended.', 409);
        }

        $staff->update(['status' => 'suspended']);

        return $this->ok(['staff' => (new StaffParityDetailResource($staff->fresh()))->resolve($request)], 'Staff suspended.');
    }

    public function activate(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaffMember($request, $staff);

        if ($staff->status === 'invited') {
            return $this->error('This staff member must accept their invitation before the account can be activated.', 409);
        }

        if ($staff->status !== 'suspended') {
            return $this->error('Only a suspended staff member can be activated.', 409);
        }

        $staff->update(['status' => 'active']);

        return $this->ok(['staff' => (new StaffParityDetailResource($staff->fresh()))->resolve($request)], 'Staff activated.');
    }

    public function destroy(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaffMember($request, $staff);

        // Removal is a soft deactivation: the row, order attribution and POS
        // session history survive, and the member simply leaves the directory.
        // The legacy stack did the same; a hard delete cascaded pos_sessions
        // and nulled orders.staff_id.
        $staff->update([
            'status' => 'deleted',
            'invitation_token' => null,
        ]);

        Log::info('api.management.staff_removed', [
            'user_id' => $this->user($request)->id,
            'staff_id' => $staff->id,
        ]);

        return $this->ok([], 'Staff removed.');
    }

    public function destroyDocument(Request $request, User $staff, StaffDocument $document): JsonResponse
    {
        $this->authorizeStaffMember($request, $staff);

        if ((int) $document->user_id !== (int) $staff->id) {
            abort(404);
        }

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return $this->ok([], 'Document removed.');
    }

    /**
     * Directory rows include the owner; mutations never touch the owner row.
     */
    private function authorizeMember(Request $request, User $staff): void
    {
        if (! in_array($staff->role, ['staff', User::ROLE_BUSINESS_OWNER], true)
            || (int) $staff->business_id !== (int) $this->user($request)->business_id
            || $staff->status === 'deleted') {
            abort(404);
        }
    }

    private function authorizeStaffMember(Request $request, User $staff): void
    {
        $this->authorizeMember($request, $staff);

        if ($staff->role !== 'staff') {
            abort(404);
        }
    }
}
