<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\StoreStaffRequest;
use App\Http\Requests\Management\UpdateStaffRequest;
use App\Http\Resources\Management\StaffResource;
use App\Models\User;
use App\Repositories\Management\StaffRepository;
use App\Services\Management\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The management staff record.
 *
 * The directory query lives in StaffRepository, the invite/edit workflows
 * (role assignment, assignment syncs, invitation mail) in StaffService, the
 * payload in StaffResource and the request rules in the two FormRequests
 * beside it. This class keeps the HTTP contract: status codes, message
 * strings, the envelope, the pagination meta and which endpoint renders the
 * detailed payload.
 *
 * The WS-20 parity module re-registers these same URIs against
 * StaffParityController (last registration wins), so this thin baseline is
 * unreachable over HTTP today; it is kept — and kept behaviour-identical — as
 * the original contract beside it.
 *
 * authorizeStaff() answers 404, not 403, for a foreign or non-staff row
 * (anti-id-probing), so it stays a private controller check rather than a
 * TenantGuard business shape, and it must not move into middleware or a
 * FormRequest.
 */
class StaffController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly StaffRepository $repository,
        private readonly StaffService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $staff = $this->repository->paginateForBusiness(
            $this->user($request),
            $request->filled('status') ? (string) $request->string('status') : null,
            $request->filled('q') ? (string) $request->string('q') : null,
            (int) $request->integer('per_page', 20),
        );

        return $this->ok(
            StaffResource::collection($staff->getCollection())->resolve($request),
            null,
            200,
            $this->paginationMeta($staff)
        );
    }

    public function show(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaff($request, $staff);

        $staff->load(['roles', 'assignedStores:id,name', 'assignedWarehouses:id,name']);

        return $this->ok(['staff' => (new StaffResource($staff))->detailed()->resolve($request)]);
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $staff = $this->service->invite($this->user($request), $request->validated(), $request->file('photo'));

        return $this->ok(
            ['staff' => (new StaffResource($staff->fresh()))->detailed()->resolve($request)],
            'Staff invited.',
            201
        );
    }

    public function update(UpdateStaffRequest $request, User $staff): JsonResponse
    {
        $this->authorizeStaff($request, $staff);

        $this->service->update(
            $this->user($request),
            $staff,
            $request->validated(),
            $request->filled('pin') ? $request->validated('pin') : null,
            $request->has('store_ids') ? (array) $request->input('store_ids') : null,
            $request->has('warehouse_ids') ? (array) $request->input('warehouse_ids') : null,
        );

        return $this->ok(['staff' => (new StaffResource($staff->fresh()))->detailed()->resolve($request)], 'Staff updated.');
    }

    public function suspend(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaff($request, $staff);

        $staff->update(['status' => 'suspended']);

        return $this->ok(['staff' => (new StaffResource($staff->fresh()))->resolve($request)], 'Staff suspended.');
    }

    public function activate(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaff($request, $staff);

        $staff->update(['status' => 'active']);

        return $this->ok(['staff' => (new StaffResource($staff->fresh()))->resolve($request)], 'Staff activated.');
    }

    public function destroy(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaff($request, $staff);

        $staff->delete();

        return $this->ok([], 'Staff removed.');
    }

    /**
     * A foreign or non-staff row must look absent: 404, not 403.
     */
    private function authorizeStaff(Request $request, User $staff): void
    {
        if ($staff->role !== 'staff' || (int) $staff->business_id !== (int) $this->user($request)->business_id) {
            abort(404);
        }
    }
}
