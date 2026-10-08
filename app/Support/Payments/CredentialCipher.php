<?php

namespace App\Support\Payments;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts the fields a provider marks secret, and nothing else.
 *
 * This codebase had no encryption at rest before this. One gateway's secret key
 * in cleartext is defensible; eight of them, several of which can move money on
 * their own, is not — and the cost of switching later is re-keying every live
 * connection.
 *
 * Only secret fields are encrypted, so the JSON shape is unchanged
 * (`{"public_key":"pk_live_…","secret_key":"eyJpdiI6…"}`). That matters for two
 * reasons: `SensitiveKeys` still matches `secret_key` by name and keeps masking
 * it in audit logs and API responses, and a row written by the old code still
 * reads.
 *
 * Operational consequences, accepted deliberately and worth knowing:
 *  - losing `APP_KEY` kills every gateway connection, because the ciphertext
 *    becomes unreadable;
 *  - key rotation needs `payments:reencrypt-credentials` to run under the old
 *    key before the new one takes over.
 */
final class CredentialCipher
{
    /**
     * Mark a value as already-encrypted so a second pass is a no-op.
     *
     * Re-running the backfill, or saving a form that round-tripped an encrypted
     * value, must not double-encrypt — that would produce a value that decrypts
     * to ciphertext and fails at the gateway with no obvious cause.
     */
    private const PREFIX = 'enc:v1:';

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $secretKeys
     * @return array<string, mixed>
     */
    public static function encrypt(array $config, array $secretKeys): array
    {
        foreach ($secretKeys as $key) {
            $value = $config[$key] ?? null;

            if (! is_string($value) || $value === '' || self::isEncrypted($value)) {
                continue;
            }

            $config[$key] = self::PREFIX.Crypt::encryptString($value);
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $secretKeys
     * @return array<string, mixed>
     */
    public static function decrypt(array $config, array $secretKeys): array
    {
        foreach ($secretKeys as $key) {
            $value = $config[$key] ?? null;

            if (! is_string($value) || ! self::isEncrypted($value)) {
                continue;
            }

            try {
                $config[$key] = Crypt::decryptString(substr($value, strlen(self::PREFIX)));
            } catch (DecryptException) {
                // A value encrypted under a different APP_KEY is unusable, but
                // throwing here would take down every storefront page that
                // merely listed the provider. Drop it so the connection reads
                // as incomplete and the business is asked to re-enter the key.
                unset($config[$key]);
            }
        }

        return $config;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Whether any of the named keys is still stored in cleartext — used by the
     * backfill to find rows that still need it.
     *
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $secretKeys
     */
    public static function needsEncryption(array $config, array $secretKeys): bool
    {
        foreach ($secretKeys as $key) {
            $value = $config[$key] ?? null;

            if (is_string($value) && $value !== '' && ! self::isEncrypted($value)) {
                return true;
            }
        }

        return false;
    }
}
