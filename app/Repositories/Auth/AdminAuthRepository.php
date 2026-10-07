<?php

namespace App\Repositories\Auth;

use App\Models\User;

/**
 * Platform-admin account lookups for the admin auth surface.
 *
 * The role scope is load-bearing. Every public admin auth endpoint resolves
 * its account through `findPlatformAccountByEmail`, and without the platform
 * role filter a tenant account — a staff or business-owner email — could take
 * the OTP route into the admin console. It is the same scope
 * `AdminInvitationRepository` applies to invitation tokens and
 * `AdminAccountRepository` applies to the directory.
 *
 * Queries only: no transactions, no abort(), no HTTP statuses. The controller
 * owns the 422/403 mapping and the workflows own their writes.
 */
final class AdminAuthRepository
{
    /**
     * The platform roles allowed through this surface. Mirrors
     * `AdminAccountRepository::PLATFORM_ROLES` (the console's canonical list);
     * declared here so auth query code never imports another workstream's
     * repository or a controller.
     */
    private const PLATFORM_ROLES = [User::ROLE_SUPERADMIN, User::ROLE_ADMIN];

    /**
     * Resolve a platform account by email for sign-in and password reset.
     *
     * The email is matched verbatim, exactly as the controller's inline
     * lookup did; null means "no platform account", which the callers answer
     * with their generic refusal rather than a 404, so this cannot be used to
     * probe which emails exist.
     */
    public function findPlatformAccountByEmail(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->whereIn('role', self::PLATFORM_ROLES)
            ->first();
    }

    /**
     * Has the platform been claimed? A superadmin row is the one fact both
     * answers ride: `setup-status` reports the inverse, and `setup` refuses
     * with 409 while one exists. Shared so the two can never drift — a
     * mismatch would either offer a second bootstrap or lock the first one
     * out.
     */
    public function superAdminExists(): bool
    {
        return User::query()
            ->where('role', User::ROLE_SUPERADMIN)
            ->exists();
    }
}
