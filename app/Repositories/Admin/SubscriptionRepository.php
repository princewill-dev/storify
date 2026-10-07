<?php

namespace App\Repositories\Admin;

use App\Models\Subscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * WS-11 (admin console) — subscription oversight list queries.
 *
 * This layer builds the queries and applies the eager loads; it never opens a
 * transaction and never calls abort() — the controller owns the HTTP status
 * each refusal maps to, and this endpoint never writes.
 */
final class SubscriptionRepository
{
    /**
     * The platform-wide subscription list: business and plan eager loads, the
     * `q` search over either name, the status pills and the newest-first
     * ordering the list has always used.
     *
     * @param  array<string, mixed>  $filters  validated by ListSubscriptionsRequest
     */
    public function paginateDirectory(array $filters, int $perPage): LengthAwarePaginator
    {
        $status = $filters['status'] ?? null;
        $term = trim((string) ($filters['q'] ?? ''));

        return Subscription::query()
            ->with([
                'business:id,name,business_code,status',
                'subscriptionPlan:id,name,plan_code,amount,currency,interval,interval_count,is_trial',
            ])
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($term) {
                $inner->whereHas('business', fn (Builder $business) => $business->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('subscriptionPlan', fn (Builder $plan) => $plan->where('name', 'like', "%{$term}%"));
            }))
            ->when($status !== null, fn (Builder $query) => $this->applyStatusFilter($query, $status))
            // Legacy ordered by `latest('starts_at')`; the id tiebreak keeps
            // same-day rows from shuffling between pages.
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Counts for the list meta. Trial rows are a facet of their own: they are
     * excluded from the five status buckets — as in the filter — so every pill
     * returns exactly the number of rows printed on it and the counts
     * partition `all`.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = Subscription::query()
            ->where(fn (Builder $inner) => $this->nonTrialConstraint($inner))
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        $trial = Subscription::query()->where(fn (Builder $inner) => $this->trialConstraint($inner))->count();

        return [
            'active' => $counts['active'] ?? 0,
            'pending' => $counts['pending'] ?? 0,
            'suspended' => $counts['suspended'] ?? 0,
            'expired' => $counts['expired'] ?? 0,
            'cancelled' => $counts['cancelled'] ?? 0,
            'trial' => $trial,
            'all' => (int) $counts->sum() + $trial,
        ];
    }

    private function applyStatusFilter(Builder $query, string $status): Builder
    {
        if ($status === 'trial') {
            return $query->where(fn (Builder $inner) => $this->trialConstraint($inner));
        }

        // A trial row belongs to the trial facet only; excluding it here keeps
        // a pill's rows equal to the count rendered on it.
        return $query->where('status', $status)
            ->where(fn (Builder $inner) => $this->nonTrialConstraint($inner));
    }

    /**
     * Trial-ness can be recorded in two places: a trial-plan template
     * (`is_trial`) or the metadata flag. Both are read here, as everywhere.
     */
    private function trialConstraint(Builder $query): Builder
    {
        return $query->whereHas('subscriptionPlan', fn (Builder $plan) => $plan->where('is_trial', true))
            ->orWhere('metadata->trial', true);
    }

    /**
     * The complement of `trialConstraint()`, written out rather than negated:
     * `NOT (plan.is_trial OR json_extract(metadata, '$.trial') = true)` is NULL
     * — and so drops the row — whenever `metadata` is null, while a bucket must
     * keep every non-trial row.
     */
    private function nonTrialConstraint(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('subscriptionPlan', fn (Builder $plan) => $plan->where('is_trial', true))
            ->where(function (Builder $inner) {
                $inner->whereNull('metadata->trial')
                    ->orWhereNot('metadata->trial', true);
            });
    }
}
