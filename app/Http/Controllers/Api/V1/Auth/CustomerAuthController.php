<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Models\Customer;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\RefreshTokenService;
use App\Services\OtpService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class CustomerAuthController extends ApiController
{
    use BuildsAuthResponses;

    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:190'],
            'last_name' => ['nullable', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email', 'unique:customers,email'],
            'phone' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

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

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

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
            'user' => $this->customerPayload($customer),
        ], 'Signed in successfully.');
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

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
            'user' => $this->customerPayload($customer),
        ], 'Account verified. Welcome!');
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $customer = Customer::where('email', $data['email'])->first();

        if ($customer) {
            $this->sendOtp($customer->email, 'account');
        }

        return $this->ok([], 'If the account exists, a new verification code has been sent.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $customer = Customer::where('email', $data['email'])->first();

        if ($customer) {
            $this->sendOtp($customer->email, 'customer_password_reset');
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

        return $this->ok(['user' => $this->customerPayload($customer)]);
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
}
