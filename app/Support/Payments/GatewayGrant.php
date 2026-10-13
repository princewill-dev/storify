<?php

namespace App\Support\Payments;

use App\Support\Plans\EntitlementRegistry;
use App\Support\Plans\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Whether a business may connect a payment provider's own keys.
 *
 * The platform's default is that its businesses' payments go through Storify:
 * for a small shop, a gateway of its own means a merchant account, its own KYC
 * and its own settlement schedule, which is exactly the friction a platform
 * exists to remove. A business reaches this two ways, and they are alternatives
 * rather than stages of one another — {@see self::mayConnectOwn()} is the whole
 * rule and the only place it is written:
 *
 * 1. **A plan that includes it.** The top tier grants it as an entitlement, so
 *    buying the plan buys the ability. This is the ordinary route, and the one
 *    a business can take without asking anybody.
 * 2. **An individual grant** — `businesses.can_connect_own_gateway`, set from
 *    the admin console. For the enterprise that already has its own merchant
 *    arrangement, and for support cases: comped accounts, a migration, a
 *    business whose plan is being sorted out while it needs to trade today.
 *
 * **One definition, two callers.** Both the WS-39 screen and the older WS-11
 * settings screen write these connections, and a rule enforced on one of them
 * is not a rule — it is a detour. Small enough to be a static, and static so
 * that neither service can quietly hold a different opinion.
 *
 * **The system processor outranks both.** When an admin has selected one
 * provider for every customer inflow ({@see SystemProcessor}), a plan or a grant
 * granting own keys is not a permission the platform can honour: the charges
 * would still go through whoever's keys they are, and the split the platform
 * promised would not happen. The check is here rather than only in the resolver
 * so that the refusal arrives at the moment of the attempt, in words, instead of
 * the connection being written and silently ignored at every checkout. The keys
 * already stored are left alone — nothing is deleted, and turning the switch off
 * returns every one of them to service.
 *
 * Only `keys` providers are gated. Manual bank transfer's "credentials" are the
 * business's own bank accounts; there is no other kind, so gating it would
 * remove a payment method rather than protect anything. That is also why the
 * bank rail needs no exemption anywhere that lists providers: it is not caught
 * by the rule in the first place.
 */
final class GatewayGrant
{
    /**
     * @throws ValidationException when the business may not connect its own keys
     */
    public static function assertMayConnectOwnKeys(int $businessId, string $provider): void
    {
        if (! self::usesOwnKeys($provider)) {
            return;
        }

        // One question, asked once. The enforced processor, the individual grant
        // and the plan entitlement are all decided in `refusalReason()` — which
        // is also what the screen reads — so there is no ordering to get wrong
        // here and no second copy of either sentence to keep in step.
        $reason = self::refusalReason($businessId);

        if ($reason === null) {
            return;
        }

        throw ValidationException::withMessages(['config' => $reason]);
    }

    /**
     * May this business connect a provider of its own — the one rule, asked as
     * a question rather than applied as a refusal.
     *
     * The screen asks it this way; the write path asks it by calling
     * {@see self::assertMayConnectOwnKeys()}, which throws what
     * {@see self::refusalReason()} returns. Both land on that one method, so the
     * form a business is shown cannot offer something the server would then
     * refuse — which is the state that had a granted business looking at a key
     * form whose only outcome was an error.
     */
    public static function mayConnectOwn(int $businessId): bool
    {
        return self::refusalReason($businessId) === null;
    }

    /**
     * Why this business may not connect its own keys, or null when it may.
     *
     * The single place either sentence is written, so the words the screen shows
     * and the words the API rejects with cannot diverge — and so that the
     * enforced-processor case, which overrides everything else, is answered
     * before the plan is ever consulted.
     *
     * The plan's name is read from the table rather than written into the
     * sentence, because every plan's name is the admin's to change and a
     * hard-coded "Enterprise" goes wrong the first time somebody renames it.
     * When no plan grants the entitlement the original sentence is returned
     * instead, which is the honest answer for a deployment that only ever hands
     * this out individually.
     */
    public static function refusalReason(int $businessId): ?string
    {
        // Truthy, not `!== null`: `enforced()` is a `bool`, so an inequality
        // against null is true for every request and would put every business
        // behind a processor the platform has not selected.
        if (SystemProcessor::enforced()) {
            return 'Storify processes all payments through '
                .(SystemProcessor::name() ?? SystemProcessor::stored())
                .'. Connect your bank account to be paid instead of your own payment provider.';
        }

        if (self::isGranted($businessId)
            || Entitlements::forBusiness($businessId)->allows(EntitlementRegistry::OWN_GATEWAY)) {
            return null;
        }

        $plan = Entitlements::planGranting(EntitlementRegistry::OWN_GATEWAY);

        if ($plan === null) {
            return 'This business takes payments through Storify. Ask support to enable connecting your own payment provider.';
        }

        return "Connecting your own payment provider is part of the {$plan->name} plan. "
            .'Upgrade to connect your own keys, or ask support to enable it for this business.';
    }

    public static function isGranted(int $businessId): bool
    {
        return (bool) DB::table('businesses')
            ->where('id', $businessId)
            ->value('can_connect_own_gateway');
    }

    /**
     * Whether this provider is provisioned with the business's own keys, as
     * opposed to the business's own bank accounts.
     */
    public static function usesOwnKeys(string $provider): bool
    {
        return (PaymentGatewayRegistry::get($provider)['credential_source'] ?? 'keys') === 'keys';
    }
}
