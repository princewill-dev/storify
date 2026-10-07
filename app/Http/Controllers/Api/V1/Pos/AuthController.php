<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\PosLoginRequest;
use App\Http\Requests\Pos\PosSwitchStoreRequest;
use App\Http\Requests\Pos\PosUpdateThemeRequest;
use App\Http\Requests\Pos\PosVerifyPinRequest;
use App\Http\Resources\Pos\PosStoreResource;
use App\Http\Resources\Pos\PosUserResource;
use App\Models\Store;
use App\Repositories\Pos\PosAuthRepository;
use App\Services\Pos\PosAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * POS terminal authentication.
 *
 * Layering: this class keeps the HTTP shape — status codes, message strings,
 * the envelope and which branch renders which of them. Validation lives in
 * the `App\Http\Requests\Pos\Pos*` classes, the store scoping and PIN
 * lookups in `PosAuthRepository`, the PIN-switch and token-issuing workflows
 * in `PosAuthService`, and the payload blocks in `PosUserResource` /
 * `PosStoreResource`.
 *
 * Deliberate non-changes:
 * - The session adoption stays here: `Auth::guard('web')->attempt()` signs
 *   the caller into the web guard AND mints the Sanctum token — two guards in
 *   one request, exactly as before — and the role/PIN refusals that gate it
 *   are policy with message strings, so they stay controller-owned.
 * - The envelope is this terminal's own `success` + `data` shape, not
 *   `ApiController::ok()`, so it is not converted to that helper.
 * - `switchStore` answers 403 for a store the scoping query rejects and then
 *   loads the row with `findOrFail` (404 if it vanished in between) — the
 *   order and the code are as they always were.
 * - No transaction was introduced: none of these flows had one.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly PosAuthRepository $repository,
        private readonly PosAuthService $service,
    ) {}

    public function login(PosLoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! Auth::guard('web')->attempt($validated, false)) {
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

        if ($user->role === 'staff' && ! $user->pos_pin) {
            return response()->json([
                'success' => false,
                'message' => 'Your account does not have a POS PIN set. Please contact your manager to set one up in the management portal.',
            ], 403);
        }

        $token = $this->service->issueTerminalToken($user);
        $stores = $this->repository->accessibleStoresFor($user);
        $activeStore = $stores->count() === 1 ? $stores->first() : null;

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'idle_timeout_minutes' => (int) config('pos.idle_timeout_minutes'),
                'user' => (new PosUserResource($user))->forLogin()->resolve($request),
                'stores' => $stores->map(fn (Store $store) => (new PosStoreResource($store))->resolve($request))->all(),
                'active_store' => $activeStore ? (new PosStoreResource($activeStore))->resolve($request) : null,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $stores = $this->repository->accessibleStoresFor($user);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => (new PosUserResource($user))->resolve($request),
                'stores' => $stores->map(fn (Store $store) => (new PosStoreResource($store))->withoutLogo()->resolve($request))->all(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Logged out.']);
    }

    public function switchStore(PosSwitchStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! $this->repository->userCanSwitchToStore($request->user(), (int) $validated['store_id'])) {
            return response()->json(['success' => false, 'message' => 'You are not assigned to this store.'], 403);
        }

        $store = Store::findOrFail($validated['store_id']);

        return response()->json([
            'success' => true,
            'data' => [
                'store' => (new PosStoreResource($store))->resolve($request),
            ],
        ]);
    }

    public function verifyPin(PosVerifyPinRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $currentUser = $request->user();

        $target = $this->service->switchUserByPin($currentUser, $validated['pin']);

        if ($target === null) {
            return response()->json(['success' => false, 'message' => 'Invalid PIN.'], 422);
        }

        if ($target === $currentUser) {
            return response()->json(['success' => true, 'data' => ['switched' => false]]);
        }

        $token = $this->service->issueTerminalToken($target);
        $stores = $this->repository->accessibleStoresFor($target);
        $activeStore = $stores->count() === 1 ? $stores->first() : null;

        return response()->json([
            'success' => true,
            'data' => [
                'switched' => true,
                'token' => $token,
                'idle_timeout_minutes' => (int) config('pos.idle_timeout_minutes'),
                'user' => (new PosUserResource($target))->forPinSwitch()->resolve($request),
                'stores' => $stores->map(fn (Store $store) => (new PosStoreResource($store))->resolve($request))->all(),
                'active_store' => $activeStore ? (new PosStoreResource($activeStore))->resolve($request) : null,
            ],
        ]);
    }

    public function updateTheme(PosUpdateThemeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $request->user()->update(['theme_preference' => $validated['theme']]);

        return response()->json(['success' => true, 'data' => ['theme' => $validated['theme']]]);
    }
}
