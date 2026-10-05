<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\RefreshTokenService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AdminAuthController extends ApiController
{
    use BuildsAuthResponses;

    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    public function setupStatus(): JsonResponse
    {
        return $this->ok([
            'requires_setup' => ! User::where('role', User::ROLE_SUPERADMIN)->exists(),
        ]);
    }

    public function setup(Request $request): JsonResponse
    {
        if (User::where('role', User::ROLE_SUPERADMIN)->exists()) {
            return $this->error('Platform is already set up. Please sign in.', 409);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'role' => User::ROLE_SUPERADMIN,
            'status' => 'active',
            'is_verified' => true,
            'email_verified_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        try {
            app(\Database\Seeders\SpatiePermissionSeeder::class)->run();
            setPermissionsTeamId(null);
            $user->assignRole('Super Admin');
        } catch (\Throwable $e) {
            Log::warning('api.admin.setup_role_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        $pair = $this->tokens->issuePair($user, 'admin', $request);

        Log::info('api.admin.onboarded', ['user_id' => $user->id]);

        return $this->ok([
            ...$pair,
            'user' => $this->userPayload($user),
        ], 'Admin account created.', 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])
            ->whereIn('role', [User::ROLE_SUPERADMIN, User::ROLE_ADMIN])
            ->first();

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

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $user = User::where('email', $data['email'])
            ->whereIn('role', [User::ROLE_SUPERADMIN, User::ROLE_ADMIN])
            ->first();

        if (! $user || ! OtpService::verify($data['email'], $data['otp'], 'login')) {
            return $this->error('Invalid or expired verification code.');
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $pair = $this->tokens->issuePair($user, 'admin', $request);

        Log::info('api.admin.login_success', ['user_id' => $user->id]);

        return $this->ok([
            ...$pair,
            'user' => $this->userPayload($user),
        ], 'Signed in successfully.');
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])
            ->whereIn('role', [User::ROLE_SUPERADMIN, User::ROLE_ADMIN])
            ->first();

        if ($user) {
            $this->sendOtp($user->email, 'login');
        }

        return $this->ok([], 'If the account exists, a new verification code has been sent.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])
            ->whereIn('role', [User::ROLE_SUPERADMIN, User::ROLE_ADMIN])
            ->first();

        if ($user) {
            $this->sendOtp($user->email, 'password_reset');
        }

        return $this->ok([], 'If the email exists, a verification code will be sent.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::where('email', $data['email'])
            ->whereIn('role', [User::ROLE_SUPERADMIN, User::ROLE_ADMIN])
            ->first();

        if (! $user || ! OtpService::verify($data['email'], $data['otp'], 'password_reset')) {
            return $this->error('Invalid or expired verification code.');
        }

        $user->forceFill(['password' => $data['password']])->save();

        $this->tokens->revokeAllAccessTokens($user, 'admin');
        $this->refreshTokens->revokeAllFor($user, 'admin');

        Log::info('api.admin.password_reset', ['user_id' => $user->id]);

        return $this->ok([], 'Password reset. You can now sign in.');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->ok(['user' => $this->userPayload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['nullable', 'string']]);

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
