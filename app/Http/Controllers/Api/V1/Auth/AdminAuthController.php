<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Http\Requests\Auth\AdminEmailRequest;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Http\Requests\Auth\AdminLogoutRequest;
use App\Http\Requests\Auth\AdminResetPasswordRequest;
use App\Http\Requests\Auth\AdminSetupRequest;
use App\Http\Requests\Auth\AdminVerifyOtpRequest;
use App\Models\User;
use App\Repositories\Auth\AdminAuthRepository;
use App\Services\Auth\AdminAuthService;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Platform-admin authentication — the console's one-time bootstrap, sign-in
 * and session endpoints (public except `me`, `logout` and `logout-all`).
 *
 * Layering: this class keeps the HTTP shape — status codes, message strings,
 * the envelope, the failure log lines that belong to a branch. Validation
 * lives in the `App\Http\Requests\Auth\Admin*` classes, the platform-role
 * scoped account lookups in `AdminAuthRepository`, and the three multi-table
 * workflows (bootstrap, login-OTP completion, password reset) in
 * `AdminAuthService`.
 *
 * Deliberate non-changes:
 * - The account lookup keeps its platform-role scope. Without it a tenant
 *   account could take the OTP route into the admin console; a wrong email
 *   and a wrong password also share one 422, so the endpoint cannot be used
 *   to probe which addresses exist.
 * - `setup()`'s 409 guard stays in the body, in order (guard order is
 *   asserted behaviour). Extracting `AdminSetupRequest` means validation now
 *   runs before the guard, so an already-set-up request with a malformed
 *   payload earns 422 instead of 409 — the accepted, codebase-wide
 *   consequence of the extraction. A valid payload still meets the guard
 *   first.
 * - No transaction was introduced anywhere: these flows had none, and the
 *   bootstrap's best-effort role provisioning is caught and warned in
 *   `AdminAuthService` rather than rolled back.
 * - `BuildsAuthResponses::userPayload` still shapes the `user` block — it is
 *   the shared contract every auth controller emits.
 */
class AdminAuthController extends ApiController
{
    use BuildsAuthResponses;

    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly RefreshTokenService $refreshTokens,
        private readonly AdminAuthRepository $accounts,
        private readonly AdminAuthService $auth,
    ) {}

    public function setupStatus(): JsonResponse
    {
        return $this->ok([
            'requires_setup' => ! $this->accounts->superAdminExists(),
        ]);
    }

    public function setup(AdminSetupRequest $request): JsonResponse
    {
        if ($this->accounts->superAdminExists()) {
            return $this->error('Platform is already set up. Please sign in.', 409);
        }

        ['user' => $user, 'pair' => $pair] = $this->auth->provision($request->validated(), $request);

        return $this->ok([
            ...$pair,
            'user' => $this->userPayload($user),
        ], 'Admin account created.', 201);
    }

    public function login(AdminLoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $this->accounts->findPlatformAccountByEmail($data['email']);

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            Log::warning('api.admin.login_failed', ['email' => substr($data['email'], 0, 3).'***']);

            return $this->error('Invalid credentials.', 422);
        }

        if ($user->status === 'suspended' || $user->status === 'deleted') {
            return $this->error('Your account is currently '.$user->status.'.', 403);
        }

        $this->sendOtp($user->email, 'login');

        return $this->ok([
            'otp_required' => true,
            'email' => $user->email,
            'context' => 'login',
        ], 'We emailed you a one-time code.');
    }

    public function verifyOtp(AdminVerifyOtpRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $this->accounts->findPlatformAccountByEmail($data['email']);

        // A missing account skips the OTP check entirely, exactly as the
        // original short-circuited condition did; a wrong or expired code
        // comes back as null.
        $pair = $user
            ? $this->auth->completeLogin($user, $data['email'], $data['otp'], $request)
            : null;

        if ($pair === null) {
            return $this->error('Invalid or expired verification code.');
        }

        return $this->ok([
            ...$pair,
            'user' => $this->userPayload($user),
        ], 'Signed in successfully.');
    }

    public function resendOtp(AdminEmailRequest $request): JsonResponse
    {
        $user = $this->accounts->findPlatformAccountByEmail($request->validated()['email']);

        if ($user) {
            $this->sendOtp($user->email, 'login');
        }

        return $this->ok([], 'If the account exists, a new verification code has been sent.');
    }

    public function forgotPassword(AdminEmailRequest $request): JsonResponse
    {
        $user = $this->accounts->findPlatformAccountByEmail($request->validated()['email']);

        if ($user) {
            $this->sendOtp($user->email, 'password_reset');
        }

        return $this->ok([], 'If the email exists, a verification code will be sent.');
    }

    public function resetPassword(AdminResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $this->accounts->findPlatformAccountByEmail($data['email']);

        if (! $user || ! $this->auth->resetPassword($user, $data['email'], $data['otp'], $data['password'])) {
            return $this->error('Invalid or expired verification code.');
        }

        return $this->ok([], 'Password reset. You can now sign in.');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->ok(['user' => $this->userPayload($user)]);
    }

    public function logout(AdminLogoutRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->tokens->revokeCurrentAccessToken($request);

        if (! empty($data['refresh_token'])) {
            $this->refreshTokens->revoke($data['refresh_token']);
        }

        return $this->ok([], 'You have been signed out.');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->tokens->revokeAllAccessTokens($user);
        $this->refreshTokens->revokeAllFor($user);

        return $this->ok([], 'You have been signed out of all devices.');
    }
}
