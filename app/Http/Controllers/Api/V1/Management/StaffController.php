<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Mail\StaffInvitationMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StaffController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $staff = User::query()
            ->where('business_id', $this->user($request)->business_id)
            ->where('role', 'staff')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->with(['roles', 'assignedStores:id,name'])
            ->latest()
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $staff->getCollection()->map(fn (User $user) => $this->payload($user))->values()->all(),
            null,
            200,
            $this->paginationMeta($staff)
        );
    }

    public function show(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaff($request, $staff);

        $staff->load(['roles', 'assignedStores:id,name', 'assignedWarehouses:id,name']);

        return $this->ok(['staff' => $this->payload($staff, detailed: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'role' => ['required', 'string'],
            'pin' => ['nullable', 'string', 'size:6', 'regex:/^[0-9]+$/'],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['integer'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer'],
        ]);

        $data['photo_path'] = $request->hasFile('photo')
            ? $request->file('photo')->store('photos', 'public')
            : null;

        $staff = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => 'staff',
            'business_id' => $this->user($request)->business_id,
            'invitation_token' => Str::random(64),
            'invited_at' => now(),
            'status' => 'invited',
            'is_verified' => true,
            'email_verified_at' => now(),
            'pos_pin' => $data['pin'] ?? null,
            'photo_path' => $data['photo_path'] ?? null,
            'password' => bcrypt(Str::random(32)),
        ]);

        setPermissionsTeamId($staff->business_id);
        $staff->assignRole($data['role']);

        $allowedStoreIds = $this->accessibleStoreIds($request);

        if (! empty($data['store_ids'])) {
            $staff->assignedStores()->sync(collect($data['store_ids'])->intersect($allowedStoreIds)->all());
        }

        if (! empty($data['warehouse_ids'])) {
            $warehouseIds = $this->user($request)->accessibleWarehouses()->pluck('id');
            $staff->assignedWarehouses()->sync(collect($data['warehouse_ids'])->intersect($warehouseIds)->all());
        }

        try {
            Mail::to($staff->email)->queue(new StaffInvitationMail($staff));
        } catch (\Throwable $e) {
            Log::error('api.staff.invite_mail_failed', ['user_id' => $staff->id, 'error' => $e->getMessage()]);
        }

        return $this->ok(['staff' => $this->payload($staff->fresh(), detailed: true)], 'Staff invited.', 201);
    }

    public function update(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaff($request, $staff);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'invited'])],
            'pin' => ['nullable', 'string', 'size:6', 'regex:/^[0-9]+$/'],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['integer'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer'],
        ]);

        $staff->update(collect($data)->only(['name', 'phone', 'status'])->all());

        if ($request->filled('pin')) {
            $staff->update(['pos_pin' => $data['pin']]);
        }

        if ($request->has('store_ids')) {
            $allowedStoreIds = $this->accessibleStoreIds($request);
            $staff->assignedStores()->sync(collect((array) $request->input('store_ids'))->intersect($allowedStoreIds)->all());
        }

        if ($request->has('warehouse_ids')) {
            $warehouseIds = $this->user($request)->accessibleWarehouses()->pluck('id');
            $staff->assignedWarehouses()->sync(collect((array) $request->input('warehouse_ids'))->intersect($warehouseIds)->all());
        }

        return $this->ok(['staff' => $this->payload($staff->fresh(), detailed: true)], 'Staff updated.');
    }

    public function suspend(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaff($request, $staff);

        $staff->update(['status' => 'suspended']);

        return $this->ok(['staff' => $this->payload($staff->fresh())], 'Staff suspended.');
    }

    public function activate(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaff($request, $staff);

        $staff->update(['status' => 'active']);

        return $this->ok(['staff' => $this->payload($staff->fresh())], 'Staff activated.');
    }

    public function destroy(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaff($request, $staff);

        $staff->delete();

        return $this->ok([], 'Staff removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user, bool $detailed = false): array
    {
        setPermissionsTeamId($user->business_id);

        $data = [
            'id' => $user->id,
            'account_code' => $user->account_code,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'photo_url' => $user->photo_path ? asset('storage/'.$user->photo_path) : null,
            'roles' => $user->getRoleNames()->values()->all(),
            'last_login_at' => $user->last_login_at?->toISOString(),
            'invited_at' => $user->invited_at?->toISOString(),
        ];

        if ($detailed) {
            $data['stores'] = $user->assignedStores->map(fn ($store) => ['id' => $store->id, 'name' => $store->name])->values()->all();
            $data['warehouses'] = $user->assignedWarehouses->map(fn ($warehouse) => ['id' => $warehouse->id, 'name' => $warehouse->name])->values()->all();
            $data['permissions'] = $user->getPermissionNames()->values()->all();
        }

        return $data;
    }

    private function authorizeStaff(Request $request, User $staff): void
    {
        if ($staff->role !== 'staff' || (int) $staff->business_id !== (int) $this->user($request)->business_id) {
            abort(404);
        }
    }
}
