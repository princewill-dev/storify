<?php

namespace App\Services;

use App\Mail\KycApproved;
use App\Mail\KycRejected;
use App\Models\KycApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * WS-3 (admin console) — the single writer for KYC state transitions.
 *
 * Every path that moves an application out of `submitted` goes through here:
 * the admin review screen (approve / reject) and the business/user activation
 * flows in WS-4 / WS-8 (`autoApproveOpenApplication`). Legacy had three
 * writers, and the activation pair persisted `reviewed_at` (a column that
 * never existed) and `reviewer_notes` (not fillable) — auto-approved
 * applications kept nothing but status and reviewer (consolidated defect
 * §24.2). One writer, one shape: approval always sets `approved_at` and a
 * reviewer note.
 *
 * The legacy review controller also had no status guard, so a direct POST
 * could re-approve an approved application or flip a rejected one (§24.4).
 * `lockForReview()` re-reads the row under a lock and refuses anything that
 * is not `submitted`.
 *
 * Each public transition opens its own transaction; callers inside a wider
 * transaction (business/user activation) may simply wrap the call — Laravel
 * nests via savepoints.
 */
final class KycApprovalService
{
    /**
     * Approve a submitted application: activate the owner, persist the
     * reviewer metadata and queue the approval mail (non-fatal, as legacy).
     */
    public function approve(KycApplication $application, User $reviewer, ?string $reviewNotes = null): KycTransitionResult
    {
        return DB::transaction(function () use ($application, $reviewer, $reviewNotes) {
            $application = $this->lockForReview($application);

            $previous = $this->applyStatus($application, KycApplication::STATUS_APPROVED, 'active', $reviewer, $reviewNotes);

            $notified = $this->queueApprovedMail($application);

            $this->record($application, 'admin.business_kyc.approved', $reviewer, $previous, $notified);

            return new KycTransitionResult($application->fresh(), $notified);
        });
    }

    /**
     * Reject a submitted application with a required reason: the owner goes
     * back to `pending` and — unlike legacy, which only pretended — receives
     * an email carrying the reason.
     */
    public function reject(KycApplication $application, User $reviewer, string $reviewNotes): KycTransitionResult
    {
        return DB::transaction(function () use ($application, $reviewer, $reviewNotes) {
            $application = $this->lockForReview($application);

            $previous = $this->applyStatus($application, KycApplication::STATUS_REJECTED, 'pending', $reviewer, $reviewNotes);

            $notified = $this->queueRejectedMail($application);

            $this->record($application, 'admin.business_kyc.rejected', $reviewer, $previous, $notified);

            return new KycTransitionResult($application->fresh(), $notified);
        });
    }

    /**
     * Approve without a human review step — used by business/user activation
     * (WS-4 / WS-8). The note records who triggered the automatic approval
     * and why; the owner is told their verification passed (legacy approved
     * silently and the owner only ever saw the activation mail).
     */
    public function autoApprove(KycApplication $application, ?User $reviewer = null, ?string $reviewNotes = null): KycTransitionResult
    {
        return DB::transaction(function () use ($application, $reviewer, $reviewNotes) {
            $application = $this->lockForReview($application);

            $note = $reviewNotes ?? 'Auto-approved during business activation.';

            $previous = $this->applyStatus($application, KycApplication::STATUS_APPROVED, 'active', $reviewer, $note);

            $notified = $this->queueApprovedMail($application);

            $this->record($application, 'admin.business_kyc.auto_approved', $reviewer, $previous, $notified, [
                'auto_approved' => true,
            ]);

            return new KycTransitionResult($application->fresh(), $notified);
        });
    }

    /**
     * Approve the owner's newest application that is still awaiting review.
     *
     * Returns null when there is nothing open. Deliberately narrower than
     * legacy, which auto-approved any row that was "not approved" — a draft
     * (never submitted, possibly empty) or a rejected one (a decision the
     * owner has not corrected yet). Only `submitted` means "open".
     */
    public function autoApproveOpenApplication(User $owner, ?User $reviewer = null, ?string $reviewNotes = null): ?KycApplication
    {
        $application = KycApplication::query()
            ->where('user_id', $owner->id)
            ->where('status', KycApplication::STATUS_SUBMITTED)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->first();

        if ($application === null) {
            return null;
        }

        try {
            return $this->autoApprove($application, $reviewer, $reviewNotes)->application;
        } catch (ValidationException $e) {
            // A concurrent reviewer got there first. That is not an activation
            // failure: the application is no longer open either way, so there
            // is simply nothing to auto-approve.
            return null;
        }
    }

    /**
     * Re-read the application under a row lock and assert it is actionable.
     *
     * The guard is what closes legacy's direct-POST hole (§24.4) and the lock
     * stops two simultaneous reviews from both passing it.
     */
    private function lockForReview(KycApplication $application): KycApplication
    {
        $locked = KycApplication::query()
            ->whereKey($application->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->status !== KycApplication::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Only an application awaiting review can be actioned. This application is already '.$locked->status.'.',
            ]);
        }

        return $locked;
    }

    /**
     * Write the application transition and cascade the owner's status.
     *
     * Returns the previous status for the audit trail's old_values.
     */
    private function applyStatus(
        KycApplication $application,
        string $status,
        string $ownerStatus,
        ?User $reviewer,
        ?string $reviewNotes
    ): string {
        $previous = $application->status;

        $application->forceFill([
            'status' => $status,
            'review_notes' => $reviewNotes,
            'reviewed_by' => $reviewer?->id,
            'approved_at' => $status === KycApplication::STATUS_APPROVED ? now() : null,
            'rejected_at' => $status === KycApplication::STATUS_REJECTED ? now() : null,
        ])->save();

        // KYC belongs to the owner, so the cascade follows the application's
        // user. A missing user row must not abort the review (legacy would
        // have thrown a fatal here).
        $owner = $application->user;

        if ($owner) {
            $owner->forceFill(['status' => $ownerStatus])->save();
        } else {
            Log::warning('admin.business_kyc.owner_missing', [
                'application_id' => $application->id,
                'user_id' => $application->user_id,
            ]);
        }

        return $previous;
    }

    private function queueApprovedMail(KycApplication $application): bool
    {
        return $this->queueOwnerMail(
            $application,
            fn (User $owner) => new KycApproved($owner, $application),
            'admin.business_kyc.approved_mail',
        );
    }

    private function queueRejectedMail(KycApplication $application): bool
    {
        return $this->queueOwnerMail(
            $application,
            fn (User $owner) => new KycRejected($owner, $application),
            'admin.business_kyc.rejected_mail',
        );
    }

    /**
     * Queue a notification to the application's owner. Mail problems are
     * logged and reported back, never fatal — mirroring legacy's try/catch,
     * but this time the caller can tell the admin the truth about it.
     */
    private function queueOwnerMail(KycApplication $application, callable $mailable, string $logPrefix): bool
    {
        $owner = $application->user;

        if (! $owner || empty($owner->email)) {
            Log::warning($logPrefix.'_skipped', [
                'application_id' => $application->id,
                'user_id' => $application->user_id,
                'reason' => 'no_recipient',
            ]);

            return false;
        }

        try {
            Mail::to($owner->email)->queue($mailable($owner));

            Log::info($logPrefix.'_queued', [
                'application_id' => $application->id,
                'user_id' => $owner->id,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error($logPrefix.'_queue_failed', [
                'application_id' => $application->id,
                'user_id' => $owner->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(
        KycApplication $application,
        string $action,
        ?User $reviewer,
        string $previousStatus,
        bool $notified,
        array $extra = []
    ): void {
        ActivityRecorder::record(
            action: $action,
            description: 'KYC application #'.$application->id.' '.$application->status.' for user '.$application->user_id,
            subject: $application,
            old: ['status' => $previousStatus],
            new: [
                'status' => $application->status,
                'reviewed_by' => $application->reviewed_by,
                'approved_at' => $application->approved_at?->toIso8601String(),
                'rejected_at' => $application->rejected_at?->toIso8601String(),
                'review_notes' => $application->review_notes,
            ],
            metadata: ['owner_user_id' => $application->user_id, 'notified' => $notified] + $extra,
            actor: $reviewer,
        );
    }
}
