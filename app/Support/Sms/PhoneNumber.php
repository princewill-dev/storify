<?php

namespace App\Support\Sms;

/**
 * One reading of a phone number, shared by every SMS surface.
 *
 * The KYC form collects a dialling code and a local number; Termii wants
 * digits; logs must never carry the whole thing. Normalising once — and
 * refusing a number that cannot be read unambiguously — is what keeps those
 * three views from disagreeing about who a code was sent to.
 */
final class PhoneNumber
{
    /**
     * The E.164 form (`+2348031234567`), or null when the input is not a
     * plausible number.
     *
     * Accepted shapes:
     *   +2348031234567    international, plus
     *   002348031234567   international, 00 prefix
     *   2348031234567     international, bare country code
     *   08031234567       local, leading zero replaced by the dial code
     *   8031234567        bare local, prefixed with the dial code
     */
    public static function normalize(
        ?string $dialCode,
        ?string $number,
        ?string $defaultDialCode = null,
    ): ?string {
        $raw = trim((string) $number);

        if ($raw === '') {
            return null;
        }

        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        $default = self::dialDigits($defaultDialCode)
            ?: self::dialDigits((string) config('services.termii.default_country_code', '234'));
        $code = self::dialDigits($dialCode) ?: $default;

        $e164 = match (true) {
            $hasPlus => '+'.$digits,
            str_starts_with($digits, '00') => '+'.substr($digits, 2),
            $default !== '' && str_starts_with($digits, $default) => '+'.$digits,
            str_starts_with($digits, '0') => $code === '' ? null : '+'.$code.substr($digits, 1),
            default => $code === '' ? null : '+'.$code.$digits,
        };

        if ($e164 === null) {
            return null;
        }

        $national = substr($e164, 1);

        // E.164 is 7–15 digits. A leading zero after the country code means
        // the number was local and already carried one; prefixing it again
        // would silently address a different subscriber.
        if (strlen($national) < 7 || strlen($national) > 15 || str_starts_with($national, '0')) {
            return null;
        }

        return $e164;
    }

    /**
     * Termii's `to` form: the same number without the plus.
     */
    public static function digits(string $e164): string
    {
        return preg_replace('/\D+/', '', $e164) ?? '';
    }

    /**
     * A form safe for logs. The only representation that may be written down.
     */
    public static function mask(string $e164): string
    {
        $length = strlen($e164);

        if ($length <= 7) {
            return str_repeat('*', $length);
        }

        $middle = max(0, $length - 9);

        return substr($e164, 0, 6).str_repeat('*', $middle).substr($e164, -3);
    }

    private static function dialDigits(?string $dialCode): string
    {
        return preg_replace('/\D+/', '', (string) $dialCode) ?? '';
    }
}
