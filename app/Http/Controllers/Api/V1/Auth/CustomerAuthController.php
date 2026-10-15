<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ConsentType;
use App\Exceptions\StorefrontStoreUnresolvedException;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use App\Http\Requests\Auth\CustomerForgotPasswordRequest;
use App\Http\Requests\Auth\CustomerLoginRequest;
use App\Http\Requests\Auth\CustomerLogoutRequest;
use App\Http\Requests\Auth\CustomerRegisterRequest;
use App\Http\Requests\Auth\CustomerResendOtpRequest;
use App\Http\Requests\Auth\CustomerResetPasswordRequest;
use App\Http\Requests\Auth\CustomerVerifyOtpRequest;
use App\Http\Resources\Auth\CustomerResource;
use App\Models\Customer;
use App\Models\Otp;
use App\Models\Store;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\RefreshTokenService;
use App\Services\ConsentService;
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
 * the customer payload in App\Http\Resources\Auth\CustomerResource. The
 * multi-step workflows delegate to OtpService, ApiTokenService and
 * RefreshTokenService; the OTP mail itself lives in the shared
 * BuildsAuthResponses concern.
 *
 * ── Scope ────────────────────────────────────────────────────────────────
 *
 * A customer account belongs to exactly one business, and one email may hold a
 * separate account at each. That makes the business a required part of every
 * lookup here, and every `Customer::where('email', …)` in this file is scoped
 * by it.
 *
 * The lookups previously were not, and neither were the OTPs they checked
 * against. An OTP keyed on the address alone is valid at every business that
 * address trades with, and a reset that resolves the row by email alone resets
 * whichever of them the database happened to return first. Together those let
 * a code mailed for one business reset an account at another — which is what
 * the scoping in this file and in OtpService closes.
 *
 * Two callers reach these methods. The canonical routes carry the store in the
 * path. The routes that were live before this existed carry it nowhere, so
 * `resolveAuthStore()` looks for it in the body, in a header, and finally in
 * the `Origin` host — which last rung is what keeps the storefront already in
 * production signing customers in while it is retired underneath it. A request
 * that names no store *and* cannot be matched to one is refused rather than
 * guessed at; guessing is the defect.
 */
class CustomerAuthController extends ApiController
{
    use BuildsAuthResponses, ResolvesStorefrontContext;

    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly RefreshTokenService $refreshTokens,
        private readonly ConsentService $consents,
    ) {}

    public function register(CustomerRegisterRequest $request): JsonResponse
    {
        $store = $this->requireStore($request);
        $data = $request->validated();
        $businessId = (int) $store->business_id;

        $existing = $this->customerByEmail($businessId, $data['email']);

        if ($existing && $existing->status === Customer::STATUS_SUSPENDED) {
            return $this->error('This account is suspended. Please contact support.', 403);
        }

        if ($existing && $existing->email_verified_at !== null) {
            return $this->error('This email already has an account here. Please sign in or reset your password.', 422);
        }

        try {
            $customer = $existing
                ? $this->claim($existing, $data, $request->ip())
                : Customer::create([
                    'business_id' => $businessId,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'] ?? '',
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'password' => Hash::make($data['password']),
                    'status' => Customer::STATUS_ACTIVE,
                    'ip_address' => $request->ip(),
                    'registered_at' => now(),
                ]);
        } catch (QueryException $e) {
            // The unique rule can lose a race with a concurrent signup; the
            // insert's duplicate-key error is the same user-facing 422.
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->error('This email already has an account here. Please sign in or reset your password.', 422);
            }

            throw $e;
        }

        // Registering is accepting the terms. Recorded as a fact with a date
        // and the surface it happened on, because that is a question a consent
        // trail exists to answer and there is no setting to read it from: the
        // storefront shows the notice as part of the sign-up form rather than
        // offering a checkbox somebody could be registered without.
        $this->consents->record(
            $customer,
            ConsentType::TERMS,
            true,
            (string) ($data['source'] ?? 'account'),
            $request->ip(),
            force: true,
        );

        $this->sendOtp($customer->email, 'account', 10, $businessId);

        Log::info('api.customer.register', [
            'customer_id' => $customer->id,
            'business_id' => $businessId,
            'claimed' => $existing !== null,
        ]);

        return $this->ok([
            'otp_required' => true,
            'email' => $customer->email,
            'context' => 'account',
        ], 'We sent a verification code to your email.', 201);
    }

    public function login(CustomerLoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $store = $this->resolveAuthStore($request);

        $customer = $store
            ? $this->customerByEmail((int) $store->business_id, $data['email'])
            : $this->customerByPasswordOnly($data['email'], $data['password']);

        if (! $customer || ! Hash::check($data['password'], $customer->password)) {
            return $this->error('The provided credentials do not match our records.');
        }

        if ($customer->status !== Customer::STATUS_ACTIVE) {
            return $this->error('This account is '.strtolower($customer->status).'. Please contact support.', 403);
        }

        // Verification gates sign-in, not just ordering. Without this an
        // unverified row — one checkout fabricated, or one somebody started
        // and abandoned — could be registered over by a stranger, who would
        // then be handed whatever order history sits on it. A fresh code is
        // sent so the client can go straight to the verification screen.
        if ($customer->email_verified_at === null) {
            $this->sendOtp($customer->email, 'account', 10, $customer->business_id);

            return $this->error(
                'Please verify your email address to continue. We sent you a new code.',
                403,
                ['code' => ['email_unverified']]
            );
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
        $store = $this->resolveAuthStore($request);

        $customer = $store
            ? $this->customerByEmail((int) $store->business_id, $data['email'])
            : $this->customerForScopedOtp($data['email'], $data['otp'], 'account');

        if (! $customer || ! OtpService::verify($data['email'], $data['otp'], 'account', $customer->business_id)) {
            return $this->error('Invalid or expired verification code.');
        }

        $customer->forceFill([
            'email_verified_at' => $customer->email_verified_at ?? now(),
            'status' => Customer::STATUS_ACTIVE,
            'last_login' => now(),
            // A verified account is a registered one even if the row predates
            // this column: verification is the moment it becomes an account.
            'registered_at' => $customer->registered_at ?? now(),
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
        $store = $this->requireStore($request);

        $customer = $this->customerByEmail((int) $store->business_id, $data['email']);

        if ($customer) {
            $this->sendOtp($customer->email, 'account', 10, $customer->business_id);
        }

        return $this->ok([], 'If the account exists, a new verification code has been sent.');
    }

    public function forgotPassword(CustomerForgotPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $store = $this->requireStore($request);

        $customer = $this->customerByEmail((int) $store->business_id, $data['email']);

        if ($customer) {
            $this->sendOtp($customer->email, 'customer_password_reset', 10, $customer->business_id);
        }

        return $this->ok([], 'If that email is registered, we will send a verification code.');
    }

    public function resetPassword(CustomerResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $store = $this->resolveAuthStore($request);

        $customer = $store
            ? $this->customerByEmail((int) $store->business_id, $data['email'])
            : $this->customerForScopedOtp($data['email'], $data['otp'], 'customer_password_reset');

        if (! $customer || ! OtpService::verify($data['email'], $data['otp'], 'customer_password_reset', $customer->business_id)) {
            return $this->error('Invalid or expired verification code.');
        }

        $customer->forceFill([
            'password' => Hash::make($data['password']),
            // Completing a reset through a mailed code is proof of mailbox
            // control, which is exactly what verification attests to. This is
            // also the advertised way back in for somebody whose account was
            // fabricated by an older checkout and who therefore has no
            // password to sign in with.
            'email_verified_at' => $customer->email_verified_at ?? now(),
            'registered_at' => $customer->registered_at ?? now(),
        ])->save();

        $this->tokens->revokeAllAccessTokens($customer, 'customer');
        $this->refreshTokens->revokeAllFor($customer, 'customer');

        Log::info('api.customer.password_reset', ['customer_id' => $customer->id]);

        return $this->ok([], 'Password updated. You can now sign in.');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        // A token for a row that belongs to no business can read nothing:
        // there is no tenant to scope orders, payments or addresses to. Those
        // rows are the ones the backfill could not repair, and this is what
        // stops one being usable.
        if ($customer->business_id === null) {
            return $this->error('This account is not attached to a store. Please contact support.', 403);
        }

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

    /**
     * The business this request is for, or a refusal.
     *
     * Used by the flows that cannot proceed without one at all. `register`
     * needs it to know which customer list to add to; `resend-otp` and
     * `forgot-password` need it to mint a code, because a code that is not
     * scoped to a business is exactly the thing this change removes.
     *
     * Those two refuse rather than guessing across candidate businesses. Sending
     * one code per candidate would mail several different codes to the same
     * inbox with nothing to tell them apart, which is worse than a clear
     * refusal — and it is unreachable in practice, since a browser always sends
     * the `Origin` this reads.
     */
    private function requireStore(Request $request): Store
    {
        $store = $this->resolveAuthStore($request);

        if (! $store) {
            throw new StorefrontStoreUnresolvedException;
        }

        return $store;
    }

    /**
     * A customer of one business, by email.
     *
     * The single funnel for every lookup in this file, so the tenancy scope
     * cannot be forgotten in one of them — which is how the reset was reaching
     * another business's account.
     */
    private function customerByEmail(int $businessId, string $email): ?Customer
    {
        return Customer::query()
            ->forBusiness($businessId)
            ->where('email', $email)
            ->first();
    }

    /**
     * Which business's account a code proves ownership of, when the request
     * named no business.
     *
     * Reached only from the transitional paths with no `Origin`. The code
     * itself is the evidence: it is scoped to one business, so a match names
     * the business, and more than one match is refused rather than picked
     * between. Nothing is read from the account until the code has been
     * checked against the scope the account belongs to.
     */
    private function customerForScopedOtp(string $email, string $code, string $type): ?Customer
    {
        $businessIds = Otp::query()
            ->where('identifier', $email)
            ->where('code', $code)
            ->where('type', $type)
            ->where('is_verified', false)
            ->whereNotNull('business_id')
            ->pluck('business_id')
            ->unique()
            ->values();

        if ($businessIds->count() > 1) {
            throw new StorefrontStoreUnresolvedException('store_ambiguous');
        }

        $businessId = $businessIds->first();

        if (! $businessId) {
            return null;
        }

        return $this->customerByEmail((int) $businessId, $email);
    }

    /**
     * Resolve by password across customers, for sign-in that named no business.
     *
     * The password is what decides, and it decides for one business only: an
     * attacker holding the password to business A's account gets A's account
     * and nothing else, because B's row does not match. Matching more than one
     * means the same person reused a password across two businesses, and the
     * server refuses to choose for them.
     *
     * Rows with no business are excluded outright. They belong to nobody, so
     * nothing about them can be signed into.
     */
    private function customerByPasswordOnly(string $email, string $password): ?Customer
    {
        $candidates = Customer::query()
            ->where('email', $email)
            ->whereNotNull('business_id')
            ->get()
            ->filter(fn (Customer $candidate) => Hash::check($password, $candidate->password))
            ->values();

        if ($candidates->count() > 1) {
            throw new StorefrontStoreUnresolvedException('store_ambiguous');
        }

        return $candidates->first();
    }

    /**
     * Turn a row checkout fabricated, or an account somebody started and
     * abandoned, into a real account.
     *
     * Nothing is reassigned. Orders already point at this row through
     * `orders.customer_id` and `(business_id, email)` is unique, so there is
     * no competing row for the history to be moved from — claiming is simply
     * the first moment the row has a password its owner chose.
     *
     * @param  array<string, mixed>  $data
     */
    private function claim(Customer $customer, array $data, ?string $ipAddress): Customer
    {
        $customer->forceFill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? '',
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'registered_at' => now(),
            'ip_address' => $ipAddress,
        ])->save();

        return $customer;
    }
}
