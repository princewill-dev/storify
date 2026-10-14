<?php

namespace App\Support\Plans;

use App\Models\Business;
use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Management\ProductBulkService;
use App\Services\SubscriptionGate;
use App\Support\Payments\GatewayGrant;
use InvalidArgumentException;

/**
 * How much of a limited thing a business is holding, and whether it may start
 * another one yet.
 *
 * {@see Entitlements} says what the plan allows; this says what is actually held
 * against it, and the two are only ever compared here. Both the create screen
 * and the create endpoint ask this class, so the form a business is offered
 * cannot be one whose only outcome is a refusal — the same contract
 * {@see GatewayGrant} holds for the payment providers screen, and it is here for
 * the same reason: two callers asking a question in their own words is how a rule
 * ends up enforced in one place and described wrongly in another.
 *
 * This was {@see StoreQuota}, which is still the stores-shaped face of it and
 * still what the store screens call. It is generalised rather than copied
 * because the four ways a limit can be refused — no plan at all, a plan that
 * grants none, over the cap after a downgrade, and at the cap — are the same four
 * for staff, products and warehouses, and four more copies of that reasoning is
 * how the fourth one ends up describing the third one's situation.
 *
 * ## One resolver per key, and an exception for the rest
 *
 * {@see self::used()} throws on a key with no resolver rather than answering
 * zero. A silent zero is a limit that is enforced nowhere while reading as if it
 * were, which is the exact defect this whole class exists to close — the store
 * limit spent its life stored, displayed and validated, and read by no creation
 * path, so a three-store plan could run thirty. A `ws55planfeaturegateTest.php`
 * test asserts every `limit` in the catalogue has a resolver here, so the throw
 * is a backstop for a key added in a hurry rather than the mechanism.
 *
 * ## Counting is not `count()`
 *
 * Neither `Store` nor `Warehouse` uses `SoftDeletes`: deleting is
 * `status = 'deleted'` on a row that stays in the table, so counting rows would
 * keep charging a business for something it no longer has — an owner who removes
 * a store and still cannot add another has been told the limit is a lie.
 * Everything not `deleted` occupies a slot: `pending` and `suspended` included,
 * because a suspended store still exists on the business's account and the slot
 * it holds is not free.
 *
 * `Product` is the exception and is counted bare, because products really are
 * deleted — the row goes, along with its images and files
 * ({@see ProductBulkService::deleteProducts()}). There
 * is no deleted state to exclude.
 *
 * `staff_limit` counts **staff accounts, not people with access** — the owner is
 * not a seat. The staff directory shows the owner row on purpose, badged, so
 * counting its query would charge every business one seat for its own owner and
 * make a one-seat plan unusable.
 *
 * ## Reaching the cap is inclusive
 *
 * {@see Entitlements::reached()} is `<= 0` remaining, not `=== 0`, which is what
 * makes a grandfathered business behave: one that moved to a smaller plan while
 * holding more stores than it allows keeps every store it has and may not add
 * another. Without that, a business could be pushed *under* the cap by the act
 * of hitting it, or — worse — a plan change would start deleting things.
 */
final class PlanQuota
{
    public const REFUSAL_CODE = 'plan_limit_reached';

    /** Where the SPA sends an owner who needs a bigger plan. */
    public const UPGRADE_URL = '/plans';

    /**
     * Limited things the business is holding right now.
     *
     * @throws InvalidArgumentException when the key is not a countable limit
     */
    public static function used(int $businessId, string $key): int
    {
        $resolver = self::resolvers()[$key] ?? null;

        if ($resolver === null) {
            throw new InvalidArgumentException(
                "No used-count resolver for the '{$key}' entitlement. A limit that cannot be "
                .'counted cannot be enforced — add one here, or take the key out of the catalogue.'
            );
        }

        return $resolver($businessId);
    }

    /**
     * Whether none of this key may be added.
     */
    public static function reached(Business $business, string $key): bool
    {
        return Entitlements::for($business)->reached($key, self::used((int) $business->id, $key));
    }

    /**
     * The refusal, in the shape the SPAs already handle, or null when one more
     * may be created.
     *
     * `{code, message, redirect}` is the same contract
     * {@see SubscriptionGate::refusal()} returns, deliberately: a client that has
     * learnt to read one refusal should not have to learn a second format for the
     * next reason the platform says no. The codes are distinct —
     * `subscription_required`, `plan_limit_reached`, `plan_feature_unavailable` —
     * because the sentences and the remedies differ, but the envelope does not.
     *
     * @return array{code: string, message: string, redirect: string}|null
     */
    public static function refusal(Business $business, string $key): ?array
    {
        $used = self::used((int) $business->id, $key);
        $entitlements = Entitlements::for($business);

        if (! $entitlements->reached($key, $used)) {
            return null;
        }

        $unit = self::unit($key);
        $refuse = fn (string $message) => [
            'code' => self::REFUSAL_CODE,
            'message' => $message,
            'redirect' => self::UPGRADE_URL,
        ];

        // No plan and no live trial. `EnsureManagementSubscription` already keeps
        // such a business off `/management/*`, so this is the belt to that pair of
        // braces — and it needs its own sentence, because "you have reached 0
        // stores" is not an explanation of anything.
        if (! $entitlements->isResolved()) {
            return $refuse(
                "This business has no active plan, so no more {$unit} can be created. "
                .'Choose a plan to continue.'
            );
        }

        $limit = $entitlements->limit($key);

        // Resolved, and still grants nothing. An admin can set a plan to zero
        // stores, and a trial that found no plan row at all falls back to the
        // registry defaults — so this is reachable, and it must not be described
        // as a missing plan, which would send the owner looking for a
        // subscription they already have.
        if ($limit === 0) {
            $plan = $entitlements->plan();

            return $refuse(
                ($plan === null
                    ? "Your current plan does not include any {$unit}."
                    : "The {$plan->name} plan does not include any {$unit}.")
                    ." Choose a plan that includes {$unit} to continue."
            );
        }

        $plan = $entitlements->plan();
        $holder = $plan === null ? 'Your current plan' : "Your {$plan->name} plan";
        $next = Entitlements::planRaising($key, $used);

        // Over the cap rather than at it: the business is holding more than its
        // plan allows, which is the grandfathered state a downgrade leaves behind
        // and not an error. Say so plainly, or the owner reads a limit they have
        // already exceeded as a bug — or as a warning that something is about to
        // be taken away, which is the one thing this rule must never do.
        if ($limit !== null && $used > $limit) {
            return $refuse(
                "{$holder} includes {$limit} {$unit} and you have {$used}. "
                .'Every one of them keeps working, but no new one can be created until you are '
                .'back under the limit. '
                .($next === null
                    ? 'Contact support to raise it.'
                    : "Upgrading to {$next->name} would allow more.")
            );
        }

        return $refuse(
            "{$holder} includes {$limit} {$unit}, and you are using all of them. "
            .($next === null
                ? 'Contact support to raise the limit for this business.'
                : "Upgrade to {$next->name} to get up to ".self::nextLimitLabel($key, $next).'.')
        );
    }

    /**
     * The same facts as {@see self::refusal()} but as numbers, for a screen that
     * would rather show a progress line than a warning.
     *
     * @return array{used: int, limit: int|null, remaining: int|null, reached: bool, upgrade_url: string}
     */
    public static function payload(Business $business, string $key): array
    {
        $entitlements = Entitlements::for($business);
        $used = self::used((int) $business->id, $key);

        return [
            'used' => $used,
            'limit' => $entitlements->limit($key),
            'remaining' => $entitlements->remaining($key, $used),
            'reached' => $entitlements->reached($key, $used),
            'upgrade_url' => self::UPGRADE_URL,
        ];
    }

    /**
     * What the plan that would end the refusal allows, worded for the sentence.
     */
    private static function nextLimitLabel(string $key, SubscriptionPlan $plan): string
    {
        $limit = Entitlements::forPlan($plan)->limit($key);
        $unit = self::unit($key);

        return $limit === null ? "as many {$unit} as you need" : "{$limit} {$unit}";
    }

    /**
     * The noun the refusal is written in, taken from the catalogue so the admin
     * editor's unit hint and the sentence an owner reads cannot disagree.
     *
     * The registry's `unit` is singular for some keys and plural for others
     * ("staff accounts", "products"), and every sentence here uses the plural
     * form, so the values are written plural in the catalogue rather than
     * pluralised here — English is not a language you can add an "s" to.
     */
    private static function unit(string $key): string
    {
        return (string) (EntitlementRegistry::get($key)['unit'] ?? $key);
    }

    /**
     * One counting rule per limited key.
     *
     * @return array<string, callable(int): int>
     */
    private static function resolvers(): array
    {
        return [
            EntitlementRegistry::STORES => fn (int $businessId): int => (int) Store::query()
                ->where('business_id', $businessId)
                ->where('status', '!=', Store::STATUS_DELETED)
                ->count(),

            // Seats, not people with access: the owner runs the business, does
            // not occupy a seat on its own plan, and is deliberately excluded —
            // see the class docblock.
            EntitlementRegistry::STAFF => fn (int $businessId): int => (int) User::query()
                ->where('business_id', $businessId)
                ->where('role', 'staff')
                ->where('status', '!=', 'deleted')
                ->count(),

            EntitlementRegistry::PRODUCTS => fn (int $businessId): int => (int) Product::query()
                ->where('business_id', $businessId)
                ->count(),

            // The model's own answer to "is this row live", rather than the
            // same `where` written out again.
            EntitlementRegistry::WAREHOUSES => fn (int $businessId): int => (int) Warehouse::query()
                ->where('business_id', $businessId)
                ->notDeleted()
                ->count(),
        ];
    }
}
