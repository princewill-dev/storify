<?php

namespace App\Services\Payments\Payouts;

use App\Support\Payments\PaymentGatewayRegistry;

/**
 * Which rail moves money for a given provider code.
 *
 * A second registry beside {@see PaymentGatewayRegistry}
 * rather than an entry in it, because the two answer different questions about
 * the same names. Charging is "can this provider take a customer's money"; paying
 * out is "can this provider send ours to a bank account". A provider can do one
 * and not the other, and a build that assumed the sets were equal would offer a
 * payout on a rail that has no transfer API at all.
 *
 * Static and exhaustive, like the gateway registry: a rail a caller can construct
 * differently from another caller is a rail two of them disagree about, and the
 * failure mode here is money sent through the wrong provider.
 *
 * **A code absent from this map is an honest refusal, not a fallback.** There is
 * no default rail and no "try them in turn" — paying a business through a
 * provider nobody selected is worse than not paying it, because the next sweep
 * would try again through the right one and the business would be paid twice.
 */
final class PayoutRailRegistry
{
    /**
     * @var array<string, class-string<PayoutRail>>
     */
    private const RAILS = [
        'bachs' => BachsPayoutRail::class,
        'paystack' => PaystackPayoutRail::class,
    ];

    public static function has(string $code): bool
    {
        return array_key_exists($code, self::RAILS);
    }

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys(self::RAILS);
    }

    /**
     * @throws \InvalidArgumentException when nothing can pay out through this code.
     */
    public static function make(string $code): PayoutRail
    {
        if (! self::has($code)) {
            throw new \InvalidArgumentException(sprintf(
                'No payout rail for provider "%s". Payouts can be sent through: %s.',
                $code,
                implode(', ', self::codes()),
            ));
        }

        return app(self::RAILS[$code]);
    }
}
