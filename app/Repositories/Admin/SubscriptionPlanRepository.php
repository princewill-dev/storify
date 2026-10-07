<?php

namespace App\Repositories\Admin;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * WS-11 (admin console) — subscription-plan list and count queries.
 *
 * This layer builds the queries; it never opens a transaction and never calls
 * abort() — the transaction boundaries belong to SubscriptionPlanService and
 * the controller owns the HTTP status each refusal maps to.
 *
 * The subscription-count definitions (total, active, guarded) are shared
 * between the list query and the post-write count load, because `can_delete`
 * is derived from the guarded count: if the two definitions drifted, a plan
 * the list marks deletable could still fail the delete guard.
 */
final class SubscriptionPlanRepository
{
    /**
     * Statuses that make a plan undeletable: live or in-flight subscriptions.
     *
     * A pending subscription is mid-checkout — deleting its plan would break
     * the payment callback — and a suspended one is still a row the plan's
     * history depends on, so both block deletion alongside active rows. The
     * destroy guard and the list payload's `can_delete` must agree, so the set
     * is defined once, here, and both read it.
     */
    public const GUARDED_STATUSES = [
        Subscription::STATUS_ACTIVE,
        Subscription::STATUS_PENDING,
        Subscription::STATUS_SUSPENDED,
    ];

    /**
     * The plan console list: subscription counters, the `q`/status filters and
     * the ordering the list has always used.
     *
     * @param  array<string, mixed>  $filters  validated by ListSubscriptionPlansRequest
     */
    public function paginateForAdmin(array $filters): LengthAwarePaginator
    {
        $term = trim((string) ($filters['q'] ?? ''));

        return SubscriptionPlan::query()
            ->withCount($this->subscriptionCounts())
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($term) {
                $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            }))
            ->when(($filters['status'] ?? null) !== null, fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'))
            // Legacy ordered by sort_order only; the name tiebreak keeps the
            // list stable between pages.
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * Load the three subscription counters on a plan the write echoes back,
     * from the same definitions the list uses so `can_delete` cannot drift.
     */
    public function loadSubscriptionCounts(SubscriptionPlan $plan): SubscriptionPlan
    {
        return $plan->loadCount($this->subscriptionCounts());
    }

    /**
     * The count definitions shared by the list query and the write echoes.
     *
     * @return array<int|string, mixed>
     */
    private function subscriptionCounts(): array
    {
        return [
            'subscriptions',
            'subscriptions as active_subscriptions_count' => fn (Builder $query) => $query->where('status', Subscription::STATUS_ACTIVE),
            'subscriptions as guarded_subscriptions_count' => fn (Builder $query) => $query->whereIn('status', self::GUARDED_STATUSES),
        ];
    }
}
