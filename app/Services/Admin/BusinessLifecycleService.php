<?php

namespace App\Services\Admin;

use App\Mail\AdminBusinessCreated;
use App\Mail\BusinessReactivated;
use App\Mail\BusinessSuspended;
use App\Models\Business;
use App\Models\User;
use App\Repositories\Admin\BusinessRepository;
use App\Services\ActivityRecorder;
use App\Services\KycApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * WS-4 (admin console) — business lifecycle workflows.
 *
 * Every tenant mutation pairs its writes with the audit row inside one
 * transaction ("a rejected audit row must take the edit down with it"), then
 * notifies after the commit: a failed notification must never undo the
 * mutation — the action can be seen in the audit trail and the admin told
 * the truth. Guards that only refuse (main store, incomplete orders/
 * transactions, deleted business) run before these methods; the controller
 * keeps the HTTP shape — status codes, message strings, and which refusal
 * maps to which 422.
 */
final class BusinessLifecycleService
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly KycApprovalService $kycApproval,
    ) {}

    /**
     * Legacy's `ALLOW_MS_SETUP` flag: on a single-business deployment the
     * platform must not spin up competing businesses once the superadmin
     * account exists. Legacy's check compared every user's email against the
     * superadmin list — which matched the superadmins themselves — so it
     * reduced to "a superadmin exists"; that intent is kept.
     */
    public function provisioningBlocked(): bool
    {
        if (! $this->businesses->superadminExists()) {
            return false;
        }

        return ! $this->multiBusinessSetupAllowed();
    }

    /**
     * Admin-provisioned business + owner account.
     *
     * Legacy's create path built a user with no password at all
     * (`User::create($data)`, and `users.password` is NOT NULL), so it could
     * only ever 500 in production. Here the owner gets a one-time temporary
     * password, returned to the caller exactly once; the staff invitation
     * flow's accept endpoint is staff-only, so it is deliberately not reused
     * here.
     *
     * @param  array<string, mixed>  $data
     * @return array{business: Business, temporary_password: string}
     */
    public function provision(array $data, User $actor): array
    {
        $temporaryPassword = Str::password(16, symbols: false);
        $status = $data['status'] ?? 'active';

        /** @var array{0: User, 1: Business} $created */
        $created = DB::transaction(function () use ($data, $temporaryPassword, $status, $actor) {
            $owner = $this->businesses->createOwner([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => User::ROLE_BUSINESS_OWNER,
                // Business.status is canonical; the owner is created with the
                // same status deliberately (activating the business is the
                // path back to active for both).
                'status' => $status,
                'password' => $temporaryPassword,
                'force_password_change' => true,
                'is_verified' => false,
            ]);

            $business = $this->businesses->createBusiness([
                'user_id' => $owner->id,
                'name' => $data['name'],
                'slug' => $this->businesses->uniqueSlug($data['name']),
                'status' => $status,
                'business_type_id' => $data['business_type_id'] ?? null,
                'ownership_type_id' => $data['ownership_type_id'] ?? null,
            ]);

            $this->businesses->linkOwnerToBusiness($owner, $business);

            ActivityRecorder::record(
                action: 'business_created',
                description: "Business '{$business->name}' provisioned by platform admin",
                subject: $business,
                new: [
                    'name' => $business->name,
                    'business_code' => $business->business_code,
                    'status' => $business->status,
                    'owner_id' => $owner->id,
                    'owner_email' => $owner->email,
                ],
                metadata: ['method' => 'admin_provisioned'],
                actor: $actor,
                businessId: $business->id,
            );

            return [$owner, $business];
        });

        [$owner, $business] = $created;

        $this->notifyAdminsOfNewBusiness($owner);

        Log::info('api.admin.business_created', [
            'actor_user_id' => $actor->id,
            'business_id' => $business->id,
            'owner_id' => $owner->id,
        ]);

        return ['business' => $business, 'temporary_password' => $temporaryPassword];
    }

    /**
     * Owner/business details from the list or the console. The caller has
     * already refused a deleted business and a missing owner account.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Business $business, User $owner, array $data, User $actor): void
    {
        $old = [
            'name' => $business->name,
            'status' => $business->status,
            'owner_name' => $owner->name,
            'owner_email' => $owner->email,
            'owner_phone' => $owner->phone,
        ];

        DB::transaction(function () use ($business, $owner, $data, $old, $actor) {
            $attributes = [];

            if (array_key_exists('name', $data)) {
                $attributes['name'] = $data['name'];
                // Slug re-normalised from the name, as legacy did (it normalised
                // on every save, with or without a slug in the payload).
                $attributes['slug'] = $this->businesses->uniqueSlug($data['name'], $business->id);
            }

            foreach (['business_type_id', 'ownership_type_id'] as $typeColumn) {
                if (array_key_exists($typeColumn, $data)) {
                    $attributes[$typeColumn] = $data[$typeColumn];
                }
            }

            if (array_key_exists('status', $data)) {
                $attributes['status'] = $data['status'];
            }

            if ($attributes !== []) {
                $this->businesses->updateBusiness($business, $attributes);
            }

            $ownerAttributes = [];

            if (array_key_exists('owner_name', $data)) {
                $ownerAttributes['name'] = $data['owner_name'];
            }

            if (array_key_exists('email', $data)) {
                $ownerAttributes['email'] = $data['email'];
            }

            if (array_key_exists('phone', $data)) {
                $ownerAttributes['phone'] = $data['phone'];
            }

            // Business.status is canonical; the owner follows deliberately.
            if (array_key_exists('status', $data)) {
                $ownerAttributes['status'] = $data['status'];
            }

            if ($ownerAttributes !== []) {
                $this->businesses->updateOwner($owner, $ownerAttributes);
            }

            // A rejected audit row must take the edit down with it.
            ActivityRecorder::record(
                action: 'business_updated',
                description: "Business '{$business->name}' updated",
                subject: $business,
                old: $old,
                new: [
                    'name' => $business->name,
                    'status' => $business->status,
                    'owner_name' => $owner->name,
                    'owner_email' => $owner->email,
                    'owner_phone' => $owner->phone,
                ],
                actor: $actor,
                businessId: $business->id,
            );
        });
    }

    /**
     * A delete refused by the main-store guard is itself audited (legacy
     * logged the outcome at each guard); the controller has already decided
     * the 422 response.
     */
    public function recordDeleteBlockedByMainStore(Business $business, User $actor): void
    {
        ActivityRecorder::record(
            action: 'business_delete_blocked',
            description: "Deletion of '{$business->name}' blocked: it owns the platform main store",
            subject: $business,
            metadata: ['guard' => 'main_store'],
            actor: $actor,
            businessId: $business->id,
        );
    }

    /**
     * Soft delete with legacy's semantics: the row survives (`status` moves
     * to `deleted` on the business and cascades to the owner) so the audit
     * trail and historical orders keep resolving. The three guards run in the
     * controller before this is called.
     */
    public function delete(Business $business, User $actor): void
    {
        $previousStatus = $business->status;

        DB::transaction(function () use ($business, $previousStatus, $actor) {
            $this->businesses->updateBusiness($business, ['status' => 'deleted']);

            // Canonical status cascades to the owner account so a deleted
            // business cannot still sign in (legacy wrote only the user half).
            $owner = $business->owner;

            if ($owner !== null) {
                $this->businesses->updateOwner($owner, ['status' => 'deleted']);
            }

            ActivityRecorder::record(
                action: 'business_deleted',
                description: "Business '{$business->name}' deleted",
                subject: $business,
                old: ['status' => $previousStatus],
                new: ['status' => 'deleted'],
                actor: $actor,
                businessId: $business->id,
            );
        });
    }

    /**
     * Suspend the business and its owner, then email the owner. Legacy never
     * guarded activate — only suspend and delete — so the asymmetry is kept
     * (the main-store and deleted checks run in the controller).
     */
    public function suspend(Business $business, string $reason, User $actor): void
    {
        $previousStatus = $business->status;

        DB::transaction(function () use ($business, $reason, $previousStatus, $actor) {
            $this->businesses->updateBusiness($business, ['status' => 'suspended']);

            $owner = $business->owner;

            if ($owner !== null) {
                $this->businesses->updateOwner($owner, ['status' => 'suspended']);
            }

            ActivityRecorder::record(
                action: 'business_suspended',
                description: "Business '{$business->name}' suspended",
                subject: $business,
                old: ['status' => $previousStatus],
                new: ['status' => 'suspended'],
                metadata: ['reason' => $reason],
                actor: $actor,
                businessId: $business->id,
            );
        });

        $this->notifyOwner(
            $business,
            fn (User $owner) => new BusinessSuspended($owner, $reason),
            'api.admin.business_suspended_mail_failed',
        );

        Log::info('api.admin.business_suspended', ['business_id' => $business->id, 'reason' => $reason]);
    }

    /**
     * Activate the business, cascade the owner active and auto-approve an open
     * KYC submission (legacy's single approval path, gone from the previous
     * API). Legacy's auto-approval persisted only status + reviewer because
     * `reviewed_at` was not a column and `reviewer_notes` was not fillable;
     * the shared KycApprovalService writes the note, `approved_at` and the
     * review audit row properly.
     *
     * Returns whether a KYC application was approved, which the controller
     * turns into the success message.
     */
    public function activate(Business $business, string $reason, User $actor): bool
    {
        $owner = $business->owner;
        $previousStatus = $business->status;

        $kycApproved = DB::transaction(function () use ($business, $owner, $reason, $previousStatus, $actor) {
            $this->businesses->updateBusiness($business, ['status' => 'active']);

            if ($owner !== null) {
                $this->businesses->updateOwner($owner, ['status' => 'active']);
            }

            ActivityRecorder::record(
                action: 'business_activated',
                description: "Business '{$business->name}' activated",
                subject: $business,
                old: ['status' => $previousStatus],
                new: ['status' => 'active'],
                metadata: ['reason' => $reason],
                actor: $actor,
                businessId: $business->id,
            );

            if ($owner === null) {
                return false;
            }

            $application = $this->kycApproval->autoApproveOpenApplication(
                $owner,
                $actor,
                'Auto-approved during business activation: '.$reason,
            );

            return $application !== null;
        });

        $this->notifyOwner(
            $business,
            fn (User $owner) => new BusinessReactivated($owner, $reason),
            'api.admin.business_activated_mail_failed',
        );

        Log::info('api.admin.business_activated', ['business_id' => $business->id, 'reason' => $reason]);

        return $kycApproved;
    }

    /**
     * Legacy's verify moved off the owner's user page onto the business
     * console. Tenant-scoped mutation, like the lifecycle transitions above:
     * the owner write and its audit row stand or fall together.
     */
    public function verifyOwner(Business $business, User $owner, User $actor): void
    {
        $wasVerified = (bool) $owner->is_verified;

        DB::transaction(function () use ($business, $owner, $wasVerified, $actor) {
            $this->businesses->markOwnerEmailVerified($owner);

            ActivityRecorder::record(
                action: 'business_owner_verified',
                description: "Owner of '{$business->name}' marked email-verified",
                subject: $business,
                old: ['owner_verified' => $wasVerified],
                new: ['owner_verified' => true],
                metadata: ['owner_user_id' => $owner->id],
                actor: $actor,
                businessId: $business->id,
            );
        });
    }

    /**
     * Legacy read the raw env flag with no config indirection; the config key
     * lets tests and a cached config override it without touching the flag.
     */
    private function multiBusinessSetupAllowed(): bool
    {
        $configured = config('app.allow_ms_setup');

        if ($configured !== null) {
            return (bool) $configured;
        }

        return (int) env('ALLOW_MS_SETUP', 0) === 1;
    }

    /**
     * Legacy queued AdminBusinessCreated to every superadmin, falling back to
     * the configured from-address when none exist.
     */
    private function notifyAdminsOfNewBusiness(User $owner): void
    {
        $recipients = User::query()
            ->where('role', User::ROLE_SUPERADMIN)
            ->pluck('email')
            ->filter()
            ->values();

        if ($recipients->isEmpty() && config('mail.from.address')) {
            $recipients = collect([config('mail.from.address')]);
        }

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Mail::to($recipients->all())->queue(new AdminBusinessCreated($owner));
        } catch (\Throwable $e) {
            Log::error('api.admin.business_created_mail_failed', [
                'owner_id' => $owner->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A failed notification must never undo the mutation — the action can be
     * seen in the audit trail and the admin told the truth.
     */
    private function notifyOwner(Business $business, callable $mailable, string $failureLog): void
    {
        $owner = $business->owner;

        if ($owner === null || empty($owner->email)) {
            return;
        }

        try {
            Mail::to($owner->email)->queue($mailable($owner));
        } catch (\Throwable $e) {
            Log::error($failureLog, [
                'business_id' => $business->id,
                'user_id' => $owner->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
