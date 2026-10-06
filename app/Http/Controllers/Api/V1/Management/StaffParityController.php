<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Mail\StaffInvitationSpaMail;
use App\Models\StaffDocument;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * WS-20 — staff directory, invitations and the staff record.
 *
 * Sits beside the thin StaffController so parallel work keeps its own file;
 * this controller re-registers the same staff URIs with full legacy parity:
 * the owner row, the store filter, invitations that can carry documents and
 * a pre-set password, multi-role reassignment, resend-invite, documents CRUD
 * and removal as a soft deactivation (status = deleted) instead of the hard
 * delete that used to cascade POS sessions and null order attribution.
 */
class StaffParityController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'invited'])],
            'store_id' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $store = null;
        if (! empty($filters['store_id'])) {
            $store = $user->accessibleStores()->where('store_id', $filters['store_id'])->first();

            if (! $store) {
                return $this->error('You do not have access to that store.', 404);
            }
        }

        // The directory is the whole team: the owner row (badged in the SPA)
        // plus role=staff. Deactivated rows (status=deleted) never appear.
        $directory = fn () => User::query()
            ->where('business_id', $user->business_id)
            ->whereIn('role', ['staff', User::ROLE_BUSINESS_OWNER])
            ->where('status', '!=', 'deleted')
            ->when($store, fn ($q) => $q->whereHas('assignedStores', fn ($inner) => $inner->whereKey($store->id)));

        $staff = $directory()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, function ($q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->withCount(['assignedStores as stores_count', 'assignedWarehouses as warehouses_count'])
            ->with('roles')
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [User::ROLE_BUSINESS_OWNER])
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        // Counts cover the scope (store filter applied) but ignore the q/status
        // filters, so the status tabs keep showing every status' size.
        $counts = $directory()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return $this->ok(
            $staff->getCollection()->map(fn (User $member) => $this->summary($member))->values()->all(),
            null,
            200,
            [
                ...$this->paginationMeta($staff),
                'stats' => [
                    'total' => (int) $counts->sum(),
                    'active' => (int) ($counts['active'] ?? 0),
                    'invited' => (int) ($counts['invited'] ?? 0),
                    'suspended' => (int) ($counts['suspended'] ?? 0),
                ],
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

        return $this->ok(['staff' => $this->detail($staff)]);
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

        $roles = Role::query()
            ->where('business_id', $user->business_id)
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
            ])->values()->all();

        $stores = $user->accessibleStores()
            ->get(['id', 'store_id', 'name'])
            ->map(fn ($store) => ['id' => $store->id, 'store_id' => $store->store_id, 'name' => $store->name])
            ->values()->all();

        $warehouses = $user->accessibleWarehouses()
            ->get(['id', 'warehouse_code', 'name'])
            ->map(fn ($warehouse) => ['id' => $warehouse->id, 'warehouse_code' => $warehouse->warehouse_code, 'name' => $warehouse->name])
            ->values()->all();

        return $this->ok(['roles' => $roles, 'stores' => $stores, 'warehouses' => $warehouses]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = (int) $user->business_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            // Optional: the owner may pre-set credentials; the staff member is
            // then forced to change them on first login (legacy semantics).
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['nullable', 'string', $this->teamRoleRule($businessId)],
            'roles' => ['nullable', 'array', 'min:1'],
            'roles.*' => ['string', $this->teamRoleRule($businessId)],
            'pin' => ['nullable', 'string', 'size:6', 'regex:/^[0-9]+$/'],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['nullable', 'integer'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['nullable', 'integer'],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:5120'],
            'document_tags' => ['nullable', 'array', 'max:10'],
            'document_tags.*' => ['nullable', 'string', 'max:100'],
        ]);

        // The legacy API validated `role` as a bare string, so an unknown or
        // foreign role name 500'd instead of returning a validation error. The
        // rule above checks the role belongs to this business.
        $roleNames = $this->resolveRoleNames($data);

        $plainPassword = $data['password'] ?? null;

        $staff = DB::transaction(function () use ($request, $user, $data, $roleNames, $plainPassword) {
            $staff = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => 'staff',
                'business_id' => $user->business_id,
                'invitation_token' => Str::random(64),
                'invited_at' => now(),
                'status' => 'invited',
                'is_verified' => true,
                'email_verified_at' => now(),
                'pos_pin' => $data['pin'] ?? null,
                'photo_path' => $request->hasFile('photo')
                    ? $request->file('photo')->store('photos', 'public')
                    : null,
                'password' => $plainPassword ?? bcrypt(Str::random(32)),
                'force_password_change' => $plainPassword !== null,
            ]);

            setPermissionsTeamId($staff->business_id);
            $staff->assignRole($roleNames);

            $this->syncAssignments($request, $staff);
            $this->storeDocuments($request, $staff);

            return $staff;
        });

        $this->sendInvitation($staff, $plainPassword);

        Log::info('api.management.staff_invited', [
            'user_id' => $user->id,
            'staff_id' => $staff->id,
        ]);

        return $this->ok(['staff' => $this->detail($staff->fresh())], 'Staff invited.', 201);
    }

    public function update(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaffMember($request, $staff);

        $businessId = (int) $this->user($request)->business_id;

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            // Invited staff become active by accepting; deleted staff stay
            // deleted. Only the active/suspended flip is a form concern.
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'pin' => ['nullable', 'string', 'size:6', 'regex:/^[0-9]+$/'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'remove_photo' => ['nullable', 'boolean'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', $this->teamRoleRule($businessId)],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['nullable', 'integer'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['nullable', 'integer'],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:5120'],
            'document_tags' => ['nullable', 'array', 'max:10'],
            'document_tags.*' => ['nullable', 'string', 'max:100'],
            'delete_document_ids' => ['nullable', 'array'],
            'delete_document_ids.*' => ['integer'],
        ]);

        DB::transaction(function () use ($request, $staff, $data, $businessId) {
            $staff->update(collect($data)->only(['name', 'phone', 'status'])->all());

            // An explicitly present-but-empty pin clears it, matching the
            // legacy form's "leave empty to clear" semantics.
            if ($request->has('pin')) {
                $staff->update(['pos_pin' => $data['pin'] ?? null]);
            }

            if ($request->hasFile('photo')) {
                if ($staff->photo_path) {
                    Storage::disk('public')->delete($staff->photo_path);
                }
                $staff->update(['photo_path' => $request->file('photo')->store('photos', 'public')]);
            } elseif ($request->boolean('remove_photo') && $staff->photo_path) {
                Storage::disk('public')->delete($staff->photo_path);
                $staff->update(['photo_path' => null]);
            }

            // Role reassignment (WS-20 repair): the old update ignored roles
            // entirely, so a cashier could never be promoted to manager.
            if ($request->has('roles')) {
                setPermissionsTeamId($businessId);
                $staff->syncRoles($this->resolveRoleNames($data));
            }

            $this->syncAssignments($request, $staff);
            $this->deleteDocuments($request, $staff);
            $this->storeDocuments($request, $staff);
        });

        return $this->ok(['staff' => $this->detail($staff->fresh())], 'Staff updated.');
    }

    public function resendInvite(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaffMember($request, $staff);

        if ($staff->status !== 'invited') {
            return $this->error('This staff member has already accepted the invitation.', 409);
        }

        $staff->update([
            'invitation_token' => Str::random(64),
            'invited_at' => now(),
        ]);

        $this->sendInvitation($staff, null);

        Log::info('api.management.staff_invite_resent', [
            'user_id' => $this->user($request)->id,
            'staff_id' => $staff->id,
        ]);

        return $this->ok(['staff' => $this->detail($staff->fresh())], 'Invitation resent.');
    }

    public function suspend(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaffMember($request, $staff);

        if ($staff->status !== 'active') {
            return $this->error('Only an active staff member can be suspended.', 409);
        }

        $staff->update(['status' => 'suspended']);

        return $this->ok(['staff' => $this->detail($staff->fresh())], 'Staff suspended.');
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

        return $this->ok(['staff' => $this->detail($staff->fresh())], 'Staff activated.');
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
     * A role may only be assigned if it belongs to the acting business — the
     * legacy `exists:roles,name` check was global and could reach another
     * tenant's role, which then threw a 500 from Spatie's team resolution.
     */
    private function teamRoleRule(int $businessId): Exists
    {
        return Rule::exists('roles', 'name')->where('business_id', $businessId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function resolveRoleNames(array $data): array
    {
        $names = collect($data['roles'] ?? []);

        if ($names->isEmpty() && ! empty($data['role'])) {
            $names = collect([$data['role']]);
        }

        $names = $names->filter()->unique()->values()->all();

        if ($names === []) {
            throw ValidationException::withMessages([
                'roles' => 'Select at least one role for this staff member.',
            ]);
        }

        return $names;
    }

    private function syncAssignments(Request $request, User $staff): void
    {
        if ($request->has('store_ids')) {
            $allowed = $this->accessibleStoreIds($request);
            $staff->assignedStores()->sync(
                collect((array) $request->input('store_ids'))->intersect($allowed)->values()->all(),
            );
        }

        if ($request->has('warehouse_ids')) {
            $allowed = $this->user($request)->accessibleWarehouseIds();
            $staff->assignedWarehouses()->sync(
                collect((array) $request->input('warehouse_ids'))->intersect($allowed)->values()->all(),
            );
        }
    }

    private function storeDocuments(Request $request, User $staff): void
    {
        if (! $request->hasFile('documents')) {
            return;
        }

        $tags = $request->input('document_tags', []);

        foreach ($request->file('documents') as $index => $file) {
            $path = $file->store('staff-documents', 'public');

            $staff->documents()->create([
                'file_name' => $file->hashName(),
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'tag' => $tags[$index] ?? null,
            ]);
        }
    }

    private function deleteDocuments(Request $request, User $staff): void
    {
        $ids = (array) $request->input('delete_document_ids', []);

        if ($ids === []) {
            return;
        }

        $staff->documents()->whereIn('id', $ids)->get()->each(function (StaffDocument $document) {
            Storage::disk('public')->delete($document->file_path);
            $document->delete();
        });
    }

    /**
     * A failed mail must not lose the staff record — the invitation can be
     * resent from the directory.
     */
    private function sendInvitation(User $staff, ?string $plainPassword): void
    {
        try {
            Mail::to($staff->email)->queue(new StaffInvitationSpaMail($staff, $plainPassword));
        } catch (\Throwable $e) {
            Log::error('api.staff.invite_mail_failed', [
                'user_id' => $staff->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(User $member): array
    {
        setPermissionsTeamId($member->business_id);

        return [
            'id' => $member->id,
            'account_code' => $member->account_code,
            'name' => $member->name,
            'email' => $member->email,
            'phone' => $member->phone,
            'status' => $member->status,
            'photo_url' => $member->photo_path ? asset('storage/'.$member->photo_path) : null,
            'roles' => $member->getRoleNames()->values()->all(),
            'is_owner' => $member->isBusinessOwner(),
            'stores_count' => (int) ($member->stores_count ?? $member->assignedStores->count()),
            'warehouses_count' => (int) ($member->warehouses_count ?? $member->assignedWarehouses->count()),
            'has_pin' => $member->pos_pin !== null,
            'last_login_at' => $member->last_login_at?->toISOString(),
            'invited_at' => $member->invited_at?->toISOString(),
            'accepted_at' => $member->accepted_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(User $member): array
    {
        $member->loadMissing(['roles', 'assignedStores:id,name', 'assignedWarehouses:id,name', 'documents']);

        return [
            ...$this->summary($member),
            'created_at' => $member->created_at?->toISOString(),
            'stores' => $member->assignedStores->map(fn ($store) => ['id' => $store->id, 'name' => $store->name])->values()->all(),
            'warehouses' => $member->assignedWarehouses->map(fn ($warehouse) => ['id' => $warehouse->id, 'name' => $warehouse->name])->values()->all(),
            'permissions' => $member->getPermissionNames()->values()->all(),
            'documents' => $member->documents->map(fn (StaffDocument $document) => [
                'id' => $document->id,
                'original_name' => $document->original_name,
                'tag' => $document->tag,
                'mime_type' => $document->mime_type,
                'size' => (int) $document->size,
                'formatted_size' => $document->formattedSize(),
                'extension' => $document->extension(),
                'is_image' => $document->isImage(),
                'url' => $document->url(),
                'created_at' => $document->created_at?->toISOString(),
            ])->values()->all(),
        ];
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
