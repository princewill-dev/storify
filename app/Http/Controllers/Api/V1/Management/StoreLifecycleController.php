<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\StoreLifecycleRequest;
use App\Http\Resources\Management\StoreLifecycleResource;
use App\Models\KycApplication;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Management\StoreLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WS-04 — store suspend, activate and soft delete.
 *
 * Layering: the HTTP shape (status codes, message strings, the envelope and
 * the order of the refusals) stays here; the shared reason rules live in
 * Management\StoreLifecycleRequest, the suspend/activate workflows (status
 * write, owner mail, log) in App\Services\Management\StoreLifecycleService,
 * and the response row shape in Management\StoreLifecycleResource.
 *
 * No repository: the only queries are `destroy`'s two refusal guards, one
 * exists() statement each, never reused and never composed on, so wrapping
 * either in a repository method would be indirection without benefit. Both
 * stay in the controller body, where their 422s and message strings live.
 * `destroy` itself is a single-row update with no notification, so it stays
 * here rather than moving into the service.
 */
class StoreLifecycleController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly StoreLifecycleService $lifecycle,
    ) {}

    public function suspend(StoreLifecycleRequest $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        if ($store->status === Store::STATUS_DELETED) {
            return $this->error('This store has been deleted and can no longer be suspended.', 422);
        }

        if ($store->status === Store::STATUS_SUSPENDED) {
            return $this->error('This store is already suspended.', 422);
        }

        $this->lifecycle->suspend(
            $store,
            $request->validated('reason') ?? 'Suspended by store owner',
            $this->user($request),
        );

        return $this->ok(
            ['store' => (new StoreLifecycleResource($store->fresh()))->resolve($request)],
            'Store suspended successfully.',
        );
    }

    /**
     * Reactivate a suspended store.
     *
     * Legacy gated on the *acting* user's KYC application, so a staff member
     * with `stores settings` could never reactivate a store and the owner's
     * approved KYC was ignored. The gate and the mail both target the owner
     * now (the mail targeting lives in StoreLifecycleService::owner()).
     */
    public function activate(StoreLifecycleRequest $request, Store $store): JsonResponse
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

        $this->lifecycle->activate(
            $store,
            $request->validated('reason') ?? 'Reactivated by store owner',
            $this->user($request),
        );

        return $this->ok(
            ['store' => (new StoreLifecycleResource($store->fresh()))->resolve($request)],
            'Store activated successfully.',
        );
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
     * may have created the store, and the KYC gate is the owner's business.
     */
    private function owner(Store $store): ?User
    {
        return $store->business?->owner ?? $store->user;
    }
}
