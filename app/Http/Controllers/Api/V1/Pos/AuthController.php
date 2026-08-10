<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosSession;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->merge([
            'email' => trim($request->input('email', '')),
        ]);

        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::guard('web')->attempt($validated, false)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user = Auth::guard('web')->user();

        if ($user->role !== 'staff' && $user->role !== 'business_owner') {
            return response()->json([
                'success' => false,
                'message' => 'This login is for POS cashiers and store owners only.',
            ], 403);
        }

        if ($user->role === 'staff' && !$user->pos_pin) {
            return response()->json([
                'success' => false,
                'message' => 'Your account does not have a POS PIN set. Please contact your manager to set one up in the management portal.',
            ], 403);
        }

        $token = $user->createToken('pos-terminal')->plainTextToken;

        $stores = $this->getAccessibleStores($user);
        $singleStore = $stores->count() === 1 ? $stores->first() : null;
        $activeStore = $singleStore;

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'idle_timeout_minutes' => (int) env('POS_IDLE_TIMEOUT_MINUTES', 15),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'permissions' => $user->getPermissionNames()->toArray(),
                    'force_password_change' => (bool) $user->force_password_change,
                    'theme' => $user->theme_preference ?? 'dark',
                ],
                'stores' => $stores->map(fn($s) => [
                    'id' => $s->id,
                    'store_id' => $s->store_id,
                    'name' => $s->name,
                    'address' => $s->address,
                    'logo' => $s->logo_path ? asset('storage/' . $s->logo_path) : null,
                ]),
                'active_store' => $activeStore ? [
                    'id' => $activeStore->id,
                    'store_id' => $activeStore->store_id,
                    'name' => $activeStore->name,
                    'address' => $activeStore->address,
                    'logo' => $activeStore->logo_path ? asset('storage/' . $activeStore->logo_path) : null,
                ] : null,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $stores = $this->getAccessibleStores($user);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'permissions' => $user->getPermissionNames()->toArray(),
                    'theme' => $user->theme_preference ?? 'dark',
                ],
                'stores' => $stores->map(fn($s) => [
                    'id' => $s->id,
                    'store_id' => $s->store_id,
                    'name' => $s->name,
                    'address' => $s->address,
                ]),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Logged out.']);
    }

    public function switchStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
        ]);

        $user = $request->user();
        $assigned = $user->assignedStores()
            ->where('stores.id', $validated['store_id'])
            ->where('status', '!=', 'deleted')
            ->exists();

        if (!$assigned && !$user->isRestrictedStaff()) {
            $assigned = Store::where('id', $validated['store_id'])
                ->where('pos_enabled', true)
                ->where('status', '!=', 'deleted')
                ->exists();
        }

        if (!$assigned) {
            return response()->json(['success' => false, 'message' => 'You are not assigned to this store.'], 403);
        }

        $store = Store::findOrFail($validated['store_id']);

        return response()->json([
            'success' => true,
            'data' => [
                'store' => [
                    'id' => $store->id,
                    'store_id' => $store->store_id,
                    'name' => $store->name,
                    'address' => $store->address,
                    'logo' => $store->logo_path ? asset('storage/' . $store->logo_path) : null,
                ],
            ],
        ]);
    }

    public function verifyPin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pin' => 'required|string|size:6',
        ]);

        $pin = $validated['pin'];
        $currentUser = $request->user();

        if ($currentUser->pos_pin && Hash::check($pin, $currentUser->pos_pin)) {
            return response()->json(['success' => true, 'data' => ['switched' => false]]);
        }

        $staffUsers = \App\Models\User::where('business_id', $currentUser->business_id)
            ->where('role', 'staff')
            ->where('id', '!=', $currentUser->id)
            ->whereNotNull('pos_pin')
            ->get(['id', 'pos_pin']);

        $matchedUser = null;
        foreach ($staffUsers as $staff) {
            if (Hash::check($pin, $staff->pos_pin)) {
                $matchedUser = $staff;
                break;
            }
        }

        if (!$matchedUser) {
            return response()->json(['success' => false, 'message' => 'Invalid PIN.'], 422);
        }

        $matchedUser = \App\Models\User::find($matchedUser->id);
        $token = $matchedUser->createToken('pos-terminal')->plainTextToken;
        $stores = $this->getAccessibleStores($matchedUser);
        $activeStore = $stores->count() === 1 ? $stores->first() : null;

        return response()->json([
            'success' => true,
            'data' => [
                'switched' => true,
                'token' => $token,
                'idle_timeout_minutes' => (int) env('POS_IDLE_TIMEOUT_MINUTES', 15),
                'user' => [
                    'id' => $matchedUser->id,
                    'name' => $matchedUser->name,
                    'email' => $matchedUser->email,
                    'role' => $matchedUser->role,
                    'permissions' => $matchedUser->getPermissionNames()->toArray(),
                    'theme' => $matchedUser->theme_preference ?? 'dark',
                    'force_password_change' => (bool) $matchedUser->force_password_change,
                ],
                'stores' => $stores->map(fn($s) => [
                    'id' => $s->id,
                    'store_id' => $s->store_id,
                    'name' => $s->name,
                    'address' => $s->address,
                    'logo' => $s->logo_path ? asset('storage/' . $s->logo_path) : null,
                ]),
                'active_store' => $activeStore ? [
                    'id' => $activeStore->id,
                    'store_id' => $activeStore->store_id,
                    'name' => $activeStore->name,
                    'address' => $activeStore->address,
                    'logo' => $activeStore->logo_path ? asset('storage/' . $activeStore->logo_path) : null,
                ] : null,
            ],
        ]);
    }

    public function updateTheme(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => 'required|in:light,dark',
        ]);

        $request->user()->update(['theme_preference' => $validated['theme']]);

        return response()->json(['success' => true, 'data' => ['theme' => $validated['theme']]]);
    }

    private function getAccessibleStores($user): \Illuminate\Support\Collection
    {
        if ($user->isRestrictedStaff()) {
            return $user->assignedStores()
                ->where('pos_enabled', true)
                ->where('status', '!=', 'deleted')
                ->get();
        }

        return Store::where('pos_enabled', true)
            ->where('status', '!=', 'deleted')
            ->get();
    }
}
