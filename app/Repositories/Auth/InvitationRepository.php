<?php

namespace App\Repositories\Auth;

use App\Models\User;

/**
 * Invitation-token resolution for this controller's two public invitation
 * endpoints (management/staff and admin).
 *
 * Both endpoints are public — an invitee has no session yet — so the token
 * itself is the credential and the role scope is load-bearing: without it a
 * management (staff) token would resolve an admin account here, and the
 * reverse, leaking that account's email on the preview and letting the accept
 * activate an account the token was never minted for. The scope is what keeps
 * the two invitation audiences apart.
 *
 * Status is deliberately not filtered: the controller collapses "unknown
 * token" and "no longer invited" into its single 404 with one message, and
 * that contract is the caller's to keep. (The platform console's replacement
 * endpoint reports "already accepted" explicitly on its own routes.)
 *
 * No transaction and no abort() live here: the accept workflow's boundary is
 * in InvitationAcceptanceService and the controller maps "no row" to the 404.
 */
final class InvitationRepository
{
    /**
     * Resolve a management-side staff invitation token. The token is matched
     * verbatim, exactly as the controller's previous inline lookup did.
     */
    public function findStaffByToken(string $token): ?User
    {
        return User::query()
            ->where('invitation_token', $token)
            ->where('role', 'staff')
            ->first();
    }

    /**
     * Resolve a platform-admin invitation token — admins and superadmins, the
     * same roles platform invitations are minted for.
     */
    public function findAdminByToken(string $token): ?User
    {
        return User::query()
            ->where('invitation_token', $token)
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPERADMIN])
            ->first();
    }
}
