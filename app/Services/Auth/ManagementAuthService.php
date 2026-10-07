<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The management-app auth workflows behind `ManagementAuthController` — the
 * three sequences that touch more than one table:
 *
 * - `register()`: the `users` row (and, via the controller's OTP mail, the
 *   `otps` row), including the unique-key race the validation rules cannot
 *   close;
 * - `completeLogin()`: the `otps` row, the email-verification stamp, the
 *   `users.last_login_at` stamp and the token pair;
 * - `resetPassword()`: the `otps` row, `users.password` and the two
 *   revocations that kill every existing management session.
 *
 * Every step keeps the exact order the controller ran it in — the pair is
 * issued before its success log, the password is written before the tokens
 * are revoked, and the OTP contexts are checked in their original order
 * (`business_login` before `business_email_verification`, which matters
 * because consuming a code marks it used). No transaction was introduced:
 * the original flows had none.
 *
 * The OTP identifier handed to `OtpService` is always the submitted email,
 * never `$user->email`: the lookup that found the account is
 * case-insensitive in MySQL while the `otps` row was written with the
 * submitted string, and the original flows passed the submitted value. These
 * methods return a sentinel (`null`/`false`) for a bad code or a duplicate
 * account; the controller keeps the single 422 it maps each to.
 *
 * Register's OTP mail and its success log stay in the controller: the mail
 * call is the shared `BuildsAuthResponses::sendOtp()` trait, and the log has
 * always followed it.
 */
final class ManagementAuthService
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    /**
     * Create the business-owner account, translating the one database refusal
     * this endpoint has always handled by hand: a unique-key collision racing
     * the `unique:users,email` / `unique:customers,email` validation rules.
     * `null` means the account already exists; every other QueryException
     * keeps travelling out, exactly as before.
     *
     * @param  array{name: string, email: string, phone: string, password: string}  $data
     */
    public function register(array $data, Request $request): ?User
    {
        try {
            return User::create([
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
                return null;
            }

            throw $e;
        }
    }

    /**
     * Consume the emailed code and adopt the session.
     *
     * An email-verification code also marks the address verified before the
     * login is stamped. Returns null when neither accepted context matches,
     * which the controller reports as "invalid or expired".
     *
     * @return array{context: string, pair: array<string, mixed>}|null
     */
    public function completeLogin(User $user, string $email, string $code, Request $request): ?array
    {
        $context = null;

        if (OtpService::verify($email, $code, 'business_login')) {
            $context = 'business_login';
        } elseif (OtpService::verify($email, $code, 'business_email_verification')) {
            $context = 'business_email_verification';
        }

        if ($context === null) {
            return null;
        }

        if ($context === 'business_email_verification') {
            $user->forceFill(['is_verified' => true, 'email_verified_at' => now()])->save();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $pair = $this->tokens->issuePair($user, 'management', $request);

        Log::info('api.management.otp_verified', ['user_id' => $user->id, 'context' => $context]);

        return ['context' => $context, 'pair' => $pair];
    }

    /**
     * Complete a password reset: consume the `business_password_reset` code,
     * write the new password (clearing any forced-change flag), then revoke
     * every access and refresh token on the management audience so no session
     * survives the reset.
     *
     * @return bool false when the code is wrong, expired or already used —
     *              the controller's 422.
     */
    public function resetPassword(User $user, string $email, string $code, string $password): bool
    {
        if (! OtpService::verify($email, $code, 'business_password_reset')) {
            return false;
        }

        $user->forceFill([
            'password' => $password,
            'force_password_change' => false,
        ])->save();

        $this->tokens->revokeAllAccessTokens($user, 'management');
        $this->refreshTokens->revokeAllFor($user, 'management');

        Log::info('api.management.password_reset', ['user_id' => $user->id]);

        return true;
    }
}
