<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Models\Impersonation;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\RefreshTokenService;
use App\Services\OtpService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ManagementAuthController extends ApiController
{
    use BuildsAuthResponses;

    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:customers,email'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
                'role' => User::ROLE_BUSINESS_OWNER,
                'status' => 'active',
                'is_verified' => false,
                'ip_address' => $request->ip(),
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->error('We already have an account with that phone number or email.', 422);
            }

            throw $e;
        }

        $this->sendOtp($user->email, 'business_email_verification');

        Log::info('api.management.register', ['user_id' => $user->id]);

        return $this->ok([
            'otp_required' => true,
            'email' => $user->email,
            'context' => 'business_email_verification',
        ], 'We sent a verification code to your email.', 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->error('Invalid credentials.', 422);
        }

        if ($user->status === 'suspended' || $user->status === 'deleted') {
            return $this->error('Your account is currently '.$user->status.'. Please contact support.', 403);
        }

        // Staff sign in directly (no OTP), matching the web flow.
        if ($user->isStaff()) {
            $user->forceFill(['last_login_at' => now()])->save();

            $pair = $this->tokens->issuePair($user, 'management', $request);

            Log::info('api.management.staff_login', ['user_id' => $user->id]);

            return $this->ok([
                ...$pair,
                'user' => $this->userPayload($user),
                'next' => $this->nextStep($user),
            ], 'Welcome, '.$user->name.'!');
        }

        if (! $user->isBusinessOwner() && ! $user->isAdmin()) {
            return $this->error('This account is not a business account.', 403);
        }

        if (! $user->is_verified) {
            $this->sendOtp($user->email, 'business_email_verification');

            return $this->ok([
                'otp_required' => true,
                'email' => $user->email,
                'context' => 'business_email_verification',
            ], 'Please verify your email to continue. We just sent a code.');
        }

        $this->sendOtp($user->email, 'business_login');

        return $this->ok([
            'otp_required' => true,
            'email' => $user->email,
            'context' => 'business_login',
        ], 'We emailed you a one-time code.');
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return $this->error('Invalid or expired verification code.');
        }

        $context = null;

        if (OtpService::verify($data['email'], $data['otp'], 'business_login')) {
            $context = 'business_login';
        } elseif (OtpService::verify($data['email'], $data['otp'], 'business_email_verification')) {
            $context = 'business_email_verification';
        }

        if ($context === null) {
            return $this->error('Invalid or expired verification code.');
        }

        if ($context === 'business_email_verification') {
            $user->forceFill(['is_verified' => true, 'email_verified_at' => now()])->save();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $pair = $this->tokens->issuePair($user, 'management', $request);

        Log::info('api.management.otp_verified', ['user_id' => $user->id, 'context' => $context]);

        return $this->ok([
            ...$pair,
            'context' => $context,
            'user' => $this->userPayload($user),
            'next' => $this->nextStep($user),
        ], 'Signed in successfully.');
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'context' => ['nullable', 'in:business_login,business_email_verification'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $context = $data['context'] ?? ($user->is_verified ? 'business_login' : 'business_email_verification');
            $this->sendOtp($user->email, $context);
        }

        return $this->ok([], 'If the account exists, a new verification code has been sent.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $this->sendOtp($user->email, 'business_password_reset');
        }

        return $this->ok([], 'If that email is registered, we will send a verification code.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! OtpService::verify($data['email'], $data['otp'], 'business_password_reset')) {
            return $this->error('Invalid or expired verification code.');
        }

        $user->forceFill([
            'password' => $data['password'],
            'force_password_change' => false,
        ])->save();

        $this->tokens->revokeAllAccessTokens($user, 'management');
        $this->refreshTokens->revokeAllFor($user, 'management');

        Log::info('api.management.password_reset', ['user_id' => $user->id]);

        return $this->ok([], 'Password updated. You can now sign in.');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $impersonation = Impersonation::activeForToken($user->currentAccessToken());
        $impersonator = $impersonation?->impersonator;

        return $this->ok([
            'user' => $this->userPayload($user),
            'next' => $this->nextStep($user),
            'impersonator' => $impersonator ? [
                'id' => $impersonator->id,
                'name' => $impersonator->name,
                'email' => $impersonator->email,
                'impersonation_id' => $impersonation->id,
                'started_at' => $impersonation->started_at?->toISOString(),
            ] : null,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $user->update($data);

        return $this->ok(['user' => $this->userPayload($user->fresh())], 'Profile updated.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // A forced change (first sign-in after an invitation) has no known
        // current password to prove, so only the new one is collected.
        $forced = (bool) $user->force_password_change;

        $rules = ['password' => ['required', 'string', 'min:8', 'confirmed']];

        if (! $forced) {
            $rules['current_password'] = ['required', 'string'];
        }

        $data = $request->validate($rules);

        if (! $forced && ! Hash::check($data['current_password'], $user->password)) {
            return $this->error('The current password is incorrect.', 422, [
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => $data['password'],
            'force_password_change' => false,
        ])->save();

        Log::info('api.management.password_changed', ['user_id' => $user->id]);

        return $this->ok([], 'Password updated successfully.');
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
