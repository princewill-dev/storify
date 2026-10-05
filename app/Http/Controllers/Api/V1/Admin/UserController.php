<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->whereIn('role', [User::ROLE_BUSINESS_OWNER, 'staff'])
            ->with(['business:id,name,business_code'])
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('verified'), fn ($q) => $q->where('is_verified', $request->boolean('verified')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('account_code', 'like', $term));
            })
            ->orderBy(
                in_array($request->string('sort')->toString(), ['name', 'email', 'role', 'status', 'last_login_at', 'created_at'], true)
                    ? $request->string('sort')->toString()
                    : 'created_at',
                $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc'
            )
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $users->getCollection()->map(fn (User $user) => $this->payload($user))->values()->all(),
            null,
            200,
            $this->paginationMeta($users)
        );
    }

    public function show(User $user): JsonResponse
    {
        if (! in_array($user->role, [User::ROLE_BUSINESS_OWNER, 'staff'], true)) {
            abort(404);
        }

        $user->load(['business:id,name,business_code,status']);

        return $this->ok(['user' => $this->payload($user, detailed: true)]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeUser($user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $user->update($data);

        return $this->ok(['user' => $this->payload($user->fresh())], 'User updated.');
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->authorizeUser($user);

        $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        $user->update(['status' => 'suspended']);

        return $this->ok(['user' => $this->payload($user->fresh())], 'User suspended.');
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $this->authorizeUser($user);

        $user->update(['status' => 'active']);

        return $this->ok(['user' => $this->payload($user->fresh())], 'User activated.');
    }

    public function verify(User $user): JsonResponse
    {
        $this->authorizeUser($user);

        $user->is_verified = true;
        $user->email_verified_at = now();
        $user->save();

        return $this->ok(['user' => $this->payload($user->fresh())], 'User verified.');
    }

    public function unverify(User $user): JsonResponse
    {
        $this->authorizeUser($user);

        $user->is_verified = false;
        $user->email_verified_at = null;
        $user->save();

        return $this->ok(['user' => $this->payload($user->fresh())], 'User verification removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user, bool $detailed = false): array
    {
        $data = [
            'id' => $user->id,
            'account_code' => $user->account_code,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'is_verified' => (bool) $user->is_verified,
            'business' => $user->business?->name,
            'business_id' => $user->business_id,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
        ];

        if ($detailed) {
            $data['stores'] = $user->accessibleStores()->get(['id', 'name', 'status'])->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'status' => $store->status,
            ])->values()->all();
        }

        return $data;
    }

    private function authorizeUser(User $user): void
    {
        if (! in_array($user->role, [User::ROLE_BUSINESS_OWNER, 'staff'], true)) {
            abort(404);
        }
    }
}
