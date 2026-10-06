<?php

namespace App\Support\Http;

/**
 * Redaction of secret-bearing values before they are persisted or returned.
 *
 * Extracted from ActivityRecorder, which carried this privately. Two other
 * places mask keys for display (the payment-settings controller and the store
 * payment-method controller) and had drifted into their own variants; this is
 * now the single definition of what counts as sensitive.
 */
final class SensitiveKeys
{
    public const REDACTED = '[redacted]';

    /**
     * A key containing any of these is sensitive, so `paystack_secret_key` and
     * `api_keys` are both caught.
     */
    private const FRAGMENTS = [
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'private_key',
        'credit_card',
        'card_number',
    ];

    /**
     * Short keys that are only sensitive on an exact match — a substring rule
     * would redact any key merely mentioning them.
     */
    private const EXACT = [
        'pin',
        'pin_code',
        'card_pin',
        'cvv',
        'cvc',
        'ssn',
    ];

    public static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::EXACT, true)) {
            return true;
        }

        foreach (self::FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively replace sensitive values so the result can be shown safely.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $values[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }

    /**
     * Mask a scalar for display — every character but the last few replaced.
     * For a key short enough that masking would still reveal it, nothing but
     * the mask itself is returned.
     */
    public static function mask(?string $value, int $visible = 4): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        if (strlen($value) <= $visible) {
            return self::REDACTED;
        }

        return str_repeat('*', max(0, strlen($value) - $visible)).substr($value, -$visible);
    }
}
