<?php

namespace App\Support\Money;

use App\Models\Store;
use App\Services\Payments\PaymentGatewayResolver;

/**
 * Which currency a store prices in.
 *
 * Lifted out of {@see PaymentGatewayResolver}, which was
 * the only place that knew the fallback order. The conversion call sites need
 * the same answer, and resolving it there by injecting the whole resolver would
 * have dragged the payment catalogue along with it.
 *
 * The order is the one the rest of the app already uses: the store's own
 * currency, then its business's, then the platform default.
 */
final class StoreCurrency
{
    public const DEFAULT = 'NGN';

    public static function for(Store $store): string
    {
        $store->loadMissing(['currency', 'business']);

        return strtoupper(
            (string) ($store->currency?->code ?? $store->business?->currency ?? self::DEFAULT)
        );
    }
}
