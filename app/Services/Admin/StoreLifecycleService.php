<?php

namespace App\Services\Admin;

use App\Mail\AdminStoreCreated;
use App\Mail\StoreActivated;
use App\Mail\StoreReactivated;
use App\Mail\StoreSuspended;
use App\Models\Store;
use App\Models\User;
use App\Repositories\Admin\StoreModerationRepository;
use App\Services\ActivityRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * WS-6 (admin console) — store lifecycle workflows.
 *
 * Every mutation pairs its writes with the audit row inside one transaction
 * ("a rejected audit row must take the edit down with it"), then notifies
 * after the commit: a failed notification must never undo the mutation — the
 * action can be seen in the audit trail and the admin told the truth.
 *
 * The main-store bootstrap writes the platform settings row inside the create
 * transaction and busts the four cache keys the home API and the admin view
 * composers read immediately after commit; the storefront would otherwise keep
 * serving the old homepage.
 *
 * Guards that only refuse (deleted store, homepage store, incomplete orders/
 * transactions) run in the controller, which keeps the HTTP shape — status
 * codes, message strings, and which refusal maps to which 422. The controller
 * also memoises the main-store id per request; the bootstrap result is
 * returned so it can drop that memo before shaping the response.
 */
final class StoreLifecycleService
{
    public function __construct(
        private readonly StoreModerationRepository $stores,
    ) {}

    /**
     * Admin-provisioned store, with logo upload, slug normalisation, the
     * main-store bootstrap and both queued notifications legacy sent.
     *
     * The `ALLOW_MS_SETUP` multi-business guard that gates the call is read
     * through config in the controller (legacy read the raw env flag with no
     * config indirection; the config key lets tests and a cached config
     * override it without editing the environment).
     *
     * @param  array<string, mixed>  $data  the validated create payload
     * @return array{store: Store, bootstrapped_main_store: bool}
     */
    public function provision(array $data, ?UploadedFile $logo, User $actor): array
    {
        $logoPath = $logo?->store('stores/logos', 'public');

        $business = $this->stores->findBusinessOrFail((int) $data['business_id']);

        $bootstrappedMainStore = false;

        $store = DB::transaction(function () use ($data, $business, $logoPath, $actor, &$bootstrappedMainStore) {
            $store = $this->stores->createStore([
                'business_id' => $business->id,
                'user_id' => $business->user_id,
                'name' => $data['name'],
                'slug' => $this->stores->uniqueSlug($data['slug'] ?? null, $data['name']),
                'description' => $data['description'] ?? null,
                'logo_path' => $logoPath,
                'support_email' => $data['support_email'] ?? null,
                'support_phone' => $data['support_phone'] ?? null,
                'address' => $data['address'] ?? null,
                'instagram_url' => $data['instagram_url'] ?? null,
                'facebook_url' => $data['facebook_url'] ?? null,
                'twitter_url' => $data['twitter_url'] ?? null,
                'tiktok_url' => $data['tiktok_url'] ?? null,
                'ownership_type_id' => $data['ownership_type_id'] ?? null,
                'business_type_id' => $data['business_type_id'] ?? null,
                'status' => $data['status'],
            ]);

            ActivityRecorder::record(
                action: 'store_created',
                description: "Store '{$store->name}' created for '{$business->name}'",
                subject: $store,
                new: [
                    'name' => $store->name,
                    'store_id' => $store->store_id,
                    'slug' => $store->slug,
                    'status' => $store->status,
                    'business_id' => $store->business_id,
                ],
                metadata: ['method' => 'admin_provisioned'],
                actor: $actor,
                businessId: $store->business_id,
            );

            // Legacy's bootstrap: the first store a superadmin creates becomes
            // the homepage store (only when none is configured yet). The
            // settings write stays inside this transaction.
            if ($actor->role === User::ROLE_SUPERADMIN && $this->stores->mainStoreId() === null) {
                $this->stores->configureMainStore($store->id);

                $bootstrappedMainStore = true;

                ActivityRecorder::record(
                    action: 'main_store_configured',
                    description: "Store '{$store->name}' configured as the homepage store",
                    subject: $store,
                    new: ['main_store_id' => $store->id],
                    actor: $actor,
                    businessId: $store->business_id,
                );
            }

            return $store;
        });

        // Post-commit hooks, in the order the controller ran them.
        if ($bootstrappedMainStore) {
            $this->forgetMainStoreCaches();
        }

        $this->notifyAdminsOfNewStore($store);
        $this->notifyOwner(
            $store,
            fn (User $owner) => new StoreActivated($store),
            'api.admin.store_created_mail_failed',
        );

        Log::info('api.admin.store_created', [
            'actor_user_id' => $actor->id,
            'store_id' => $store->id,
            'public_id' => $store->store_id,
        ]);

        return ['store' => $store, 'bootstrapped_main_store' => $bootstrappedMainStore];
    }

    /**
     * The 16-field edit. Slug uniqueness retries, logo replacement deletes the
     * previous file, status is restricted to the editable set, and the main
     * (homepage) store keeps legacy's guard: it cannot be moved to
     * inactive/suspended — the other edits still apply and the caller is told
     * the status change was skipped.
     *
     * Returns whether that guard blocked the status change, which the
     * controller turns into the structured warning and the message.
     *
     * @param  array<string, mixed>  $data  the validated edit payload
     */
    public function update(Store $store, array $data, ?UploadedFile $logo, User $actor): bool
    {
        $previousStatus = $store->status;
        $blockedMainStoreStatus = false;

        $old = [
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'support_email' => $store->support_email,
            'support_phone' => $store->support_phone,
        ];

        DB::transaction(function () use ($store, $data, $logo, $actor, $old, &$blockedMainStoreStatus) {
            $attributes = [];

            foreach ([
                'description', 'support_email', 'support_phone', 'address',
                'instagram_url', 'facebook_url', 'twitter_url', 'tiktok_url',
                'ownership_type_id', 'business_type_id',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field];
                }
            }

            if (array_key_exists('name', $data)) {
                $attributes['name'] = $data['name'];
            }

            if (array_key_exists('slug', $data) || array_key_exists('name', $data)) {
                $attributes['slug'] = $this->stores->uniqueSlug(
                    $data['slug'] ?? null,
                    $data['name'] ?? $store->name,
                    $store->id,
                );
            }

            // Re-resolve the owner from the chosen business. Legacy unset the
            // business_id and therefore never relinked it; the link is written
            // here so the store keeps showing under its business.
            if (array_key_exists('business_id', $data)) {
                $business = $this->stores->findBusiness((int) $data['business_id']);

                if ($business !== null) {
                    $attributes['business_id'] = $business->id;
                    $attributes['user_id'] = $business->user_id;
                }
            }

            if (array_key_exists('status', $data)) {
                $mainStoreId = $this->stores->mainStoreId();
                $isMainStore = $mainStoreId !== null && (int) $store->id === $mainStoreId;

                if ($isMainStore && in_array($data['status'], ['inactive', 'suspended'], true)) {
                    // Legacy blocked the change silently and flashed a warning;
                    // the warning is now structured in the response.
                    $blockedMainStoreStatus = true;

                    Log::warning('api.admin.store_status_change_blocked_main_store', [
                        'actor_user_id' => $actor->id,
                        'store_id' => $store->id,
                        'attempted_status' => $data['status'],
                        'main_store_id' => $mainStoreId,
                    ]);
                } else {
                    $attributes['status'] = $data['status'];
                }
            }

            if ($logo !== null) {
                if ($store->logo_path) {
                    try {
                        Storage::disk('public')->delete($store->logo_path);
                    } catch (\Throwable $e) {
                        Log::warning('api.admin.store_logo_delete_failed', [
                            'store_id' => $store->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $attributes['logo_path'] = $logo->store('stores/logos', 'public');
            }

            if ($attributes !== []) {
                $this->stores->updateStore($store, $attributes);
            }

            ActivityRecorder::record(
                action: 'store_updated',
                description: "Store '{$store->name}' updated",
                subject: $store,
                old: $old,
                new: [
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'status' => $store->status,
                    'support_email' => $store->support_email,
                    'support_phone' => $store->support_phone,
                ],
                metadata: $blockedMainStoreStatus
                    ? ['blocked_status_change' => 'main_store']
                    : [],
                actor: $actor,
                businessId: $store->business_id,
            );
        });

        // A status change into a suspended-like state notifies the owner;
        // legacy sent this mail without a reason, so the owner could not tell
        // why. The generated reason fixes that.
        if ($previousStatus !== $store->status && in_array($store->status, ['inactive', 'suspended'], true)) {
            $reason = "Your store was set to {$store->status} by the platform team.";

            $this->notifyOwner(
                $store,
                fn (User $owner) => new StoreSuspended($store, $reason),
                'api.admin.store_status_mail_failed',
            );
        }

        Log::info('api.admin.store_updated', ['actor_user_id' => $actor->id, 'store_id' => $store->id]);

        return $blockedMainStoreStatus;
    }

    /**
     * A delete refused by the main-store guard is itself audited (legacy
     * logged the outcome at each guard); the controller has already decided
     * the 422 response.
     */
    public function recordDeleteBlockedByMainStore(Store $store, User $actor): void
    {
        $this->recordDeleteBlocked(
            $store,
            'main_store',
            "Deletion of '{$store->name}' blocked: it is the homepage store",
            $actor,
        );
    }

    /**
     * A delete refused because an order is not `completed` is audited too.
     */
    public function recordDeleteBlockedByIncompleteOrders(Store $store, User $actor): void
    {
        $this->recordDeleteBlocked(
            $store,
            'incomplete_orders',
            "Deletion of '{$store->name}' blocked: incomplete orders",
            $actor,
        );
    }

    /**
     * A delete refused because a transaction is not `confirmed` is audited too.
     */
    public function recordDeleteBlockedByIncompleteTransactions(Store $store, User $actor): void
    {
        $this->recordDeleteBlocked(
            $store,
            'incomplete_transactions',
            "Deletion of '{$store->name}' blocked: incomplete transactions",
            $actor,
        );
    }

    /**
     * A suspend refused because the store is the homepage store is audited
     * with the reason the admin supplied.
     */
    public function recordSuspendBlockedByMainStore(Store $store, string $reason, User $actor): void
    {
        ActivityRecorder::record(
            action: 'store_suspend_blocked',
            description: "Suspension of '{$store->name}' blocked: it is the homepage store",
            subject: $store,
            metadata: ['guard' => 'main_store', 'reason' => $reason],
            actor: $actor,
            businessId: $store->business_id,
        );
    }

    /**
     * Suspend with the mandatory reason legacy required (the request class
     * enforces max 2000) and the owner email legacy queued. The main store
     * cannot be suspended — the controller refuses that before this runs.
     */
    public function suspend(Store $store, string $reason, User $actor): void
    {
        $previousStatus = $store->status;

        DB::transaction(function () use ($store, $reason, $previousStatus, $actor) {
            $this->stores->updateStore($store, ['status' => Store::STATUS_SUSPENDED]);

            ActivityRecorder::record(
                action: 'store_suspended',
                description: "Store '{$store->name}' suspended",
                subject: $store,
                old: ['status' => $previousStatus],
                new: ['status' => Store::STATUS_SUSPENDED],
                metadata: ['reason' => $reason],
                actor: $actor,
                businessId: $store->business_id,
            );
        });

        $this->notifyOwner(
            $store,
            fn (User $owner) => new StoreSuspended($store, $reason),
            'api.admin.store_suspended_mail_failed',
        );

        Log::info('api.admin.store_suspended', [
            'actor_user_id' => $actor->id,
            'store_id' => $store->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Activate, with the same mandatory-reason contract. Legacy guarded exact
     * suspend, not activate — so activating the main store stays allowed (the
     * controller only refuses the deleted status).
     */
    public function activate(Store $store, string $reason, User $actor): void
    {
        $previousStatus = $store->status;

        DB::transaction(function () use ($store, $reason, $previousStatus, $actor) {
            $this->stores->updateStore($store, ['status' => Store::STATUS_ACTIVE]);

            ActivityRecorder::record(
                action: 'store_activated',
                description: "Store '{$store->name}' activated",
                subject: $store,
                old: ['status' => $previousStatus],
                new: ['status' => Store::STATUS_ACTIVE],
                metadata: ['reason' => $reason],
                actor: $actor,
                businessId: $store->business_id,
            );
        });

        $this->notifyOwner(
            $store,
            fn (User $owner) => new StoreReactivated($store, $reason),
            'api.admin.store_activated_mail_failed',
        );

        Log::info('api.admin.store_activated', [
            'actor_user_id' => $actor->id,
            'store_id' => $store->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Soft delete with legacy's semantics: the row survives as
     * `status = 'deleted'` so the audit trail and historical orders keep
     * resolving. The three guards run in the controller before this is called.
     */
    public function delete(Store $store, User $actor): void
    {
        $previousStatus = $store->status;

        DB::transaction(function () use ($store, $previousStatus, $actor) {
            $this->stores->updateStore($store, ['status' => Store::STATUS_DELETED]);

            ActivityRecorder::record(
                action: 'store_deleted',
                description: "Store '{$store->name}' deleted",
                subject: $store,
                old: ['status' => $previousStatus],
                new: ['status' => Store::STATUS_DELETED],
                actor: $actor,
                businessId: $store->business_id,
            );
        });

        Log::info('api.admin.store_deleted', ['actor_user_id' => $actor->id, 'store_id' => $store->id]);
    }

    /**
     * @param  array<string, mixed>  $metadata  carries the guard key
     */
    private function recordDeleteBlocked(Store $store, string $guard, string $description, User $actor): void
    {
        ActivityRecorder::record(
            action: 'store_delete_blocked',
            description: $description,
            subject: $store,
            metadata: ['guard' => $guard],
            actor: $actor,
            businessId: $store->business_id,
        );
    }

    /**
     * The homepage store is cached at four keys by the home API and the admin
     * view composers; the bootstrap write must bust them or the storefront
     * keeps serving the old homepage.
     */
    private function forgetMainStoreCaches(): void
    {
        Cache::forget('admin_main_store');
        Cache::forget('home_main_store');
        Cache::forget('home_api_company');
        Cache::forget('company_settings');
    }

    /**
     * Legacy queued AdminStoreCreated to every superadmin, falling back to the
     * configured from-address when none exist.
     */
    private function notifyAdminsOfNewStore(Store $store): void
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
            Mail::to($recipients->all())->queue(new AdminStoreCreated($store));
        } catch (\Throwable $e) {
            Log::error('api.admin.store_created_admin_mail_failed', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A failed notification must never undo the mutation — the action can be
     * seen in the audit trail and the admin told the truth.
     */
    private function notifyOwner(Store $store, callable $mailable, string $failureLog): void
    {
        $owner = $store->user;

        if ($owner === null || empty($owner->email)) {
            return;
        }

        try {
            Mail::to($owner->email)->queue($mailable($owner));
        } catch (\Throwable $e) {
            Log::error($failureLog, [
                'store_id' => $store->id,
                'user_id' => $store->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
