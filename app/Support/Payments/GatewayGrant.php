<?php

namespace App\Support\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Whether a business may connect a payment provider's own keys.
 *
 * The platform's default is that its businesses' payments go through Storify:
 * for a small shop, a gateway of its own means a merchant account, its own KYC
 * and its own settlement schedule, which is exactly the friction a platform
 * exists to remove. An enterprise that already has an arrangement is granted
 * this individually, from the admin console —
 * `businesses.can_connect_own_gateway`.
 *
 * **One definition, two callers.** Both the WS-39 screen and the older WS-11
 * settings screen write these connections, and a rule enforced on one of them
 * is not a rule — it is a detour. Small enough to be a static, and static so
 * that neither service can quietly hold a different opinion.
 *
 * Only `keys` providers are gated. Manual bank transfer's "credentials" are the
 * business's own bank accounts; there is no other kind, so gating it would
 * remove a payment method rather than protect anything.
 */
final class GatewayGrant
{
    /**
     * @throws ValidationException when the business has not been granted the ability
     */
    public static function assertMayConnectOwnKeys(int $businessId, string $provider): void
    {
        if (! self::usesOwnKeys($provider)) {
            return;
        }

        if (self::isGranted($businessId)) {
            return;
        }

        throw ValidationException::withMessages([
            'config' => 'This business takes payments through Storify. Ask support to enable connecting your own payment provider.',
        ]);
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
