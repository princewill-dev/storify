<?php

namespace App\Support\Money;

/**
 * Naira <-> kobo conversion.
 *
 * Money is stored and returned as integer kobo; naira appears only at the HTTP
 * boundary, where a person typed it or where a legacy decimal column holds it.
 *
 * This class deliberately exposes several conversion methods rather than one.
 * The codebase grew seventeen private implementations across controllers, and
 * they are NOT interchangeable — they disagree on real input. Merging them
 * into a single `toKobo()` would silently change what those call sites compute,
 * so each contract is named for what it actually does and call sites migrate
 * one at a time, verified against the behaviour they had.
 *
 * The disagreement is concrete:
 *
 *     input     koboFromLenient  koboFromStrict  koboFromDecimalOrFloat
 *     '12.'     1200             0               1200
 *     '1e3'     100              0               100000
 *     ''        0                0               0
 *
 * `koboFromStrict` guards gateway amount verification, where accepting a
 * malformed amount is a security problem rather than an inconvenience.
 */
final class Naira
{
    /**
     * Forgiving exact parse. Reads the digits either side of the decimal point
     * and ignores anything it does not understand.
     *
     * Legacy source: Api\V1\Admin\Concerns\InteractsWithPlans::toKobo().
     */
    public static function koboFromLenient(mixed $amount): int
    {
        $value = (string) ($amount ?? '0');
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        $kobo = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$kobo : $kobo;
    }

    /**
     * Strict parse. Anything that is not a plain decimal number — a trailing
     * point, scientific notation, an empty string — converts to zero.
     *
     * Used where an amount is compared against a payment gateway, so a
     * malformed value must fail closed rather than be guessed at.
     *
     * Legacy source: Api\V1\Management\Subscription\PaymentController::toKobo().
     */
    public static function koboFromStrict(string|int|float|null $amount): int
    {
        $value = trim((string) $amount);

        if ($value === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return 0;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $kobo = ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$kobo : $kobo;
    }

    /**
     * Exact parse when the input is a plain decimal, falling back to float
     * rounding otherwise. The exact branch keeps precision for the common case;
     * the fallback means a stray format still produces a plausible amount.
     *
     * Legacy source: Api\V1\Management\Accounting\BillController::toKobo().
     */
    public static function koboFromDecimalOrFloat(mixed $naira): int
    {
        $value = trim((string) $naira);

        if (preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');

            return ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        }

        return (int) round(((float) $value) * 100);
    }

    /**
     * Float multiply-and-round. The bluntest of the four and the only one that
     * can lose precision on large values — kept because call sites depend on
     * exactly this behaviour, not because it is a good default.
     *
     * Legacy source: Api\V1\Management\InvoiceController::kobo().
     */
    public static function koboFromRounded(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Exact decimal string, sign-aware.
     *
     * For non-negative kobo this is identical to the unsigned variants the
     * controllers each carried; for negative kobo it is correct where they
     * were not (they produced strings like "-1.-50").
     *
     * Legacy sources: Api\V1\Admin\AccountingController::naira() and
     * Api\V1\Management\Accounting\BillController::koboToDecimal().
     */
    public static function decimalFromKobo(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $kobo = abs($kobo);

        return $sign.intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Float naira. Only for call sites whose output is persisted as a decimal
     * or asserted as a JSON float — converting those to strings is a deliberate
     * change, not a refactor.
     *
     * Legacy source: Api\V1\Management\InvoiceController::naira().
     */
    public static function floatFromKobo(int $kobo): float
    {
        return $kobo / 100;
    }

    /**
     * Integer percentage of a kobo amount, truncated.
     *
     * Legacy source: Api\V1\Management\Subscription\PaymentController::discountKobo().
     */
    public static function percentOfKobo(int $kobo, int $basisPoints): int
    {
        return intdiv($kobo * $basisPoints, 10000);
    }
}
