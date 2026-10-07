<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\OtpService;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The platform-admin auth workflows behind `AdminAuthController` — the three
 * sequences that touch more than one table:
 *
 * - `provision()`: the `users` row, the Spatie platform roles and the token
 *   pair for the one-time bootstrap;
 * - `completeLogin()`: the `otps` row, `users.last_login_at` and the pair;
 * - `resetPassword()`: the `otps` row, `users.password` and the two
 *   revocations that kill every existing session.
 *
 * Every step keeps the exact order the controller ran it in — the pair is
 * issued before its success log, the password is written before the tokens
 * are revoked — and no transaction was introduced: the original flows had
 * none, and the bootstrap's best-effort role provisioning (caught and
 * warned below) is its own deliberate failure policy, not a transaction to
 * wrap.
 *
 * The OTP identifier handed to `OtpService` is always the submitted email,
 * never `$user->email`: the lookup that found the account is
 * case-insensitive in MySQL while the `otps` row was written with the
 * submitted string, and the original flows passed the submitted value. These
 * methods return a sentinel (`null`/`false`) for a bad code; the controller
 * keeps the single 422 it maps to.
 */
final class AdminAuthService
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    /**
     * Bootstrap the platform: create the superadmin, make the platform roles
     * exist and adopt the session.
     *
     * Role provisioning stays best-effort, wrapped exactly as before: a
     * seeder or assignment failure must not fail a bootstrap whose account row
     * is already written — the warning keeps the failure visible and the
     * response still issues the pair.
     *
     * `email_verified_at` is passed to `create()` exactly as the controller
     * did. It is not in the model's fillable list, so the model silently drops
     * it; this pass preserves that behaviour rather than "fixing" it.
     *
     * @param  array{name: string, email: string, phone?: string|null, password: string}  $data
     * @return array{user: User, pair: array<string, mixed>}
     */
    public function provision(array $data, Request $request): array
    {
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
            app(SpatiePermissionSeeder::class)->run();
            setPermissionsTeamId(null);
            $user->assignRole('Super Admin');
        } catch (\Throwable $e) {
            Log::warning('api.admin.setup_role_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        $pair = $this->tokens->issuePair($user, 'admin', $request);

        Log::info('api.admin.onboarded', ['user_id' => $user->id]);

        return ['user' => $user, 'pair' => $pair];
    }

    /**
     * Consume the emailed login code and adopt the session: verify, stamp
     * `last_login_at`, issue the pair, log — in that order.
     *
     * @return array<string, mixed>|null the token pair, or null when the code
     *                                   is wrong, expired or already used.
     */
    public function completeLogin(User $user, string $email, string $code, Request $request): ?array
    {
        if (! OtpService::verify($email, $code, 'login')) {
            return null;
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $pair = $this->tokens->issuePair($user, 'admin', $request);

        Log::info('api.admin.login_success', ['user_id' => $user->id]);

        return $pair;
    }

    /**
     * Complete a password reset: consume the code, write the new password,
     * then revoke every access and refresh token on the admin audience so no
     * session survives the reset.
     *
     * @return bool false when the code is wrong, expired or already used —
     *              the controller's 422.
     */
    public function resetPassword(User $user, string $email, string $code, string $password): bool
    {
        if (! OtpService::verify($email, $code, 'password_reset')) {
            return false;
        }

        $user->forceFill(['password' => $password])->save();

        $this->tokens->revokeAllAccessTokens($user, 'admin');
        $this->refreshTokens->revokeAllFor($user, 'admin');

        Log::info('api.admin.password_reset', ['user_id' => $user->id]);

        return true;
    }
}
