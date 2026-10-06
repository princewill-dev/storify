<?php

namespace App\Services\Admin;

use App\Mail\BusinessReactivated;
use App\Mail\BusinessSuspended;
use App\Mail\UserPasswordResetMail;
use App\Models\Impersonation;
use App\Models\User;
use App\Repositories\Admin\UserModerationRepository;
use App\Services\ActivityRecorder;
use App\Services\Auth\ApiTokenService;
use App\Services\KycApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * WS-8 (admin console) — user moderation workflows.
 *
 * Every write pairs its mutation with the audit row inside one transaction
 * ("a rejected audit row must take the edit down with it"), then notifies
 * after the commit: a failed notification must never undo the mutation — the
 * action is in the audit trail and the admin is told the truth about the
 * mail. The controller keeps the HTTP shape — status codes, message strings,
 * which refusal maps to which 422 — and the repository owns the queries.
 */
final class UserModerationService
{
    /**
     * Legacy submitted this hidden when the admin clicked Activate — there was
     * never a reason form. Kept verbatim so `BusinessReactivated` and the KYC
     * reviewer note read the same as they did in the legacy console.
     */
    public const DEFAULT_ACTIVATION_REASON = 'Reactivated by admin';

    public function __construct(
        private readonly UserModerationRepository $users,
        private readonly ApiTokenService $tokens,
        private readonly KycApprovalService $kycApproval,
    ) {}

    /**
     * Edit name/email/phone with the audit row recording old and new values.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data, ?User $actor): void
    {
        $old = $user->only(['name', 'email', 'phone']);

        DB::transaction(function () use ($user, $data, $old, $actor) {
            $user->update($data);

            ActivityRecorder::record(
                action: 'user_updated',
                description: "Updated user {$user->name}",
                subject: $user,
                old: ['name' => $old['name'], 'email' => $old['email'], 'phone' => $old['phone']],
                new: ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null],
                actor: $actor,
            );
        });
    }

    /**
     * Suspend with the audit row and the notification email. Returns whether
     * the email was queued, so the console can report the truth.
     */
    public function suspend(User $user, string $reason, ?User $actor): bool
    {
        $previousStatus = $user->status;

        DB::transaction(function () use ($user, $reason, $previousStatus, $actor) {
            $user->update(['status' => 'suspended']);

            ActivityRecorder::record(
                action: 'user_suspended',
                description: "Suspended {$user->name}. Reason: {$reason}",
                subject: $user,
                old: ['status' => $previousStatus],
                new: ['status' => 'suspended', 'reason' => $reason],
                actor: $actor,
            );
        });

        $notified = $this->notifyUser(
            $user,
            fn (User $recipient) => new BusinessSuspended($recipient, $reason),
            'api.admin.user_suspended_mail_failed',
        );

        Log::info('api.admin.user_suspended', ['user_id' => $user->id, 'reason' => $reason]);

        return $notified;
    }

    /**
     * Activate a suspended user: legacy default reason (no form), KYC
     * auto-approval through the shared KycApprovalService, reactivation email
     * and audit row. `deleted` users go through restore — activating them here
     * would make one door out of a delete that has guards.
     *
     * @return array{kyc_approved: bool, notified: bool}
     */
    public function activate(User $user, ?string $reason, ?User $actor): array
    {
        $reason ??= self::DEFAULT_ACTIVATION_REASON;
        $previousStatus = $user->status;

        $kycApproved = DB::transaction(function () use ($user, $reason, $previousStatus, $actor) {
            $user->update(['status' => 'active']);

            ActivityRecorder::record(
                action: 'user_activated',
                description: "Activated {$user->name}. Reason: {$reason}",
                subject: $user,
                old: ['status' => $previousStatus],
                new: ['status' => 'active', 'reason' => $reason],
                actor: $actor,
            );

            // Legacy auto-approved a pending application on activation; the
            // service only touches a `submitted` row and writes the reviewer
            // note (legacy's columns for it were dropped by the model).
            return $this->kycApproval->autoApproveOpenApplication(
                $user,
                $actor,
                'Auto-approved during user activation: '.$reason,
            ) !== null;
        });

        $notified = $this->notifyUser(
            $user,
            fn (User $recipient) => new BusinessReactivated($recipient, $reason),
            'api.admin.user_activated_mail_failed',
        );

        Log::info('api.admin.user_activated', ['user_id' => $user->id, 'reason' => $reason]);

        return ['kyc_approved' => $kycApproved, 'notified' => $notified];
    }

    public function verify(User $user, ?User $actor): void
    {
        $previouslyVerified = (bool) $user->is_verified;

        DB::transaction(function () use ($user, $actor, $previouslyVerified) {
            $user->forceFill(['is_verified' => true, 'email_verified_at' => now()])->save();

            ActivityRecorder::record(
                action: 'user_verified',
                description: "Verified {$user->name}",
                subject: $user,
                old: ['is_verified' => $previouslyVerified],
                new: ['is_verified' => true],
                actor: $actor,
            );
        });
    }

    public function unverify(User $user, ?User $actor): void
    {
        $previouslyVerified = (bool) $user->is_verified;

        DB::transaction(function () use ($user, $actor, $previouslyVerified) {
            $user->forceFill(['is_verified' => false, 'email_verified_at' => null])->save();

            ActivityRecorder::record(
                action: 'user_unverified',
                description: "Removed verification for {$user->name}",
                subject: $user,
                old: ['is_verified' => $previouslyVerified],
                new: ['is_verified' => false],
                actor: $actor,
            );
        });
    }

    /**
     * Support-driven password reset: a `XXXX-xxxx-NNNN` temporary password,
     * `force_password_change`, and the reset mail. When mail queueing fails the
     * temporary password is returned in the response so the admin can still
     * hand it over — legacy flashed it for exactly this reason.
     *
     * @return array{temporary_password: string, emailed: bool}
     */
    public function resetPassword(User $user, ?User $actor): array
    {
        $temporaryPassword = Str::upper(Str::random(4)).'-'.Str::lower(Str::random(4)).'-'.random_int(1000, 9999);

        $user->forceFill([
            'password' => $temporaryPassword,
            'force_password_change' => true,
        ])->save();

        $notified = $this->notifyUser(
            $user,
            fn (User $recipient) => new UserPasswordResetMail($recipient, $temporaryPassword),
            'api.admin.user_password_reset_mail_failed',
        );

        // The temp password never enters the audit row — ActivityRecorder
        // redacts any `password` key, and the description deliberately omits it.
        ActivityRecorder::record(
            action: 'user_password_reset',
            description: "Reset password for {$user->name}",
            subject: $user,
            metadata: ['emailed' => $notified],
            actor: $actor,
        );

        return ['temporary_password' => $temporaryPassword, 'emailed' => $notified];
    }

    /**
     * Guarded soft delete (legacy sets `status = deleted`; the row and its
     * history survive). The refuse paths run in the controller against the
     * repository's guards.
     */
    public function delete(User $user, ?User $actor): void
    {
        $previousStatus = $user->status;

        DB::transaction(function () use ($user, $previousStatus, $actor) {
            $user->update(['status' => 'deleted']);

            ActivityRecorder::record(
                action: 'user_deleted',
                description: "Deleted user {$user->name}",
                subject: $user,
                old: ['status' => $previousStatus],
                new: ['status' => 'deleted'],
                actor: $actor,
            );
        });
    }

    /**
     * Restore a `deleted` user to `active` — the other half of the soft delete,
     * without which delete is a one-way door.
     */
    public function restore(User $user, ?User $actor): void
    {
        DB::transaction(function () use ($user, $actor) {
            $user->update(['status' => 'active']);

            ActivityRecorder::record(
                action: 'user_restored',
                description: "Restored user {$user->name}",
                subject: $user,
                old: ['status' => 'deleted'],
                new: ['status' => 'active'],
                actor: $actor,
            );
        });
    }

    /**
     * The blocked suspension is an audit event of its own, even though nothing
     * mutates.
     */
    public function recordSuspendBlockedByMainStore(User $user, ?User $actor): void
    {
        ActivityRecorder::record(
            action: 'user_suspend_blocked',
            description: "Suspension of {$user->name} blocked: the user owns the platform main store",
            subject: $user,
            metadata: ['guard' => 'main_store'],
            actor: $actor,
        );
    }

    /**
     * The blocked delete is an audit event of its own, even though nothing
     * mutates.
     */
    public function recordDeleteBlockedByMainStore(User $user, ?User $actor): void
    {
        ActivityRecorder::record(
            action: 'user_delete_blocked',
            description: "Deletion of {$user->name} blocked: the user owns the platform main store",
            subject: $user,
            metadata: ['guard' => 'main_store'],
            actor: $actor,
        );
    }

    /**
     * "Login as user" — issues a management-audience token pair for the user,
     * links the impersonation row to the access token the pair just created,
     * and writes the start audit row. The order is the hand-off contract: the
     * token must exist before the row can name it.
     *
     * @return array{impersonation: Impersonation, pair: array<string, mixed>}
     */
    public function startImpersonation(User $user, User $admin, Request $request): array
    {
        $impersonation = Impersonation::create([
            'impersonator_id' => $admin->id,
            'impersonated_id' => $user->id,
            'started_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        $pair = $this->tokens->issuePair($user, 'management', $request, [
            'impersonated',
            Impersonation::ABILITY_PREFIX.$impersonation->id,
        ]);

        $impersonation->update([
            'access_token_id' => $this->users->latestAccessTokenId($user),
        ]);

        ActivityRecorder::record(
            action: 'user_impersonated',
            description: "{$admin->name} started impersonating {$user->name}",
            subject: $user,
            metadata: ['impersonation_id' => $impersonation->id, 'admin_id' => $admin->id],
            actor: $admin,
        );

        return ['impersonation' => $impersonation, 'pair' => $pair];
    }

    /**
     * Force-end an impersonation session started from the console.
     */
    public function stopImpersonation(Impersonation $impersonation, User $user, ?User $actor): void
    {
        DB::transaction(function () use ($impersonation, $user, $actor) {
            $impersonation->update(['ended_at' => now()]);

            // Revoke the impersonated access token so the handed-off session
            // cannot keep acting after the admin ends it.
            if ($impersonation->access_token_id) {
                $user->tokens()->whereKey($impersonation->access_token_id)->delete();
            }

            ActivityRecorder::record(
                action: 'user_impersonation_stopped',
                description: "Stopped impersonating {$user->name}",
                subject: $user,
                metadata: ['impersonation_id' => $impersonation->id],
                actor: $actor,
            );
        });
    }

    /**
     * A failed notification must never undo the mutation — the action is in
     * the audit trail and the admin is told the truth about the mail.
     */
    private function notifyUser(User $user, callable $mailable, string $failureLog): bool
    {
        if (empty($user->email)) {
            return false;
        }

        try {
            Mail::to($user->email)->queue($mailable($user));

            return true;
        } catch (\Throwable $e) {
            Log::error($failureLog, ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
