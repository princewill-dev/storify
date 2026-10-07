<?php

namespace App\Repositories\Auth;

use App\Models\User;

/**
 * Account lookups for the management auth surface.
 *
 * Every entry point that starts unauthenticated — sign-in, OTP verification,
 * OTP resend, forgot-password and reset-password — resolves its account
 * through `findByEmail`: five call sites that previously repeated the same
 * inline query. There is deliberately no role scope here: the login branch
 * itself decides who may enter (staff sign in directly, owners/admins
 * continue to an OTP challenge, every other role is refused with the same
 * "not a business account" 403), so scoping the query would only change which
 * accounts receive that message.
 *
 * Queries only: no transactions, no abort(), no HTTP statuses. The controller
 * owns the 422/403 mapping and the workflows own their writes.
 */
final class ManagementAuthRepository
{
    /**
     * Resolve an account by email for sign-in and password recovery.
     *
     * Matched verbatim, exactly as the controller's inline lookups did; null
     * means "no account", which the callers answer with their generic refusal
     * rather than a 404, so this cannot be used to probe which emails exist.
     */
    public function findByEmail(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->first();
    }
}
