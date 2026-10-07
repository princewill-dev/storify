<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Http\Requests\Auth\AcceptAdminInvitationRequest;
use App\Http\Requests\Auth\AcceptStaffInvitationRequest;
use App\Http\Resources\Auth\AdminInvitationPreviewResource;
use App\Http\Resources\Auth\StaffInvitationPreviewResource;
use App\Repositories\Auth\InvitationRepository;
use App\Services\Auth\InvitationAcceptanceService;
use Illuminate\Http\JsonResponse;

/**
 * Public invitation endpoints for the management (staff) and admin apps.
 *
 * The invitee has no session yet, so the invitation token is the credential.
 * Token resolution is role-scoped in InvitationRepository — a staff token must
 * not resolve a platform account and the reverse — the accept workflow and its
 * ordering live in InvitationAcceptanceService, validation in the two Auth
 * FormRequests, and the preview shapes in the Auth preview resources. This
 * controller keeps the HTTP shape only: the envelope, the message strings and
 * the single 404 ("invalid or has expired") that both an unknown token and a
 * no-longer-invited account collapse into on these routes.
 *
 * The POSTs resolve the token after validation now (FormRequest parameter
 * resolution), so an invalid token plus a malformed payload answers 422
 * instead of 404; with a valid payload the 404 order is unchanged. That is the
 * accepted extraction consequence.
 */
class InvitationController extends ApiController
{
    use BuildsAuthResponses;

    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly InvitationAcceptanceService $acceptance,
    ) {}

    public function showStaff(string $token): JsonResponse
    {
        $user = $this->invitations->findStaffByToken($token);

        if (! $user || $user->status !== 'invited') {
            return $this->error('This invitation link is invalid or has expired.', 404);
        }

        return $this->ok(StaffInvitationPreviewResource::make($user)->resolve());
    }

    public function acceptStaff(AcceptStaffInvitationRequest $request, string $token): JsonResponse
    {
        $user = $this->invitations->findStaffByToken($token);

        if (! $user || $user->status !== 'invited') {
            return $this->error('This invitation link is invalid or has expired.', 404);
        }

        $pair = $this->acceptance->acceptStaff($user, $request->validated(), $request);

        return $this->ok([
            ...$pair,
            'user' => $this->userPayload($user->fresh()),
        ], 'Welcome aboard! Your account has been activated.');
    }

    public function showAdmin(string $token): JsonResponse
    {
        $user = $this->invitations->findAdminByToken($token);

        if (! $user || $user->status !== 'invited') {
            return $this->error('This invitation link is invalid or has expired.', 404);
        }

        return $this->ok(AdminInvitationPreviewResource::make($user)->resolve());
    }

    public function acceptAdmin(AcceptAdminInvitationRequest $request, string $token): JsonResponse
    {
        $user = $this->invitations->findAdminByToken($token);

        if (! $user || $user->status !== 'invited') {
            return $this->error('This invitation link is invalid or has expired.', 404);
        }

        $pair = $this->acceptance->acceptAdmin($user, $request->validated(), $request);

        return $this->ok([
            ...$pair,
            'user' => $this->userPayload($user->fresh()),
        ], 'Welcome to the admin team!');
    }
}
