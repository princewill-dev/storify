<?php

namespace App\Repositories\Admin;

use App\Models\User;

/**
 * WS-10 (admin console) — invitation-token resolution for the two public
 * admin-invitation endpoints.
 *
 * The role scope is load-bearing. Both endpoints are public (an invitee has no
 * session yet), so the token itself is the credential: without the
 * PLATFORM_ROLES filter a tenant account's invitation_token — a staff invite,
 * say — would resolve here, leaking that account's email on the preview and
 * letting the POST activate it into a platform admin. Tests assert a staff
 * token 404s.
 *
 * No transaction and no abort() live here: the accept workflow's boundary is
 * in AdminInvitationService, and the controller maps "no row" to the 404 the
 * state contract promises.
 */
final class AdminInvitationRepository
{
    /**
     * The roles an invitation token may belong to. Mirrors
     * AdminController::PLATFORM_ROLES — the console that mints these tokens —
     * declared here so query code never imports a controller.
     */
    private const PLATFORM_ROLES = [User::ROLE_SUPERADMIN, User::ROLE_ADMIN];

    /**
     * Resolve an invitation token to a platform account, any status — the
     * caller decides whether "already accepted" or "invalid" applies. A blank
     * token short-circuits to null; otherwise the token is matched verbatim,
     * exactly as the controller's previous private lookup did.
     */
    public function findByToken(string $token): ?User
    {
        if (trim($token) === '') {
            return null;
        }

        return User::query()
            ->where('invitation_token', $token)
            ->whereIn('role', self::PLATFORM_ROLES)
            ->first();
    }
}
