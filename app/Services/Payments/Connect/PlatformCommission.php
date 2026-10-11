<?php

namespace App\Services\Payments\Connect;

use App\Models\Setting;
use App\Services\Subscription\PlatformBillingGateway;
use Illuminate\Support\Facades\Log;

/**
 * Storify's cut of a sale it processes on a business's behalf.
 *
 * Two parts, both admin-set on the Payment Methods screen: a percentage and a
 * flat amount. It covers **Storify's commission only** — the payment provider's
 * own processing fee is the provider's, varies by payment method (a card and a
 * bank transfer do not cost the same), and is deducted at source before we see
 * anything. Folding it into this number would make our margin a function of
 * which method the customer happened to pick.
 *
 * The percentage is held as **basis points** — hundredths of a percent, so
 * `2.50%` is `250` — because the column is a decimal and comparing floats for a
 * value that decides how much money leaves a business's account is a bad habit
 * to start. Everything here is integer arithmetic on kobo.
 *
 * **Reading is un-memoised**, for the same reason
 * {@see PlatformBillingGateway::code()} is: this is
 * admin-mutable state, and an instance that outlives a request would keep
 * answering with the rate it first saw.
 *
 * The amount is *refused*, never guessed at, when it cannot be taken. The
 * provider rejects `platform_fee >= gross` outright
 * (`400 INVALID_PLATFORM_FEE`), so a sale smaller than the flat fee would be a
 * customer-facing failure caused entirely by our own configuration. Returning
 * null lets a ₦20 sale go through with no cut taken; failing the sale would
 * punish the shopkeeper for our arithmetic.
 */
final class PlatformCommission
{
    /** Basis points in one percent — 100 bp = 1%. */
    private const BP_PER_PERCENT = 100;

    /** Basis points in a whole — the divisor that turns a rate into kobo. */
    private const BP_PER_WHOLE = 10_000;

    /** Half of `BP_PER_WHOLE`, added to round half-up in integer arithmetic. */
    private const BP_ROUNDING = 5_000;

    private function __construct(
        private readonly int $percentBasisPoints,
        private readonly int $fixedKobo,
    ) {}

    /**
     * The configured commission, or the zero commission a fresh install has.
     */
    public static function fromSettings(): self
    {
        $row = Setting::query()->first(['platform_commission_percent', 'platform_commission_fixed_kobo']);

        return new self(
            self::toBasisPoints($row?->platform_commission_percent),
            // Clamped, not trusted: a negative flat fee would be us paying the
            // business a bonus on every sale, and the column is hand-editable.
            max(0, (int) ($row?->platform_commission_fixed_kobo ?? 0)),
        );
    }

    /**
     * @param  float|int|string  $percent  a percentage, as stored: `2.50` is two and a half percent
     */
    public static function fromParts(float|int|string $percent, int $fixedKobo): self
    {
        return new self(self::toBasisPoints($percent), max(0, $fixedKobo));
    }

    /**
     * A decimal percentage as basis points, rounded to the hundredth of a
     * percent the column can hold. `2.5` and `"2.50"` and `2.50` all arrive at
     * `250`.
     */
    private static function toBasisPoints(float|int|string|null $percent): int
    {
        if ($percent === null || $percent === '') {
            return 0;
        }

        return max(0, (int) round(((float) $percent) * self::BP_PER_PERCENT));
    }

    public function percentBasisPoints(): int
    {
        return $this->percentBasisPoints;
    }

    public function fixedKobo(): int
    {
        return $this->fixedKobo;
    }

    /**
     * Whether this configuration takes anything at all.
     *
     * A zero commission is a real choice — a platform that charges businesses
     * only for their subscription — so it is not an error, and no `platform_fee`
     * line is sent for it.
     */
    public function isZero(): bool
    {
        return $this->percentBasisPoints === 0 && $this->fixedKobo === 0;
    }

    /**
     * The commission on a sale, in kobo — or null when none can be taken.
     *
     * Null means one of two things, and both mean "send no fee": nothing is
     * configured, or the total would not be strictly less than the sale. The
     * second is logged, because it is a configuration the admin should be told
     * about — every small sale is going untaxed — while the first is not.
     */
    public function on(int $grossKobo): ?int
    {
        if ($this->isZero() || $grossKobo < 1) {
            return null;
        }

        // Half-up rounding on integers: half the divisor added before an integer
        // division. Exact for every input, where `round($x * 0.025)` is not.
        $percentPart = intdiv(
            $grossKobo * $this->percentBasisPoints + self::BP_ROUNDING,
            self::BP_PER_WHOLE,
        );
        $total = $percentPart + $this->fixedKobo;

        if ($total < 1 || $total >= $grossKobo) {
            Log::warning('platform_commission.not_takeable', [
                'gross_kobo' => $grossKobo,
                'commission_kobo' => $total,
                'percent_basis_points' => $this->percentBasisPoints,
                'fixed_kobo' => $this->fixedKobo,
            ]);

            return null;
        }

        return $total;
    }

    /**
     * The commission as the decimal string the provider expects, or null.
     *
     * The same number as {@see on()}, formatted at the provider's precision
     * rather than converted again — there is exactly one calculation and this is
     * only its presentation.
     */
    public function onAsDecimal(int $grossKobo): ?string
    {
        $fee = $this->on($grossKobo);

        return $fee === null ? null : number_format($fee / 100, 2, '.', '');
    }
}
