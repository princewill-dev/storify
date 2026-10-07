<?php

namespace App\Services\Management;

use App\Mail\StoreReactivated;
use App\Mail\StoreSuspended;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * WS-04 — the store suspend/activate workflows.
 *
 * Each transition writes the status, queues the owner's mail and logs it, in
 * that order, with no transaction: the status change is a single-row write,
 * and a failed (or absent) notification must never block or undo it, so the
 * mail is wrapped and logged instead of being allowed to bubble. Keeping the
 * mail outside any transaction also keeps it off the queue until the write
 * stands.
 *
 * The refusals (deleted/already-suspended/already-active, the owner's KYC
 * gate, the incomplete-orders/transactions delete guards) stay in the
 * controller: they are status codes and message strings, i.e. HTTP shape.
 */
final class StoreLifecycleService
{
    public function suspend(Store $store, string $reason, User $actor): void
    {
        $store->update(['status' => Store::STATUS_SUSPENDED]);

        // Deliberate change from legacy: the mail goes to the store owner, not
        // to whoever happened to click Suspend (a staff member could act, and
        // legacy addressed the mail to their own inbox instead of the owner's).
        $this->sendStatusMail($store, new StoreSuspended($store, $reason));

        Log::info('api.management.store_suspended', [
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'reason' => $reason,
        ]);
    }

    public function activate(Store $store, string $reason, User $actor): void
    {
        $store->update(['status' => Store::STATUS_ACTIVE]);

        $this->sendStatusMail($store, new StoreReactivated($store, $reason));

        Log::info('api.management.store_activated', [
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'reason' => $reason,
        ]);
    }

    /**
     * The business owner rather than the store row's creator — a staff member
     * may have created the store, and the lifecycle mails are the owner's
     * business. The same targeting decision backs the controller's KYC gate.
     */
    private function owner(Store $store): ?User
    {
        return $store->business?->owner ?? $store->user;
    }

    private function sendStatusMail(Store $store, StoreSuspended|StoreReactivated $mail): void
    {
        $owner = $this->owner($store);

        if (! $owner?->email) {
            return;
        }

        try {
            Mail::to($owner->email)->queue($mail);
        } catch (\Throwable $e) {
            // A mail failure must never roll back or block the status change.
            Log::error('store.status_mail_failed', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
