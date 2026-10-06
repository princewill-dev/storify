<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\Admin\Concerns\InteractsWithPlans;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WS-11 (admin console) — subscription oversight list.
 *
 * Legacy `/office/subscriptions` was a read-only, 15-a-page list with a status
 * filter and free-text search over business or plan name — no detail screen
 * and, per the audit verification, no cancel/suspend/extend action anywhere in
 * the platform for anyone. Parity means the same: this controller reads, it
 * never mutates a subscription. (`status = expired/suspended` rows only exist
 * if another process writes them; the list still renders them.)
 *
 * Improve on legacy: the `trial` value the legacy filter offered never existed
 * as a row status — trials live on `users.trial_ends_at`, and a plan flagged
 * `is_trial` is a template every checkout path excludes. Here `trial` filters
 * on the two places trial-ness can actually be recorded: a trial-plan template
 * or the metadata flag. Trial rows are a facet of their own: they are excluded
 * from the five status buckets — in the filter and in `status_counts` alike —
 * so every pill returns exactly the number of rows printed on it and the
 * counts partition `all`.
 */
class SubscriptionController extends ApiController
{
    use EnsuresPlatformAdmin;
    use InteractsWithPlans;

    private const STATUSES = ['active', 'pending', 'suspended', 'expired', 'cancelled', 'trial'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $filters['status'] ?? null;
        $term = trim((string) ($filters['q'] ?? ''));

        $subscriptions = Subscription::query()
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
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return $this->ok(
            $subscriptions->getCollection()->map(fn (Subscription $subscription) => $this->payload($subscription))->values()->all(),
            null,
            200,
            $this->paginationMeta($subscriptions) + ['status_counts' => $this->statusCounts()],
        );
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
     * @return array<string, int>
     */
    private function statusCounts(): array
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

    /**
     * @return array<string, mixed>
     */
    private function payload(Subscription $subscription): array
    {
        $plan = $subscription->subscriptionPlan;

        return [
            'id' => $subscription->id,
            'subscription_code' => $subscription->subscription_code,
            'status' => $subscription->status,
            'status_label' => ucfirst($subscription->status),
            'is_active' => $subscription->isActive(),
            'is_trial' => $this->isTrial($subscription),
            'starts_at' => $subscription->starts_at?->toISOString(),
            'expires_at' => $subscription->expires_at?->toISOString(),
            'cancelled_at' => $subscription->cancelled_at?->toISOString(),
            'days_remaining' => $subscription->expires_at && $subscription->expires_at->isFuture()
                ? max(0, (int) now()->diffInDays($subscription->expires_at))
                : null,
            'business' => $subscription->business ? [
                'id' => $subscription->business->id,
                'name' => $subscription->business->name,
                'business_code' => $subscription->business->business_code,
                'status' => $subscription->business->status,
            ] : null,
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'plan_code' => $plan->plan_code,
                'amount' => (float) $plan->amount,
                'amount_kobo' => $this->toKobo($plan->amount),
                'currency' => $plan->currency,
                'interval' => $plan->interval,
                'interval_count' => (int) $plan->interval_count,
                'interval_label' => $this->intervalLabel($plan->interval, (int) $plan->interval_count),
                'is_trial' => (bool) $plan->is_trial,
            ] : null,
            'started_with' => [
                'coupon_code' => data_get($subscription->metadata, 'coupon_code'),
                'early_pass_code' => data_get($subscription->metadata, 'early_pass_code'),
                'payment_skipped' => (bool) data_get($subscription->metadata, 'payment_skipped', false),
            ],
        ];
    }

    private function isTrial(Subscription $subscription): bool
    {
        return (bool) ($subscription->subscriptionPlan?->is_trial
            || data_get($subscription->metadata, 'trial', false));
    }
}
