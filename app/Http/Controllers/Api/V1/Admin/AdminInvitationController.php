<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Http\Requests\Admin\AcceptAdminInvitationRequest;
use App\Http\Resources\Admin\AdminInvitationResource;
use App\Models\User;
use App\Repositories\Admin\AdminInvitationRepository;
use App\Services\Admin\AdminInvitationService;
use Illuminate\Http\JsonResponse;

/**
 * WS-10 (admin console) — the public half of the admin invitation flow.
 *
 * `GET/POST /api/v1/admin/invitations/{token}` already existed in the shared
 * `routes/api/v1/auth.php` against `Api\V1\Auth\InvitationController`, but it
 * collapsed every non-happy state into one 404 — the exact legacy defect the
 * audit flags (legacy flashed "invalid or has expired" vs "already been
 * accepted"). That controller and the shared route file are out of this
 * workstream's ownership, so the module route file re-registers the two URIs
 * on this controller: the route collection keys on method+URI, meaning this
 * later registration replaces the earlier one and `route:list` keeps exactly
 * one entry per URI (same pattern as ad08's user routes).
 *
 * The two routes are public — an invited admin has no session yet — so they
 * strip the admin group's `auth:sanctum` / `token.audience:admin` /
 * `team.context` middleware with `withoutMiddleware()`. The POST keeps the
 * shared `throttle:auth` limiter.
 *
 * State contract the SPA can rely on:
 * - unknown token            -> 404 "invalid or has expired"
 * - token, status != invited -> 200 GET / 409 POST with `already_accepted: true`
 * - pending token            -> 200 with the invitee's email (+ name on GET)
 *
 * Decision on the token lifetime (deliberate, flagged for the orchestrator):
 * legacy nulled `invitation_token` on acceptance, which is precisely why an
 * accepted link became indistinguishable from a forged one. This flow retains
 * the token — inert the moment `status` leaves `invited` — so a second click
 * can be told apart from a bad link. It is rotated by resend and dies with the
 * account on removal. If retention is ever considered a leak, the only safe
 * alternative is a separate accepted-token record, not a null.
 *
 * Layering: the controller keeps only the HTTP shape — status codes, message
 * strings and the envelope. Token resolution (with the platform-role scope
 * that makes a staff token 404) lives in `AdminInvitationRepository`, the
 * accept workflow and its transaction in `AdminInvitationService`, validation
 * in `AcceptAdminInvitationRequest`, and the preview's two shapes in
 * `AdminInvitationResource`. `isPending()` stays here: it is the branch that
 * picks 200-vs-409, and models are outside this workstream's ownership.
 */
class AdminInvitationController extends ApiController
{
    use BuildsAuthResponses;

    public function __construct(
        private readonly AdminInvitationRepository $invitations,
        private readonly AdminInvitationService $adminInvitations,
    ) {}

    /**
     * Preview an invitation. Unlike the collapsed 404 the SPA previously had
     * to interpret, this reports "already accepted" as a distinct, actionable
     * state so the screen can point the visitor at the login page.
     */
    public function show(string $token): JsonResponse
    {
        $admin = $this->invitations->findByToken($token);

        if (! $admin) {
            return $this->error('This invitation link is invalid or has expired.', 404);
        }

        if (! $this->isPending($admin)) {
            return $this->ok(
                AdminInvitationResource::make($admin)->alreadyAccepted()->resolve(),
                'This invitation has already been accepted. Please log in.',
            );
        }

        return $this->ok(AdminInvitationResource::make($admin)->resolve());
    }

    /**
     * Accept an invitation: name + password, activate the account, adopt the
     * session. Legacy logged the invitee straight into the admin dashboard;
     * here the same token pair the login flow issues comes back, so the SPA
     * lands them on the dashboard without a second sign-in.
     */
    public function accept(AcceptAdminInvitationRequest $request, string $token): JsonResponse
    {
        $admin = $this->invitations->findByToken($token);

        if (! $admin) {
            return $this->error('This invitation link is invalid or has expired.', 404);
        }

        if (! $this->isPending($admin)) {
            // 409: the link is real, the state is not. Body carries the same
            // `already_accepted` flag the GET uses so API clients share one
            // branch, and the message matches the legacy warning. Raw
            // response on purpose — `error()` would drop the two data keys.
            return response()->json([
                'message' => 'This invitation has already been accepted. Please log in.',
                'already_accepted' => true,
                'email' => $admin->email,
            ], 409);
        }

        ['user' => $admin, 'pair' => $pair] = $this->adminInvitations->accept($admin, $request->validated(), $request);

        return $this->ok([
            ...$pair,
            'user' => $this->userPayload($admin),
        ], 'Welcome to the admin team!');
    }

    /**
     * A usable invitation is one whose account is still waiting for setup.
     */
    private function isPending(User $admin): bool
    {
        return $admin->status === 'invited';
    }
}
