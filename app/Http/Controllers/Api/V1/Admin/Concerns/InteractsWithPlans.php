<?php

namespace App\Http\Controllers\Api\V1\Admin\Concerns;

/**
 * WS-11 (admin console) — plan money and interval formatting, shared by the
 * subscription oversight list and the subscription-plan CRUD.
 *
 * `subscription_plans.amount` holds decimal naira (the legacy schema, kept by
 * the new stack — `Api\V1\Management\Subscription\PlanController` reads the
 * same column), while the API also speaks kobo so no caller ever does float
 * arithmetic on money. Conversion goes through exact string parsing, the same
 * contract the management plan payloads use.
 */
trait InteractsWithPlans
{
    /**
     * The plan/payment tables hold decimal naira; the API also speaks kobo so
     * callers never do float arithmetic on money. String parsing keeps it exact.
     */
    protected function toKobo(mixed $amount): int
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
     * Kobo back to the decimal-naira string the column stores — integer maths
     * only, so 10099 kobo is always "100.99".
     */
    protected function toNaira(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $kobo = abs($kobo);

        return $sign.intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Turn either write shape (`amount_kobo`, the house money unit, or the
     * legacy-style decimal `amount`) into the column's decimal-naira string.
     *
     * @param  array<string, mixed>  $data
     */
    protected function amountNaira(array $data): string
    {
        if (array_key_exists('amount_kobo', $data) && $data['amount_kobo'] !== null) {
            return $this->toNaira((int) $data['amount_kobo']);
        }

        return $this->normaliseNaira((string) $data['amount']);
    }

    /**
     * A validated decimal ≤2 places, padded to the column's scale.
     */
    protected function normaliseNaira(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', trim($amount), 2), 2, '0');

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /**
     * "monthly", "monthly x3" — the interval column plus its count when >1,
     * the way the legacy list rendered it (without the legacy "monthlyly"
     * string-concatenation bug).
     */
    protected function intervalLabel(string $interval, int $intervalCount): string
    {
        return $intervalCount > 1 ? $interval.' x'.$intervalCount : $interval;
    }
}
