<?php

namespace App\Services\Admin;

use App\Mail\AdminInvitationSpaMail;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * WS-10 (admin console) — platform admin account workflows.
 *
 * Every mutation pairs its writes with the audit row inside one transaction
 * ("a rejected audit row must take the change down with it"), then queues the
 * invitation mail after the commit: a broken mailer must not roll back an
 * account the admin just created, and the caller is told the truth about the
 * mail. The controller keeps the HTTP shape — status codes, message strings,
 * which refusal maps to which 422 — and the repository owns the queries. The
 * self/superadmin guards run in the controller before these methods.
 */
final class AdminAccountService
{
    /**
     * Invite a platform admin. Mirrors the legacy `store()`: `role = admin`,
     * `status = invited`, a 64-char token, a random unusable password and
     * `force_password_change`, the Spatie role assigned under team `null`, and
     * the invitation mailed. The audit row is new (legacy only wrote a log
     * line), as is the `emailed` flag — a mail-queue failure must not lose the
     * account, but the admin deserves to know the invite did not go out.
     *
     * @return array{admin: User, emailed: bool}
     */
    public function invite(string $email, Role $role, User $actor): array
    {
        $admin = DB::transaction(function () use ($email, $role, $actor) {
            $admin = User::create([
                'name' => '',
                'email' => $email,
                'role' => User::ROLE_ADMIN,
                'business_id' => null,
                'status' => 'invited',
                'invitation_token' => Str::random(64),
                'invited_at' => now(),
                // Unusable random password; the invitee sets their own on the
                // accept screen. `password` is a hashed cast, so the plain
                // string is hashed once by the model.
                'password' => Str::random(32),
                'force_password_change' => true,
            ]);

            setPermissionsTeamId(null);
            $admin->assignRole($role);

            ActivityRecorder::record(
                action: 'admin.invited',
                description: "Invited {$admin->email} as {$role->name}",
                subject: $admin,
                new: ['email' => $admin->email, 'role' => $role->name, 'status' => 'invited'],
                metadata: ['invited_by' => $actor->id, 'role' => $role->name],
                actor: $actor,
            );

            return $admin;
        });

        return [
            'admin' => $admin,
            'emailed' => $this->queueInvitation($admin, 'admin.invitation.mail_failed'),
        ];
    }

    /**
     * Resend an invitation: rotate the token, refresh `invited_at`, re-queue
     * the mail. An already-accepted admin never reaches this method — the
     * controller answers that with `changed: false` and a warning before any
     * mutation.
     *
     * @return bool whether the mail was queued
     */
    public function resendInvitation(User $admin, User $actor): bool
    {
        DB::transaction(function () use ($admin, $actor) {
            $admin->update([
                'invitation_token' => Str::random(64),
                'invited_at' => now(),
            ]);

            ActivityRecorder::record(
                action: 'admin.invitation_resent',
                description: "Resent the platform admin invitation to {$admin->email}",
                subject: $admin,
                new: ['invited_at' => $admin->invited_at?->toISOString()],
                metadata: ['resent_by' => $actor->id],
                actor: $actor,
            );
        });

        return $this->queueInvitation($admin, 'admin.invitation.resend_mail_failed');
    }

    /**
     * Change an admin's platform role. `syncRoles` (not `assignRole`) because
     * a platform admin holds exactly one assignable role. The previous role
     * names are read before the transaction so the audit row records the real
     * state it replaced.
     */
    public function changeRole(User $admin, Role $role, User $actor): void
    {
        $previousRoles = $admin->roles->pluck('name')->values()->all();

        DB::transaction(function () use ($admin, $role, $actor, $previousRoles) {
            setPermissionsTeamId(null);
            $admin->syncRoles([$role]);

            ActivityRecorder::record(
                action: 'admin.role_changed',
                description: "Changed {$admin->email}'s platform role to {$role->name}",
                subject: $admin,
                old: ['roles' => $previousRoles],
                new: ['roles' => [$role->name]],
                metadata: ['changed_by' => $actor->id, 'role' => $role->name],
                actor: $actor,
            );
        });
    }

    /**
     * Remove a platform admin — legacy's hard delete, with its two guards held
     * by the controller. Roles and access tokens are detached first: the pivot
     * rows have no FK cascade onto users in this schema and a live Sanctum
     * token would outlive the account otherwise. The audit row is written
     * first, inside the same transaction, while the row (and its roles) still
     * exist.
     */
    public function remove(User $admin, User $actor): void
    {
        $email = $admin->email;

        DB::transaction(function () use ($admin, $actor, $email) {
            ActivityRecorder::record(
                action: 'admin.removed',
                description: "Removed platform admin {$email}",
                subject: $admin,
                old: ['email' => $email, 'roles' => $admin->roles->pluck('name')->values()->all()],
                metadata: ['removed_by' => $actor->id],
                actor: $actor,
            );

            setPermissionsTeamId(null);
            $admin->roles()->detach();
            $admin->tokens()->delete();
            $admin->delete();
        });
    }

    /**
     * Queue the invitation mail, swallowing (and logging) delivery failures so
     * a broken mailer cannot roll back an account the admin just created.
     */
    private function queueInvitation(User $admin, string $failureLog): bool
    {
        try {
            Mail::to($admin->email)->queue(new AdminInvitationSpaMail($admin));

            return true;
        } catch (\Throwable $e) {
            Log::error($failureLog, [
                'user_id' => $admin->id,
                'email' => $admin->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
