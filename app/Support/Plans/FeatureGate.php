<?php

namespace App\Support\Plans;

use App\Models\Business;
use App\Support\Payments\GatewayGrant;

/**
 * Whether a plan includes a whole feature, and what to say when it does not.
 *
 * The toggle-shaped sibling of {@see PlanQuota} and
 * {@see GatewayGrant}: {@see Entitlements::allows()} answers the
 * question, this turns the answer into something a screen can show. Everything
 * that gates a feature on a plan asks this class, so the sentence an owner reads
 * is written once — and so the plan named in it is read from the table rather
 * than typed into a string.
 *
 * ## Why the plan is looked up rather than named
 *
 * The obvious implementation of "accounting is an Enterprise thing" is the word
 * *Enterprise* in the refusal. That is wrong the first time somebody renames the
 * plan, and the platform lets them rename it from the admin console — the whole
 * point of storing entitlements as data rather than as code. So the message asks
 * {@see Entitlements::planGranting()} which active plan actually grants the
 * toggle and names that. This is the one reason that method exists.
 *
 * When no plan grants it at all, the message does not pretend there is an
 * upgrade to sell: it says the feature is unavailable and points at support,
 * which is the same thing {@see GatewayGrant} does for a business whose gateway
 * no plan includes.
 *
 * ## The label comes from the catalogue
 *
 * `{$label}` is the registry's own label for the key, so the admin editor's
 * wording and the sentence an owner reads cannot drift apart — the same coupling
 * {@see PlanQuota} has with the registry's `unit`. Every sentence below is
 * written to read correctly whatever the label is, because a refusal is not the
 * place to discover that a label is plural, or that it starts with a verb.
 */
final class FeatureGate
{
    /**
     * Distinct from `plan_limit_reached` and `subscription_required` because the
     * remedy and the sentence differ — this is "your plan does not have this",
     * not "you have used all of it" and not "you have no plan".
     */
    public const REFUSAL_CODE = 'plan_feature_unavailable';

    /** Where the SPA sends an owner who needs a plan that includes it. */
    public const UPGRADE_URL = '/plans';

    /**
     * Whether the business's plan includes this feature.
     */
    public static function allows(?Business $business, string $toggle): bool
    {
        return Entitlements::for($business)->allows($toggle);
    }

    /**
     * The refusal, in the shape the SPAs already handle, or null when the
     * feature is included.
     *
     * A `403` at the call site rather than a `422`, like the rest of this family:
     * nothing about the request is wrong, the business is asking for something
     * its plan does not include.
     *
     * @return array{code: string, message: string, redirect: string}|null
     */
    public static function refusal(?Business $business, string $toggle): ?array
    {
        $entitlements = Entitlements::for($business);

        if ($entitlements->allows($toggle)) {
            return null;
        }

        $label = self::label($toggle);

        // No plan and no live trial, so the toggles are all false because nothing
        // is resolved rather than because a plan said no. `EnsureManagementSubscription`
        // keeps such a business off `/management/*` first, so this is the belt to
        // that pair of braces — and it needs its own sentence, because "your plan
        // does not include accounting" is a lie about a business with no plan.
        if (! $entitlements->isResolved()) {
            return [
                'code' => self::REFUSAL_CODE,
                'message' => "This business has no active plan, so {$label} is unavailable. "
                    .'Choose a plan to continue.',
                'redirect' => self::UPGRADE_URL,
            ];
        }

        $plan = Entitlements::planGranting($toggle);

        return [
            'code' => self::REFUSAL_CODE,
            'message' => "Your current plan does not include {$label}. "
                .($plan === null
                    ? 'Ask support to enable it for this business.'
                    : "It is part of the {$plan->name} plan — upgrade to include it, or ask support "
                        .'to enable it for this business.'),
            'redirect' => self::UPGRADE_URL,
        ];
    }

    /**
     * The catalogue's own name for the feature.
     *
     * Falls back to the key rather than throwing: an unrecognised toggle is
     * already refused by {@see Entitlements::allows()} (an unknown key is never
     * `true`), so the worst a key reaching here can do is read awkwardly in a
     * message. The test that asserts every catalogue key has an enforcement point
     * is what stops an unlabelled key existing in the first place.
     */
    private static function label(string $toggle): string
    {
        return (string) (EntitlementRegistry::get($toggle)['label'] ?? $toggle);
    }
}
