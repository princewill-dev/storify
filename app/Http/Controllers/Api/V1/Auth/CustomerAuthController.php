<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Http\Requests\Auth\CustomerForgotPasswordRequest;
use App\Http\Requests\Auth\CustomerLoginRequest;
use App\Http\Requests\Auth\CustomerLogoutRequest;
use App\Http\Requests\Auth\CustomerRegisterRequest;
use App\Http\Requests\Auth\CustomerResendOtpRequest;
use App\Http\Requests\Auth\CustomerResetPasswordRequest;
use App\Http\Requests\Auth\CustomerVerifyOtpRequest;
use App\Http\Resources\Auth\CustomerResource;
use App\Models\Customer;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\RefreshTokenService;
use App\Services\OtpService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Storefront (customer) app authentication.
 *
 * Layering: the HTTP shape (statuses, messages, envelope) and the workflow
 * sequence stay here; field validation lives in App\Http\Requests\Auth and
 * the customer payload in App\Http\Resources\Auth\CustomerResource.
 *
 * No repository or service is warranted: every row read is a single-row
 * lookup by unique email with no composition or tenancy scope, and the
 * multi-step workflows already delegate to OtpService, ApiTokenService and
 * RefreshTokenService — the OTP mail itself lives in the shared
 * BuildsAuthResponses concern.
 */
class CustomerAuthController extends ApiController
{
    use BuildsAuthResponses;

    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    public function register(CustomerRegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $customer = Customer::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? '',
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => Hash::make($data['password']),
                'status' => Customer::STATUS_ACTIVE,
                'ip_address' => $request->ip(),
            ]);
        } catch (QueryException $e) {
            // The unique rules can lose a race with a concurrent signup; the
            // insert's duplicate-key error is the same user-facing 422.
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->error('This email already has an account. Please sign in or reset your password.', 422);
            }

            throw $e;
        }

        $this->sendOtp($customer->email, 'account');

        Log::info('api.customer.register', ['customer_id' => $customer->id]);

        return $this->ok([
            'otp_required' => true,
            'email' => $customer->email,
            'context' => 'account',
        ], 'We sent a verification code to your email.', 201);
    }

    public function login(CustomerLoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $customer = Customer::where('email', $data['email'])->first();

        if (! $customer || ! Hash::check($data['password'], $customer->password)) {
            return $this->error('The provided credentials do not match our records.');
        }

        if ($customer->status !== Customer::STATUS_ACTIVE) {
            return $this->error('This account is '.strtolower($customer->status).'. Please contact support.', 403);
        }

        $customer->forceFill(['last_login' => now()])->save();

        $pair = $this->tokens->issuePair($customer, 'customer', $request);

        Log::info('api.customer.login', ['customer_id' => $customer->id]);

        return $this->ok([
            ...$pair,
            'user' => (new CustomerResource($customer))->resolve(),
        ], 'Signed in successfully.');
    }

    public function verifyOtp(CustomerVerifyOtpRequest $request): JsonResponse
    {
        $data = $request->validated();

        $customer = Customer::where('email', $data['email'])->first();

        if (! $customer || ! OtpService::verify($data['email'], $data['otp'], 'account')) {
            return $this->error('Invalid or expired verification code.');
        }

        $customer->forceFill([
            'email_verified_at' => $customer->email_verified_at ?? now(),
            'status' => Customer::STATUS_ACTIVE,
            'last_login' => now(),
        ])->save();

        $pair = $this->tokens->issuePair($customer, 'customer', $request);

        Log::info('api.customer.otp_verified', ['customer_id' => $customer->id]);

        return $this->ok([
            ...$pair,
            'user' => (new CustomerResource($customer))->resolve(),
        ], 'Account verified. Welcome!');
    }

    public function resendOtp(CustomerResendOtpRequest $request): JsonResponse
    {
        $data = $request->validated();

        $customer = Customer::where('email', $data['email'])->first();

        if ($customer) {
            $this->sendOtp($customer->email, 'account');
        }

        return $this->ok([], 'If the account exists, a new verification code has been sent.');
    }

    public function forgotPassword(CustomerForgotPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $customer = Customer::where('email', $data['email'])->first();

        if ($customer) {
            $this->sendOtp($customer->email, 'customer_password_reset');
        }

        return $this->ok([], 'If that email is registered, we will send a verification code.');
    }

    public function resetPassword(CustomerResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $customer = Customer::where('email', $data['email'])->first();

        if (! $customer || ! OtpService::verify($data['email'], $data['otp'], 'customer_password_reset')) {
            return $this->error('Invalid or expired verification code.');
        }

        $customer->forceFill(['password' => Hash::make($data['password'])])->save();

        $this->tokens->revokeAllAccessTokens($customer, 'customer');
        $this->refreshTokens->revokeAllFor($customer, 'customer');

        Log::info('api.customer.password_reset', ['customer_id' => $customer->id]);

        return $this->ok([], 'Password updated. You can now sign in.');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return $this->ok(['user' => (new CustomerResource($customer))->resolve()]);
    }

    public function logout(CustomerLogoutRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->tokens->revokeCurrentAccessToken($request);

        if (! empty($data['refresh_token'])) {
            $this->refreshTokens->revoke($data['refresh_token']);
        }

        return $this->ok([], 'You have been signed out.');
    }
}
