<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Mail\StoreReactivated;
use App\Mail\StoreSuspended;
use App\Models\KycApplication;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StoreLifecycleController extends ApiController
{
    use ResolvesManagementContext;

    public function suspend(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        if ($store->status === Store::STATUS_DELETED) {
            return $this->error('This store has been deleted and can no longer be suspended.', 422);
        }

        if ($store->status === Store::STATUS_SUSPENDED) {
            return $this->error('This store is already suspended.', 422);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $reason = $data['reason'] ?? 'Suspended by store owner';

        $store->update(['status' => Store::STATUS_SUSPENDED]);

        // Deliberate change from legacy: the mail goes to the store owner, not
        // to whoever happened to click Suspend (a staff member could act, and
        // legacy addressed the mail to their own inbox instead of the owner's).
        $this->sendStatusMail($store, new StoreSuspended($store, $reason));

        Log::info('api.management.store_suspended', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'reason' => $reason,
        ]);

        return $this->ok(['store' => $this->lifecyclePayload($store->fresh())], 'Store suspended successfully.');
    }

    /**
     * Reactivate a suspended store.
     *
     * Legacy gated on the *acting* user's KYC application, so a staff member
     * with `stores settings` could never reactivate a store and the owner's
     * approved KYC was ignored. The gate and the mail both target the owner now.
     */
    public function activate(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        if ($store->status === Store::STATUS_DELETED) {
            return $this->error('This store has been deleted and can no longer be activated.', 422);
        }

        if ($store->status === Store::STATUS_ACTIVE) {
            return $this->error('This store is already active.', 422);
        }

        $owner = $this->owner($store);
        $kyc = $owner?->kycApplication;

        if (! $kyc || $kyc->status !== KycApplication::STATUS_APPROVED) {
            return $this->error('Complete KYC verification before activating this store.', 422);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $reason = $data['reason'] ?? 'Reactivated by store owner';

        $store->update(['status' => Store::STATUS_ACTIVE]);

        $this->sendStatusMail($store, new StoreReactivated($store, $reason));

        Log::info('api.management.store_activated', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'reason' => $reason,
        ]);

        return $this->ok(['store' => $this->lifecyclePayload($store->fresh())], 'Store activated successfully.');
    }

    /**
     * Soft delete: the row and all its data are retained, the store just
     * disappears from every list. Refused while money is still in flight —
     * orders must all be completed and transactions all confirmed.
     */
    public function destroy(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        if (Order::query()
            ->where('store_id', $store->id)
            ->where('status', '!=', OrderStatus::COMPLETED->value)
            ->exists()) {
            return $this->error('Cannot delete: store has incomplete orders.', 422);
        }

        if (Transaction::query()
            ->whereHas('order', fn ($query) => $query->where('store_id', $store->id))
            ->where('status', '!=', TransactionStatus::CONFIRMED->value)
            ->exists()) {
            return $this->error('Cannot delete: store has incomplete transactions.', 422);
        }

        $store->update(['status' => Store::STATUS_DELETED]);

        Log::info('store.deleted', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
        ]);

        return $this->ok([], "Store '{$store->name}' has been deleted.");
    }

    /**
     * The business owner rather than the store row's creator — a staff member
     * may have created the store, and the KYC gate and lifecycle mails are the
     * owner's business.
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

    /**
     * @return array<string, mixed>
     */
    private function lifecyclePayload(Store $store): array
    {
        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'status' => $store->status,
        ];
    }
}
