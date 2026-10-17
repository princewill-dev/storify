<?php

namespace App\Services\Payments\Connect;

use App\Models\Setting;
use App\Services\Subscription\PlatformBillingGateway;
use Illuminate\Support\Facades\Log;

/**
 * Storify's cut of a sale it processes on a business's behalf.
 *
 * A percentage, with an admin-set maximum per sale: **1% capped at ₦300**, for
 * example. The fee is the smaller of the two, so the percentage decides ordinary
 * sales and the ceiling decides large ones. It covers **Storify's commission
 * only**. The payment provider's own processing fee is *Storify's* cost, not the
 * business's — we are merchant of record and the provider takes its charge out
 * of our balance before we see the money, which is why it appears as
 * `5510 Provider Processing Fees` on our books and nowhere on the business's.
 * This number is the whole of what a business is charged for taking the payment.
 * Folding the provider's fee into it would make our margin a function of which
 * method the customer happened to pick, and would put a cost the business never
 * chose onto a line it can see.
 *
 * The flat per-sale amount this class used to add is gone. A fixed charge made
 * every small sale disproportionately expensive, and at its worst could equal or
 * exceed the gross and be refused entirely. A cap has the opposite shape: it
 * only ever reduces what a large sale pays, and it cannot make a small one
 * fail.
 *
 * The percentage is held as **basis points** — hundredths of a percent, so
 * `2.50%` is `250` — because the column is a decimal and comparing floats for a
 * value that decides how much money leaves a business's account is a bad habit
 * to start. Everything here is integer arithmetic on kobo.
 *
 * **The cap is applied after rounding and before the takeability guard.** That
 * order is load-bearing: a percentage that would consume a tiny sale can still
 * produce a perfectly sensible fee once capped, and refusing it before applying
 * the cap would make the cap unable to do the one thing it exists for.
 *
 * **A cap of zero means uncapped**, not "take nothing". A zero *percentage* is
 * the only configuration that takes nothing; a cap on its own is a ceiling, not
 * a charge.
 *
 * **Reading is un-memoised**, for the same reason
 * {@see PlatformBillingGateway::code()} is: this is
 * admin-mutable state, and an instance that outlives a request would keep
 * answering with the rate it first saw.
 *
 * The amount is *refused*, never guessed at, when it cannot be taken. The
 * provider rejects `platform_fee >= gross` outright
 * (`400 INVALID_PLATFORM_FEE`). Returning null lets a sale with a sub-kobo
 * percentage go through with no cut taken; failing the sale would punish the
 * shopkeeper for our arithmetic.
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
        /** The per-sale ceiling in kobo. Zero means there is none. */
        private readonly int $maxKobo,
    ) {}

    /**
     * The configured commission, or the zero commission a fresh install has.
     */
    public static function fromSettings(): self
    {
        $row = Setting::query()->first(['platform_commission_percent', 'platform_commission_max_kobo']);

        return new self(
            self::toBasisPoints($row?->platform_commission_percent),
            // Clamped, not trusted: a negative ceiling would turn every large
            // sale's fee into a negative amount, and the column is
            // hand-editable. Zero — uncapped — is the safe direction.
            max(0, (int) ($row?->platform_commission_max_kobo ?? 0)),
        );
    }

    /**
     * @param  float|int|string  $percent  a percentage, as stored: `2.50` is two and a half percent
     * @param  int  $maxKobo  the per-sale ceiling; zero means uncapped
     */
    public static function fromParts(float|int|string $percent, int $maxKobo): self
    {
        return new self(self::toBasisPoints($percent), max(0, $maxKobo));
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

    /**
     * The per-sale ceiling in kobo, or zero when the percentage is uncapped.
     */
    public function maxKobo(): int
    {
        return $this->maxKobo;
    }

    /**
     * Whether this configuration takes anything at all.
     *
     * A zero commission is a real choice — a platform that charges businesses
     * only for their subscription — so it is not an error, and no `platform_fee`
     * line is sent for it. Only the percentage decides this: a configured cap
     * with no percentage is a ceiling of nothing.
     */
    public function isZero(): bool
    {
        return $this->percentBasisPoints === 0;
    }

    /**
     * The commission on a sale, in kobo — or null when none can be taken.
     *
     * Null means one of two things, and both mean "send no fee": nothing is
     * configured, or the amount would not be strictly less than the sale. The
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
        $uncapped = intdiv(
            $grossKobo * $this->percentBasisPoints + self::BP_ROUNDING,
            self::BP_PER_WHOLE,
        );

        // The ceiling is applied here, before the guard below. A cap that fits
        // inside the sale is takeable even when the uncapped percentage is not.
        $fee = $this->maxKobo > 0 ? min($uncapped, $this->maxKobo) : $uncapped;

        if ($fee < 1 || $fee >= $grossKobo) {
            Log::warning('platform_commission.not_takeable', [
                'gross_kobo' => $grossKobo,
                'commission_kobo' => $fee,
                'uncapped_commission_kobo' => $uncapped,
                'percent_basis_points' => $this->percentBasisPoints,
                'max_kobo' => $this->maxKobo,
            ]);

            return null;
        }

        return $fee;
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
