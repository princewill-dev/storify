<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Customer;
use App\Models\KycApplication;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * WS-34 — one shell endpoint for every nav badge, the avatar URL, and the
 * KYC / subscription status pills.
 *
 * Legacy's AppServiceProvider view composer did the same job on every page:
 * it counted Stores, Warehouses, Staff, Customers, pending Orders, open
 * Dispatches, pending Transactions and open POS sessions per user, cached 15
 * seconds, and read the KYC application and subscription off the user. Every
 * badge in the SPA reads this one response instead of each module hitting its
 * own count endpoint (WS-18's pending count and WS-26's `stats.open` remain
 * for their own screens; their definitions are mirrored here so the numbers
 * cannot disagree).
 *
 * Scoping: owners read business-wide counts, restricted staff read their
 * assigned stores (or accessible stores when they have no assignment), and a
 * count whose permission the user lacks comes back as zero rather than being
 * omitted — the nav only shows entries the same permission hides.
 *
 * Cache key is per user because scoping is per user; legacy's `sidebar.counts`
 * key was per user too.
 */
class ShellCountsController extends ApiController
{
    use ResolvesManagementContext;

    /** Legacy's view-composer TTL. */
    private const CACHE_TTL = 15;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $payload = Cache::remember(
            "shell.counts.{$user->id}",
            self::CACHE_TTL,
            fn () => $this->payload($request, $user),
        );

        return $this->ok($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, User $user): array
    {
        $storeIds = $this->storeIds($request);

        return [
            'generated_at' => now()->toISOString(),
            'counts' => [
                'stores' => $user->can('stores view')
                    ? $this->accessibleStores($request)->count()
                    : 0,
                'warehouses' => $user->can('warehouses view')
                    ? $user->accessibleWarehouses()->where('status', '!=', Warehouse::STATUS_DELETED)->count()
                    : 0,
                'staff' => $user->can('staff view')
                    ? User::query()
                        ->where('business_id', $user->business_id)
                        ->where('role', 'staff')
                        ->where('status', 'active')
                        ->count()
                    : 0,
                'customers' => $user->can('customers view')
                    ? Customer::query()
                        ->where('business_id', $user->business_id)
                        ->where('status', '!=', Customer::STATUS_DELETED)
                        ->when($user->isRestrictedStaff(), fn (Builder $q) => $q->whereHas(
                            'orders',
                            fn (Builder $orders) => $orders->whereIn('store_id', $storeIds)
                        ))
                        ->count()
                    : 0,
                'orders_pending' => $user->can('orders view')
                    ? Order::query()
                        ->where('business_id', $user->business_id)
                        ->whereIn('store_id', $storeIds)
                        ->where('status', OrderStatus::PENDING->value)
                        ->count()
                    : 0,
                // Legacy's badge definition, same as WS-26's `stats.open`.
                'dispatches_open' => $user->can('orders view')
                    ? OrderDelivery::query()
                        ->where('business_id', $user->business_id)
                        ->whereNotIn('status', ['delivered', 'failed', 'returned'])
                        ->whereHas('order', fn (Builder $order) => $order->whereIn('store_id', $storeIds))
                        ->count()
                    : 0,
                // WS-18's definition: every pending payment needs a human
                // decision, invoice-linked ones included (legacy's join hid
                // those).
                'transactions_pending' => $user->can('transactions view')
                    ? $this->transactionsPending($request, $user)
                    : 0,
                'pos_open_sessions' => $user->can('pos view_history')
                    ? PosSession::query()
                        ->where('business_id', $user->business_id)
                        ->where('status', PosSession::STATUS_OPEN)
                        ->whereIn('store_id', $storeIds)
                        ->count()
                    : 0,
                'pos_stores' => $user->can('pos view_history')
                    ? $this->accessibleStores($request)->where('pos_enabled', true)->count()
                    : 0,
            ],
            'kyc' => $this->kyc($user),
            'subscription' => $this->subscription($user),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                // The avatar the header renders; Gravatar fallback included by
                // User::photoUrl() so the shell never shows a broken image.
                'photo_url' => $user->photoUrl(),
            ],
        ];
    }

    private function transactionsPending(Request $request, User $user): int
    {
        // Same store rule as WS-18's list: an explicit staff assignment wins,
        // otherwise the accessible stores.
        $storeIds = $user->isStaff() && $user->assignedStores()->exists()
            ? $user->assignedStores()
                ->where('status', '!=', Store::STATUS_DELETED)
                ->pluck('stores.id')
                ->map(fn ($id) => (int) $id)
            : $this->storeIds($request);

        return Transaction::query()
            ->where('business_id', $user->business_id)
            ->where('status', TransactionStatus::PENDING->value)
            ->when($user->isStaff(), fn (Builder $q) => $q->where(
                fn (Builder $scope) => $scope
                    ->whereHas('order', fn (Builder $order) => $order->whereIn('store_id', $storeIds))
                    ->orWhereHas('invoice', fn (Builder $invoice) => $invoice->whereIn('store_id', $storeIds))
            ))
            ->count();
    }

    /**
     * The four states the legacy sidebar pill rendered. `required` covers both
     * "no application" and a draft, and the pill is owner-only (the API's KYC
     * routes refuse anyone else), so non-owners get null.
     *
     * @return array{status: string, has_application: bool, review_notes: string|null}|null
     */
    private function kyc(User $user): ?array
    {
        if (! $user->isBusinessOwner()) {
            return null;
        }

        $application = $user->kycApplication()->first();

        return [
            'status' => match ($application?->status) {
                KycApplication::STATUS_SUBMITTED => 'submitted',
                KycApplication::STATUS_APPROVED => 'approved',
                KycApplication::STATUS_REJECTED => 'rejected',
                default => 'required',
            },
            'has_application' => $application !== null,
            'review_notes' => $application?->status === KycApplication::STATUS_REJECTED
                ? $application->review_notes
                : null,
        ];
    }

    /**
     * Mirrors WS-09's GateController states so the sidebar pill and the
     * subscription screens can never disagree. Gated on `settings
     * subscription`, the same permission the nav entry carries.
     *
     * @return array{state: string, plan: string|null, expires_at: string|null, days_left: int|null}|null
     */
    private function subscription(User $user): ?array
    {
        if (! $user->can('settings subscription')) {
            return null;
        }

        $subscription = $user->business?->activeSubscription;
        $daysLeft = $user->daysLeftOnTrial();

        $state = match (true) {
            $subscription !== null => 'active',
            $user->isOnTrial() => ($daysLeft ?? 0) <= 2 ? 'trial_ending' : 'trial',
            $user->trialHasExpired() => 'trial_expired',
            (bool) $user->selected_plan_id => 'plan_selected_unpaid',
            default => 'no_plan',
        };

        return [
            'state' => $state,
            'plan' => $subscription?->subscriptionPlan?->name ?? $user->selectedPlan?->name,
            'expires_at' => $subscription?->expires_at?->toISOString() ?? $user->trial_ends_at?->toISOString(),
            'days_left' => $daysLeft,
        ];
    }

    /**
     * Accessible stores minus the soft-deleted ones — legacy filtered
     * `status != 'deleted'` on every sidebar read, and WS-06's leaked
     * warehouse defect is the same class of bug.
     */
    private function accessibleStores(Request $request)
    {
        return $this->user($request)
            ->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED);
    }

    /**
     * @return Collection<int, int>
     */
    private function storeIds(Request $request): Collection
    {
        // Qualified `stores.id`: for restricted staff accessibleStores() is the
        // assignment pivot join, whose own `id` column would make a bare `id`
        // ambiguous (SQLSTATE 1052).
        return $this->accessibleStores($request)
            ->pluck('stores.id')
            ->map(fn ($id) => (int) $id);
    }
}
