<?php

namespace App\Support\Plans;

use App\Models\Business;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionGate;
use App\Support\Payments\GatewayGrant;

/**
 * How many stores a business is running, and whether it may start another.
 *
 * The one place `stores_limit` is *counted* — {@see Entitlements} says what the
 * plan allows, this says what is actually held against it, and the two are only
 * ever compared here. Both the create screen and the create endpoint ask this
 * class, so the form a business is offered cannot be one whose only outcome is a
 * refusal. That is the same contract {@see GatewayGrant}
 * holds for the payment providers screen, and it is here for the same reason:
 * two callers asking a question in their own words is how a rule ends up
 * enforced in one place and described wrongly in another.
 *
 * ## Counting is not `count()`
 *
 * `Store` has no `SoftDeletes`. Deleting a store is `status = 'deleted'` on a row
 * that stays in the table, so counting rows would keep charging a business for a
 * store it no longer has — an owner who removes a store and still cannot add
 * another has been told the limit is a lie. Everything that is not `deleted`
 * occupies a slot: `pending` and `suspended` included, because a suspended store
 * still exists on the business's account and the slot it holds is not free.
 *
 * ## Reaching the cap is inclusive
 *
 * {@see Entitlements::reached()} is `<= 0` remaining, not `=== 0`, which is what
 * makes a grandfathered business behave: one that moved to a smaller plan while
 * holding more stores than it allows keeps every store it has and may not add
 * another. Without that, a business could be pushed *under* the cap by the act
 * of hitting it, or — worse — a plan change would start deleting things.
 */
final class StoreQuota
{
    public const REFUSAL_CODE = 'plan_limit_reached';

    /** Where the SPA sends an owner who needs a bigger plan. */
    public const UPGRADE_URL = '/plans';

    /**
     * Stores held against the business's plan right now.
     */
    public static function used(int $businessId): int
    {
        return (int) Store::query()
            ->where('business_id', $businessId)
            ->where('status', '!=', Store::STATUS_DELETED)
            ->count();
    }

    /**
     * Whether no further store may be created.
     */
    public static function reached(Business $business): bool
    {
        return Entitlements::for($business)
            ->reached(EntitlementRegistry::STORES, self::used((int) $business->id));
    }

    /**
     * The refusal, in the shape the SPAs already handle, or null when a store
     * may be created.
     *
     * `{code, message, redirect}` is the same contract
     * {@see SubscriptionGate::refusal()} returns, deliberately:
     * a client that has learnt to read one refusal should not have to learn a
     * second format for the next reason the platform says no. The two codes are
     * distinct — `subscription_required` and `plan_limit_reached` — because the
     * sentences and the remedies differ, but the envelope does not.
     *
     * @return array{code: string, message: string, redirect: string}|null
     */
    public static function refusal(Business $business): ?array
    {
        $used = self::used((int) $business->id);
        $entitlements = Entitlements::for($business);

        if (! $entitlements->reached(EntitlementRegistry::STORES, $used)) {
            return null;
        }

        // No plan and no live trial. `EnsureManagementSubscription` already
        // keeps such a business off `/management/*`, so this is the belt to that
        // pair of braces — and it needs its own sentence, because "you have
        // reached 0 stores" is not an explanation of anything.
        if (! $entitlements->isResolved()) {
            return [
                'code' => self::REFUSAL_CODE,
                'message' => 'This business has no active plan, so no more stores can be created. '
                    .'Choose a plan to continue.',
                'redirect' => self::UPGRADE_URL,
            ];
        }

        $limit = $entitlements->limit(EntitlementRegistry::STORES);

        // Resolved, and still grants nothing. An admin can set a plan to zero
        // stores, and a trial that found no plan row at all falls back to the
        // registry defaults — so this is reachable, and it must not be described
        // as a missing plan, which would send the owner looking for a
        // subscription they already have.
        if ($limit === 0) {
            $holder = $entitlements->plan();

            return [
                'code' => self::REFUSAL_CODE,
                'message' => ($holder === null
                    ? 'Your current plan does not include any stores.'
                    : "The {$holder->name} plan does not include any stores.")
                    .' Choose a plan with stores to continue.',
                'redirect' => self::UPGRADE_URL,
            ];
        }

        $plan = $entitlements->plan();
        $holder = $plan === null ? 'Your current plan' : "Your {$plan->name} plan";
        $next = Entitlements::planRaising(EntitlementRegistry::STORES, $used);

        // Over the cap rather than at it: the business is holding more stores
        // than its plan allows, which is the grandfathered state a downgrade
        // leaves behind and not an error. Say so plainly, or the owner reads a
        // limit they have already exceeded as a bug — or as a warning that
        // something is about to be taken away, which is the one thing this rule
        // must never do.
        if ($limit !== null && $used > $limit) {
            return [
                'code' => self::REFUSAL_CODE,
                'message' => "{$holder} includes {$limit} stores and you have {$used}. "
                    .'Every one of them keeps working, but no new store can be created until you are '
                    .'back under the limit. '
                    .($next === null
                        ? 'Contact support to raise it.'
                        : "Upgrading to {$next->name} would allow more."),
                'redirect' => self::UPGRADE_URL,
            ];
        }

        return [
            'code' => self::REFUSAL_CODE,
            'message' => "{$holder} includes {$limit} stores, and you are using all of them. "
                .($next === null
                    ? 'Contact support to raise the limit for this business.'
                    : "Upgrade to {$next->name} to run up to "
                        .self::nextLimitLabel($next).'.'),
            'redirect' => self::UPGRADE_URL,
        ];
    }

    /**
     * The same facts as {@see self::refusal()} but as numbers, for a screen
     * that would rather show a progress line than a warning.
     *
     * @return array{used: int, limit: int|null, remaining: int|null, reached: bool, upgrade_url: string}
     */
    public static function payload(Business $business): array
    {
        $entitlements = Entitlements::for($business);
        $used = self::used((int) $business->id);

        return [
            'used' => $used,
            'limit' => $entitlements->limit(EntitlementRegistry::STORES),
            'remaining' => $entitlements->remaining(EntitlementRegistry::STORES, $used),
            'reached' => $entitlements->reached(EntitlementRegistry::STORES, $used),
            'upgrade_url' => self::UPGRADE_URL,
        ];
    }

    /**
     * What the plan that would end the refusal allows, worded for the sentence.
     */
    private static function nextLimitLabel(SubscriptionPlan $plan): string
    {
        $limit = Entitlements::forPlan($plan)->limit(EntitlementRegistry::STORES);

        return $limit === null ? 'as many stores as you need' : "{$limit} stores";
    }
}
