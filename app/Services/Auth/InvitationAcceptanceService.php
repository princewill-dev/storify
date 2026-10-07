<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The accept-invitation workflow for both invitation audiences: activate the
 * invited account and adopt the session by issuing the same token pair the
 * login flow issues.
 *
 * No DB::transaction here — deliberately: the original accept never opened
 * one (the activation is guarded by the single-use invitation token and the
 * controller's invited-status check), so adding a boundary would be a change,
 * not a rearrangement.
 *
 * The sequence is load-bearing and must not move: the row is updated first,
 * the Spatie team context follows (staff only), the token pair is issued
 * against the same instance, and the success log comes last — exactly the
 * order the controller had.
 */
final class InvitationAcceptanceService
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    /**
     * @param  array{name?: string, password: string}  $data
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}
     */
    public function acceptStaff(User $user, array $data, Request $request): array
    {
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

        return $pair;
    }

    /**
     * @param  array{name: string, password: string}  $data
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}
     */
    public function acceptAdmin(User $user, array $data, Request $request): array
    {
        // `update()` on purpose, not `forceFill()`: `email_verified_at` is not
        // in User::$fillable, so it is silently dropped — that is this
        // endpoint's current behaviour, kept because this refactor rearranges
        // layers rather than changing what the endpoint does. Fixing the drop
        // belongs to a behaviour change, not this move.
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

        return $pair;
    }
}
