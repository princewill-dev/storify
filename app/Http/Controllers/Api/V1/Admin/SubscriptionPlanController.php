<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\Admin\Concerns\InteractsWithPlans;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * WS-11 (admin console) — subscription plan CRUD ("Subscription Fee" screen).
 *
 * Legacy `/office/subscription-plans` listed plans by `sort_order` and offered
 * create/edit modals plus a guarded delete. Two legacy defects are fixed
 * rather than cloned:
 *
 *  - the modals rendered `is_trial` / `trial_days` but the validator dropped
 *    them, so the fields never persisted; they are accepted, required-for-trial
 *    and stored here;
 *  - a plan's subscriptions FK cascades, so a plan delete erases its
 *    subscription rows. The guard covers pending and suspended rows too (a
 *    pending subscription is mid-checkout — deleting its plan would break the
 *    payment callback), and the list payload carries `can_delete` so the SPA
 *    disables Delete up front instead of round-tripping the error.
 *
 * Money: the column stores decimal naira (legacy schema); writes accept
 * `amount_kobo` (preferred, the house unit) or a decimal `amount`, and both
 * read shapes are returned.
 */
class SubscriptionPlanController extends ApiController
{
    use EnsuresPlatformAdmin;
    use InteractsWithPlans;

    /**
     * Statuses that make a plan undeletable: live or in-flight subscriptions.
     */
    private const GUARDED_STATUSES = [
        Subscription::STATUS_ACTIVE,
        Subscription::STATUS_PENDING,
        Subscription::STATUS_SUSPENDED,
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $term = trim((string) ($filters['q'] ?? ''));

        $plans = SubscriptionPlan::query()
            ->withCount([
                'subscriptions',
                'subscriptions as active_subscriptions_count' => fn (Builder $query) => $query->where('status', Subscription::STATUS_ACTIVE),
                'subscriptions as guarded_subscriptions_count' => fn (Builder $query) => $query->whereIn('status', self::GUARDED_STATUSES),
            ])
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

        return $this->ok(
            $plans->getCollection()->map(fn (SubscriptionPlan $plan) => $this->payload($plan))->values()->all(),
            null,
            200,
            $this->paginationMeta($plans),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $this->validated($request);

        $plan = DB::transaction(function () use ($request, $data) {
            $attributes = $this->attributes($data);

            // "Setting default un-defaults all others" (legacy).
            if ($attributes['is_default']) {
                SubscriptionPlan::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $plan = SubscriptionPlan::create($attributes);

            ActivityRecorder::record(
                action: 'subscription_plan_created',
                description: "Subscription plan '{$plan->name}' created",
                subject: $plan,
                new: $this->auditValues($plan),
                actor: $request->user(),
            );

            return $plan;
        });

        return $this->ok(['plan' => $this->payload($this->withCounts($plan))], 'Subscription plan created.', 201);
    }

    public function update(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $this->validated($request);
        $old = $this->auditValues($plan);

        DB::transaction(function () use ($request, $plan, $data, $old) {
            $attributes = $this->attributes($data);

            if ($attributes['is_default']) {
                SubscriptionPlan::query()
                    ->where('is_default', true)
                    ->whereKeyNot($plan->getKey())
                    ->update(['is_default' => false]);
            }

            $plan->update($attributes);

            ActivityRecorder::record(
                action: 'subscription_plan_updated',
                description: "Subscription plan '{$plan->name}' updated",
                subject: $plan,
                old: $old,
                new: $this->auditValues($plan->fresh()),
                actor: $request->user(),
            );
        });

        return $this->ok(['plan' => $this->payload($this->withCounts($plan->fresh()))], 'Subscription plan updated.');
    }

    public function destroy(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($plan->subscriptions()->whereIn('status', self::GUARDED_STATUSES)->exists()) {
            return $this->error('Cannot delete a plan with active subscriptions. Deactivate it instead.', 422);
        }

        $name = $plan->name;
        $values = $this->auditValues($plan);

        DB::transaction(function () use ($request, $plan, $name, $values) {
            $plan->delete();

            ActivityRecorder::record(
                action: 'subscription_plan_deleted',
                description: "Subscription plan '{$name}' deleted",
                old: $values,
                actor: $request->user(),
            );
        });

        return $this->ok([], "Plan \"{$name}\" deleted successfully.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount_kobo' => ['nullable', 'integer', 'min:0', 'max:9999999999', 'required_without:amount'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2', 'required_without:amount_kobo'],
            'currency' => ['required', 'string', 'size:3'],
            'interval' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'interval_count' => ['required', 'integer', 'min:1', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'is_trial' => ['sometimes', 'boolean'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365', Rule::requiredIf(fn () => $request->boolean('is_trial'))],
            'features' => ['nullable', 'array', 'max:30'],
            'features.*' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);

        // A trial-plan template can never be the platform default: every
        // checkout path excludes `is_trial` plans and the early-access
        // redemption action activates the *active default* plan, so a trial
        // default would silently break redemption.
        if ($request->boolean('is_trial') && $request->boolean('is_default')) {
            throw ValidationException::withMessages([
                'is_default' => 'A trial plan cannot be the default plan.',
            ]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $isDefault = (bool) ($data['is_default'] ?? false);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'amount' => $this->amountNaira($data),
            'currency' => strtoupper($data['currency']),
            'interval' => $data['interval'],
            'interval_count' => (int) $data['interval_count'],
            // A default plan that is inactive would break the active-default
            // consumers (early-access activation) — being default implies active.
            'is_active' => $isDefault || (bool) ($data['is_active'] ?? true),
            'is_default' => $isDefault,
            'is_trial' => (bool) ($data['is_trial'] ?? false),
            'trial_days' => ($data['is_trial'] ?? false) ? (int) $data['trial_days'] : null,
            'features' => $this->features($data['features'] ?? []),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * The legacy textarea was parsed one-feature-per-line; the API takes a
     * clean array, but trims and drops blanks so pasted values still work.
     *
     * @param  array<int, mixed>  $features
     * @return array<int, string>
     */
    private function features(array $features): array
    {
        return array_values(array_filter(
            array_map(fn ($feature) => trim((string) $feature), $features),
            fn (string $feature) => $feature !== '',
        ));
    }

    private function withCounts(SubscriptionPlan $plan): SubscriptionPlan
    {
        return $plan->loadCount([
            'subscriptions',
            'subscriptions as active_subscriptions_count' => fn (Builder $query) => $query->where('status', Subscription::STATUS_ACTIVE),
            'subscriptions as guarded_subscriptions_count' => fn (Builder $query) => $query->whereIn('status', self::GUARDED_STATUSES),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SubscriptionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'name' => $plan->name,
            'description' => $plan->description,
            'amount' => (float) $plan->amount,
            'amount_kobo' => $this->toKobo($plan->amount),
            'currency' => $plan->currency,
            'interval' => $plan->interval,
            'interval_count' => (int) $plan->interval_count,
            'interval_label' => $this->intervalLabel($plan->interval, (int) $plan->interval_count),
            'features' => array_values($plan->features ?? []),
            'is_active' => (bool) $plan->is_active,
            'is_default' => (bool) $plan->is_default,
            'is_trial' => (bool) $plan->is_trial,
            'trial_days' => $plan->trial_days !== null ? (int) $plan->trial_days : null,
            'sort_order' => (int) $plan->sort_order,
            'subscriptions_count' => (int) ($plan->subscriptions_count ?? 0),
            'active_subscriptions_count' => (int) ($plan->active_subscriptions_count ?? 0),
            'can_delete' => (int) ($plan->guarded_subscriptions_count ?? 0) === 0,
            'created_at' => $plan->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(SubscriptionPlan $plan): array
    {
        return [
            'name' => $plan->name,
            'amount' => (string) $plan->amount,
            'currency' => $plan->currency,
            'interval' => $plan->interval,
            'interval_count' => (int) $plan->interval_count,
            'is_active' => (bool) $plan->is_active,
            'is_default' => (bool) $plan->is_default,
            'is_trial' => (bool) $plan->is_trial,
            'trial_days' => $plan->trial_days !== null ? (int) $plan->trial_days : null,
            'sort_order' => (int) $plan->sort_order,
        ];
    }
}
