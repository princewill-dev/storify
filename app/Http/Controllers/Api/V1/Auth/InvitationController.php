<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InvitationController extends ApiController
{
    use BuildsAuthResponses;

    public function __construct(private readonly ApiTokenService $tokens) {}

    public function showStaff(string $token): JsonResponse
    {
        $user = User::where('invitation_token', $token)
            ->where('role', 'staff')
            ->first();

        if (! $user || $user->status !== 'invited') {
            return $this->error('This invitation link is invalid or has expired.', 404);
        }

        return $this->ok([
            'email' => $user->email,
            'name' => $user->name,
            'business' => $user->business?->name,
        ]);
    }

    public function acceptStaff(Request $request, string $token): JsonResponse
    {
        $user = User::where('invitation_token', $token)
            ->where('role', 'staff')
            ->first();

        if (! $user || $user->status !== 'invited') {
            return $this->error('This invitation link is invalid or has expired.', 404);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update([
            'name' => $data['name'] ?? $user->name,
            'password' => $data['password'],
            'invitation_token' => null,
            'accepted_at' => now(),
            'status' => 'active',
            'force_password_change' => false,
        ]);

        setPermissionsTeamId($user->business_id);

        $pair = $this->tokens->issuePair($user, 'management', $request);

        Log::info('api.staff.invitation.accepted', ['user_id' => $user->id]);

        return $this->ok([
            ...$pair,
            'user' => $this->userPayload($user->fresh()),
        ], 'Welcome aboard! Your account has been activated.');
    }

    public function showAdmin(string $token): JsonResponse
    {
        $user = User::where('invitation_token', $token)
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPERADMIN])
            ->first();

        if (! $user || $user->status !== 'invited') {
            return $this->error('This invitation link is invalid or has expired.', 404);
        }

        return $this->ok(['email' => $user->email]);
    }

    public function acceptAdmin(Request $request, string $token): JsonResponse
    {
        $user = User::where('invitation_token', $token)
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPERADMIN])
            ->first();

        if (! $user || $user->status !== 'invited') {
            return $this->error('This invitation link is invalid or has expired.', 404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update([
            'name' => $data['name'],
            'password' => $data['password'],
            'invitation_token' => null,
            'accepted_at' => now(),
            'status' => 'active',
            'is_verified' => true,
            'email_verified_at' => now(),
            'force_password_change' => false,
        ]);

        $pair = $this->tokens->issuePair($user, 'admin', $request);

        Log::info('api.admin.invitation.accepted', ['user_id' => $user->id]);

        return $this->ok([
            ...$pair,
            'user' => $this->userPayload($user->fresh()),
        ], 'Welcome to the admin team!');
    }
}
