<?php

namespace App\Support\Plans;

use App\Models\Business;
use App\Support\Payments\GatewayGrant;

/**
 * How many stores a business is running, and whether it may start another.
 *
 * The stores-shaped face of {@see PlanQuota}, which holds the rules: the four
 * ways a limit can be refused, the counting rules, the refusal envelope and the
 * grandfathering behaviour. This class is what the store screens and the store
 * create endpoint call, and it exists as its own name for two reasons.
 *
 * The first is that the store limit is the one limit the platform has actually
 * enforced for a while, and `ws52storequotaTest.php` is the suite that pins its
 * behaviour down — sixteen tests that assert on `StoreQuota::REFUSAL_CODE`,
 * `StoreQuota::used()`, `StoreQuota::reached()` and the exact phrases in its
 * refusals. Generalising the rules into `PlanQuota` is what the other three
 * limits needed; rewriting those sixteen tests so they read
 * `PlanQuota::refusal($business, EntitlementRegistry::STORES)` would buy nothing
 * and cost the suite the one property that makes it worth having, which is that
 * it describes stores.
 *
 * The second is that "how many stores may this business run" is a question with
 * a business answer, and `PlanQuota::refusal($business, 'stores_limit')` at a
 * call site reads as a generic mechanism being pointed at a specific thing.
 * Naming it here means the store screens ask their own question in their own
 * words, which is the same reason {@see GatewayGrant} is not
 * a call to `Entitlements::allows()`.
 *
 * Every method passes `EntitlementRegistry::STORES`, and `used()` counts the
 * same rows it always did: everything on the business that is not
 * `status = 'deleted'`, because stores are never hard-deleted.
 */
final class StoreQuota
{
    public const REFUSAL_CODE = PlanQuota::REFUSAL_CODE;

    /** Where the SPA sends an owner who needs a bigger plan. */
    public const UPGRADE_URL = PlanQuota::UPGRADE_URL;

    /**
     * Stores held against the business's plan right now.
     */
    public static function used(int $businessId): int
    {
        return PlanQuota::used($businessId, EntitlementRegistry::STORES);
    }

    /**
     * Whether no further store may be created.
     */
    public static function reached(Business $business): bool
    {
        return PlanQuota::reached($business, EntitlementRegistry::STORES);
    }

    /**
     * The refusal, in the shape the SPAs already handle, or null when a store
     * may be created.
     *
     * @return array{code: string, message: string, redirect: string}|null
     */
    public static function refusal(Business $business): ?array
    {
        return PlanQuota::refusal($business, EntitlementRegistry::STORES);
    }

    /**
     * The same facts as {@see self::refusal()} but as numbers, for a screen that
     * would rather show a progress line than a warning.
     *
     * @return array{used: int, limit: int|null, remaining: int|null, reached: bool, upgrade_url: string}
     */
    public static function payload(Business $business): array
    {
        return PlanQuota::payload($business, EntitlementRegistry::STORES);
    }
}
