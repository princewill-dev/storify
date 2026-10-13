<?php

namespace App\Services\Admin;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Support\Money\Naira;
use App\Support\Plans\EntitlementRegistry;
use Illuminate\Support\Facades\DB;

/**
 * WS-11 (admin console) — subscription-plan write workflows.
 *
 * Each mutation pairs its table write(s) with the audit row inside one
 * transaction (the recorder contract: a rejected audit row takes the mutation
 * down with it), keeping the exact order the controller used. The controller
 * keeps the HTTP shape — validation, the guarded-delete 422 refusal and the
 * response echo.
 *
 * "Setting default un-defaults all others" (legacy): a plan flagged default
 * unsets the flag on its siblings first, inside the same transaction, so at
 * most one default is ever visible. On edit the plan being saved is excluded
 * from that sweep.
 *
 * Money: the column stores decimal naira (legacy schema, kept by the new
 * stack — the management-side plan endpoints read the same column) while
 * writes accept `amount_kobo` (preferred, the house unit) or a decimal
 * `amount`. Both branches land on the column as an exact 2dp string:
 * `amount_kobo` through the integer→string converter byte-for-byte, and a
 * decimal `amount` through a decimal→kobo parse and back — the exact branch
 * covers everything `decimal:0,2` admits, and its float fallback matches the
 * old inline normalisation for the trailing-point/leading-point forms
 * (`12.`, `.5`) that the same validator accepts, so the stored value is
 * unchanged either way.
 */
final class SubscriptionPlanService
{
    /**
     * @param  array<string, mixed>  $data  the validated create payload
     */
    public function create(array $data, ?User $actor): SubscriptionPlan
    {
        return DB::transaction(function () use ($data, $actor) {
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
                actor: $actor,
            );

            return $plan;
        });
    }

    /**
     * @param  array<string, mixed>  $data  the validated edit payload
     */
    public function update(SubscriptionPlan $plan, array $data, ?User $actor): void
    {
        $old = $this->auditValues($plan);

        DB::transaction(function () use ($plan, $data, $old, $actor) {
            $attributes = $this->attributes($data, $plan);

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
                actor: $actor,
            );
        });
    }

    /**
     * The subscriptions guard lives in the controller (it maps to a 422); this
     * only runs once that guard has passed. The audit row names the plan that
     * is gone, so its name and old values are captured before the delete.
     *
     * No subject is passed to the recorder, exactly as before: the row is
     * deleted, and the platform actor's business is null either way.
     */
    public function delete(SubscriptionPlan $plan, ?User $actor): void
    {
        $name = $plan->name;
        $values = $this->auditValues($plan);

        DB::transaction(function () use ($plan, $name, $values, $actor) {
            $plan->delete();

            ActivityRecorder::record(
                action: 'subscription_plan_deleted',
                description: "Subscription plan '{$name}' deleted",
                old: $values,
                actor: $actor,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?SubscriptionPlan $existing = null): array
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
            'entitlements' => $this->entitlements($data, $existing),
            'tier' => array_key_exists('tier', $data)
                ? (int) $data['tier']
                : (int) ($existing->tier ?? 1),
        ];
    }

    /**
     * What the plan grants, narrowed to what the registry knows about.
     *
     * The narrowing is {@see EntitlementRegistry::normalize()}'s, not this
     * method's: unknown keys are dropped, limits are coerced to integers, a
     * blank limit becomes `null` — unlimited, not zero — and a toggle is read
     * with `FILTER_VALIDATE_BOOLEAN`. Writing the map raw would be one typo away
     * from a plan whose store limit is the string "many", and every reader of
     * that column downstream would have to defend against it separately.
     *
     * **Absent means unchanged**, which is a deliberate exception to this
     * request's otherwise full-replacement contract. The column is new, so every
     * client that predates it — an older admin console, a script, a test that
     * posts the fields it knows about — omits the key, and reading that as
     * "grant nothing" would empty out a plan's contents the first time anybody
     * renamed it. Present, and it replaces the map wholesale.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function entitlements(array $data, ?SubscriptionPlan $existing): ?array
    {
        if (! array_key_exists('entitlements', $data)) {
            return $existing?->entitlements;
        }

        return $data['entitlements'] === null ? null : EntitlementRegistry::normalize($data['entitlements']);
    }

    /**
     * Either write shape → the decimal-naira string the column stores.
     *
     * @param  array<string, mixed>  $data
     */
    private function amountNaira(array $data): string
    {
        if (array_key_exists('amount_kobo', $data) && $data['amount_kobo'] !== null) {
            return Naira::decimalFromKobo((int) $data['amount_kobo']);
        }

        return Naira::decimalFromKobo(Naira::koboFromDecimalOrFloat($data['amount']));
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
            // Audited because it is the field with the widest blast radius on
            // this form: an edit here changes what every business subscribed to
            // the plan is entitled to, immediately and silently, and the trail
            // is the only place that change is attributable afterwards.
            'entitlements' => $plan->entitlements,
            'tier' => (int) $plan->tier,
        ];
    }
}
