<?php

namespace App\Http\Resources\Management\PaymentSettings\Concerns;

/**
 * The gateway-key mask the payment-settings screens render.
 *
 * Deliberately NOT SensitiveKeys::mask(), whose output for the same input is
 * `****abcd` where this returns `pk_test****abcd`. The shape here — the first
 * three characters for short keys, the first seven for long ones, then the
 * last four — is what the add/edit modal renders and what
 * ws11paymentconfigTest asserts, so it survives the extraction of the rest of
 * the key handling (redaction) to SensitiveKeys.
 */
trait MasksGatewayKeys
{
    private function maskKey(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        if (strlen($key) <= 10) {
            return substr($key, 0, 3).'****';
        }

        return substr($key, 0, 7).'****'.substr($key, -4);
    }
}
