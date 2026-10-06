<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Auth\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 * accepted link became indistinguishable from a forged one. This controller
 * retains the token — inert the moment `status` leaves `invited` — so a second
 * click can be told apart from a bad link. It is rotated by resend and dies
 * with the account on removal. If retention is ever considered a leak, the
 * only safe alternative is a separate accepted-token record, not a null.
 */
class AdminInvitationController extends ApiController
{
    use BuildsAuthResponses;

    public function __construct(private readonly ApiTokenService $tokens) {}

    /**
     * Preview an invitation. Unlike the collapsed 404 the SPA previously had
     * to interpret, this reports "already accepted" as a distinct, actionable
     * state so the screen can point the visitor at the login page.
     */
    public function show(string $token): JsonResponse
    {
        $admin = $this->findByToken($token);

        if (! $admin) {
            return $this->error('This invitation link is invalid or has expired.', 404);
        }

        if (! $this->isPending($admin)) {
            return $this->ok([
                'already_accepted' => true,
                'email' => $admin->email,
                'accepted_at' => $admin->accepted_at?->toISOString(),
            ], 'This invitation has already been accepted. Please log in.');
        }

        return $this->ok([
            'already_accepted' => false,
            'email' => $admin->email,
            'name' => $admin->name,
            'invited_at' => $admin->invited_at?->toISOString(),
        ]);
    }

    /**
     * Accept an invitation: name + password, activate the account, adopt the
     * session. Legacy logged the invitee straight into the admin dashboard;
     * here the same token pair the login flow issues comes back, so the SPA
     * lands them on the dashboard without a second sign-in.
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $admin = $this->findByToken($token);

        if (! $admin) {
            return $this->error('This invitation link is invalid or has expired.', 404);
        }

        if (! $this->isPending($admin)) {
            // 409: the link is real, the state is not. Body carries the same
            // `already_accepted` flag the GET uses so API clients share one
            // branch, and the message matches the legacy warning.
            return response()->json([
                'message' => 'This invitation has already been accepted. Please log in.',
                'already_accepted' => true,
                'email' => $admin->email,
            ], 409);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        DB::transaction(function () use ($admin, $data) {
            // `forceFill` on purpose: `email_verified_at` is not in the
            // model's fillable list, so an `update([...])` would silently drop
            // it (the previous accept endpoint did exactly that).
            $admin->forceFill([
                'name' => $data['name'],
                'password' => $data['password'],
                'status' => 'active',
                'is_verified' => true,
                'email_verified_at' => now(),
                'accepted_at' => now(),
                'force_password_change' => false,
            ])->save();

            ActivityRecorder::record(
                action: 'admin.invitation_accepted',
                description: "{$admin->email} accepted the platform admin invitation",
                subject: $admin,
                old: ['status' => 'invited'],
                new: ['status' => 'active'],
                actor: $admin,
            );
        });

        setPermissionsTeamId(null);

        $admin = $admin->fresh();
        $pair = $this->tokens->issuePair($admin, 'admin', $request);

        Log::info('api.admin.invitation.accepted', [
            'user_id' => $admin->id,
            'email' => $admin->email,
        ]);

        return $this->ok([
            ...$pair,
            'user' => $this->userPayload($admin),
        ], 'Welcome to the admin team!');
    }

    /**
     * Resolve an invitation token to a platform account, any status — the
     * caller decides whether "already accepted" or "invalid" applies.
     */
    private function findByToken(string $token): ?User
    {
        if (trim($token) === '') {
            return null;
        }

        return User::query()
            ->where('invitation_token', $token)
            ->whereIn('role', AdminController::PLATFORM_ROLES)
            ->first();
    }

    /**
     * A usable invitation is one whose account is still waiting for setup.
     */
    private function isPending(User $admin): bool
    {
        return $admin->status === 'invited';
    }
}
