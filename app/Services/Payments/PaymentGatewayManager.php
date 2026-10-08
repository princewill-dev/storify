<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Support\Payments\PaymentGatewayRegistry;

/**
 * Resolves a provider code to its driver.
 *
 * The single answer to "can this platform actually take money with this
 * provider". The catalogue in {@see PaymentGatewayRegistry} describes every
 * provider a business may one day connect; a driver is what makes one real, and
 * this is where the two are joined.
 *
 * That distinction is what keeps the rollout honest: the seeder marks a
 * provider available only when its driver exists, so a half-finished stage
 * cannot surface a gateway on the storefront that would fail at checkout.
 */
final class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    /**
     * The driver for a provider, or null when it has not been built yet.
     */
    public function driver(string $code): ?PaymentGateway
    {
        if (isset($this->resolved[$code])) {
            return $this->resolved[$code];
        }

        $class = PaymentGatewayRegistry::get($code)['driver'] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        $driver = app($class);

        if (! $driver instanceof PaymentGateway) {
            return null;
        }

        return $this->resolved[$code] = $driver;
    }

    /**
     * Whether this provider can take a payment today.
     */
    public function isImplemented(string $code): bool
    {
        return $this->driver($code) !== null;
    }

    /**
     * Every provider with a working driver.
     *
     * @return array<int, string>
     */
    public function implementedCodes(): array
    {
        return array_values(array_filter(
            PaymentGatewayRegistry::keys(),
            fn (string $code): bool => $this->isImplemented($code),
        ));
    }
}
