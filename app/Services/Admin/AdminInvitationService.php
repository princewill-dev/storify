<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Auth\ApiTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WS-10 (admin console) — the accept-invitation workflow.
 *
 * One transaction pairs the account mutation with its audit row (the same
 * "a rejected audit row must take the edit down with it" rule the moderation
 * services follow). The post-commit steps keep their original order and must
 * not move: the Spatie team context is cleared so the platform roles on the
 * fresh row resolve, the fresh row is what the token pair and the response
 * name, and the pair is issued before the success log.
 *
 * Deliberately does not null `invitation_token`: the token is inert the moment
 * `status` leaves `invited`, and retaining it is what lets a second click be
 * told apart from a forged link (see AdminInvitationController's header).
 */
final class AdminInvitationService
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    /**
     * Activate the invited platform account and adopt the session: the same
     * token pair the login flow issues comes back, so the SPA lands the
     * invitee on the admin dashboard without a second sign-in.
     *
     * @param  array{name: string, password: string}  $data
     * @return array{user: User, pair: array<string, mixed>}
     */
    public function accept(User $admin, array $data, Request $request): array
    {
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

        return ['user' => $admin, 'pair' => $pair];
    }
}
