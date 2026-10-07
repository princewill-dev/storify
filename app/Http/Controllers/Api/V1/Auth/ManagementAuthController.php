<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Http\Requests\Auth\ManagementChangePasswordRequest;
use App\Http\Requests\Auth\ManagementForgotPasswordRequest;
use App\Http\Requests\Auth\ManagementLoginRequest;
use App\Http\Requests\Auth\ManagementLogoutRequest;
use App\Http\Requests\Auth\ManagementRegisterRequest;
use App\Http\Requests\Auth\ManagementResendOtpRequest;
use App\Http\Requests\Auth\ManagementResetPasswordRequest;
use App\Http\Requests\Auth\ManagementUpdateProfileRequest;
use App\Http\Requests\Auth\ManagementVerifyOtpRequest;
use App\Models\Impersonation;
use App\Models\User;
use App\Repositories\Auth\ManagementAuthRepository;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\ManagementAuthService;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Management-app authentication — the business sign-in surface (public except
 * `me`, profile/change-password and the logout endpoints).
 *
 * Layering: this class keeps the HTTP shape — status codes, message strings,
 * the envelope, and the branch that decides which of them an account gets.
 * Validation lives in the `App\Http\Requests\Auth\Management*` classes, the
 * shared account lookup in `ManagementAuthRepository`, and the three
 * multi-table workflows (registration, OTP sign-in completion, password
 * reset) in `ManagementAuthService`. The `user`/`next` blocks are still
 * shaped by `BuildsAuthResponses::userPayload()/nextStep()` — the shared
 * contract every auth controller emits.
 *
 * Deliberate non-changes:
 * - Staff sign in directly, without an OTP challenge, matching the web flow;
 *   owners and admins continue to an emailed code, and any other role is
 *   refused with the generic "not a business account" 403.
 * - `resend-otp` and `forgot-password` answer with the same generic line
 *   whether or not the email matched, so neither endpoint can be used to
 *   enumerate accounts.
 * - No transaction was introduced anywhere: these flows had none.
 */
class ManagementAuthController extends ApiController
{
    use BuildsAuthResponses;

    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly RefreshTokenService $refreshTokens,
        private readonly ManagementAuthRepository $accounts,
        private readonly ManagementAuthService $auth,
    ) {}

    public function register(ManagementRegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request->validated(), $request);

        if ($user === null) {
            return $this->error('We already have an account with that phone number or email.', 422);
        }

        $this->sendOtp($user->email, 'business_email_verification');

        Log::info('api.management.register', ['user_id' => $user->id]);

        return $this->ok([
            'otp_required' => true,
            'email' => $user->email,
            'context' => 'business_email_verification',
        ], 'We sent a verification code to your email.', 201);
    }

    public function login(ManagementLoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $this->accounts->findByEmail($data['email']);

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

    public function verifyOtp(ManagementVerifyOtpRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $this->accounts->findByEmail($data['email']);

        // A missing account skips the OTP check entirely, exactly as the
        // original short-circuited condition did; a wrong or expired code
        // comes back as null.
        $result = $user
            ? $this->auth->completeLogin($user, $data['email'], $data['otp'], $request)
            : null;

        if ($result === null) {
            return $this->error('Invalid or expired verification code.');
        }

        return $this->ok([
            ...$result['pair'],
            'context' => $result['context'],
            'user' => $this->userPayload($user),
            'next' => $this->nextStep($user),
        ], 'Signed in successfully.');
    }

    public function resendOtp(ManagementResendOtpRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $this->accounts->findByEmail($data['email']);

        if ($user) {
            $context = $data['context'] ?? ($user->is_verified ? 'business_login' : 'business_email_verification');
            $this->sendOtp($user->email, $context);
        }

        return $this->ok([], 'If the account exists, a new verification code has been sent.');
    }

    public function forgotPassword(ManagementForgotPasswordRequest $request): JsonResponse
    {
        $user = $this->accounts->findByEmail($request->validated()['email']);

        if ($user) {
            $this->sendOtp($user->email, 'business_password_reset');
        }

        return $this->ok([], 'If that email is registered, we will send a verification code.');
    }

    public function resetPassword(ManagementResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $this->accounts->findByEmail($data['email']);

        if (! $user || ! $this->auth->resetPassword($user, $data['email'], $data['otp'], $data['password'])) {
            return $this->error('Invalid or expired verification code.');
        }

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

    public function updateProfile(ManagementUpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->update($request->validated());

        return $this->ok(['user' => $this->userPayload($user->fresh())], 'Profile updated.');
    }

    public function changePassword(ManagementChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validated();

        // A forced change (first sign-in after an invitation) has no known
        // current password to prove, so only the new one is collected; the
        // FormRequest applies the same condition to its rules.
        $forced = (bool) $user->force_password_change;

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

    public function logout(ManagementLogoutRequest $request): JsonResponse
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
