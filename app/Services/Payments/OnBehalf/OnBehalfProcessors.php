<?php

namespace App\Services\Payments\OnBehalf;

use App\Services\Payments\OnBehalf\Contracts\OnBehalfProcessor;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Which provider's on-behalf processor answers for a code.
 *
 * A one-line lookup in front of the registry, and it earns its place by being
 * the only place that decides *how* a processor is built: the class comes from
 * the provider's own registry entry, so a new provider needs an entry and a
 * class and nothing else — no `match` here to remember, and no second list of
 * provider names to drift out of step with the first.
 *
 * Nothing is cached. A processor holds no state between calls (every method
 * takes its account id and reads its credentials on use), so a cached instance
 * would only be a place for yesterday's settings to live — the same reasoning
 * that keeps `PlatformBillingGateway::code()` un-memoised.
 */
final class OnBehalfProcessors
{
    /**
     * The processor for a provider, or null when the platform cannot process on
     * a business's behalf there.
     *
     * Null is a real answer and callers must handle it: a business may have a
     * provider connected that the platform has no managed path for, and inventing
     * one would mean charging through credentials nobody holds.
     */
    public function for(string $code): ?OnBehalfProcessor
    {
        $class = PaymentGatewayRegistry::managedProcessorClassFor($code);

        if ($class === null) {
            return null;
        }

        // Resolved through the container so a processor's own dependencies are
        // injected rather than constructed here — the point of asking for the
        // class name instead of the object.
        $processor = app($class);

        return $processor instanceof OnBehalfProcessor ? $processor : null;
    }

    /**
     * The same, refused in words when there is none.
     *
     * @throws ValidationException
     */
    public function forOrFail(string $code): OnBehalfProcessor
    {
        $processor = $this->for($code);

        if ($processor === null) {
            $name = (string) (PaymentGatewayRegistry::get($code)['name'] ?? $code);

            throw ValidationException::withMessages([
                'provider' => $name.' cannot process payments on your behalf. Connect your own account instead.',
            ]);
        }

        return $processor;
    }
}
