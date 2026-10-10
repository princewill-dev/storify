<?php

namespace App\Support\Money;

use App\Models\ExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Converting a store amount into whatever currency a provider charges in.
 *
 * Six of the seven payment providers settle in naira, so for them this class is
 * a no-op: `convert()` returns its input unchanged. The seventh, Bitfra, prices
 * in USD, and a naira store that connects it is the reason this exists.
 *
 * ## What a rate is here
 *
 * Every row is stored USD-based — `base=USD`, `quote=<the other currency>` — and
 * means **units of quote per one USD**. A pair is therefore never fetched and
 * never stored directly; it is the ratio of two rows, which is why adding a
 * currency is a seeder row and not a code change.
 *
 * ## The assumption this class cannot enforce
 *
 * The conversion below cancels the two minor-unit scales against each other:
 *
 *     minor ÷ centScale   = major amount
 *     major × targetScale = target minor
 *
 * and both scales here are 100, so they cancel. **That is only true of
 * currencies with two decimal places.** The rate table is populated from
 * whatever is in `currencies`, so the day someone seeds JPY or KWD, this
 * silently starts charging a hundredfold. Hence {@see TWO_DECIMAL_CURRENCIES}:
 * anything outside it converts to `null`, which every caller reads as "cannot
 * price this" and refuses the payment. Failing closed costs a gateway; failing
 * open costs a customer two orders of magnitude.
 *
 * ## Missing versus stale
 *
 * A missing rate returns null and blocks — there is nothing to price with. A
 * *stale* rate is used anyway, however old: that was a deliberate choice, and
 * the fetch job logs when a rate has gone unrefreshed so a silently dead
 * scheduler shows up somewhere. This class does not second-guess the age.
 */
final class ExchangeRateService
{
    /** The stored basis. Every row in the table quotes against this. */
    public const BASE = 'USD';

    /**
     * Currencies whose minor unit is one hundredth. Kept explicit rather than
     * derived, because deriving it from the `currencies` table would mean the
     * table itself could widen the arithmetic's assumptions.
     *
     * @var list<string>
     */
    private const TWO_DECIMAL_CURRENCIES = ['NGN', 'USD', 'CAD'];

    private const CACHE_KEY = 'exchange_rates';

    private const CACHE_TTL_SECONDS = 600;

    /**
     * Drop the cached table, so the next read hits the database.
     *
     * Called by the fetch job after it writes — the cache is ten minutes long,
     * and without this a manual `rates:fetch` would appear to have done nothing.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Convert an amount in `$from` minor units into `$to` minor units, or null
     * when no rate exists for either side.
     *
     * The null return is the whole contract. `round($x) * null` is `0` in PHP,
     * not an error, so an unresolvable rate that was not checked for would turn
     * a ₦23,000 sale into a free one. Every operand is resolved before any
     * arithmetic happens.
     */
    public function convert(int $amountMinor, string $from, string $to): ?int
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        // The identity case, spelled out: six of seven providers take this
        // branch, and it must be byte-identical to the number the caller passed.
        if ($from === $to) {
            return $this->isTwoDecimal($from) ? $amountMinor : null;
        }

        $divisor = $this->divisorFor($from, $to);

        if ($divisor === null) {
            return null;
        }

        // The (int) is load-bearing: without it this returns a float from an
        // `?int` signature, which PHP truncates on the way out — dropping the
        // cent rather than rounding to it.
        return (int) round($amountMinor / $divisor);
    }

    /**
     * How many `$from` minor units make one `$to` minor unit.
     *
     * The single number a browser needs: divide an amount in `$from` minor
     * units by this to get `$to` minor units, rounded. One number rather than a
     * rates map, deliberately — the client's arithmetic has to mirror
     * `convert()`, and handing it two rates invites exactly the omission that
     * would charge naira as dollars.
     */
    public function divisorFor(string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if (! $this->isTwoDecimal($from) || ! $this->isTwoDecimal($to)) {
            return null;
        }

        if ($from === $to) {
            return 1.0;
        }

        $fromRate = $this->rateFor($from);
        $toRate = $this->rateFor($to);

        if ($fromRate === null || $toRate === null || $toRate <= 0.0) {
            return null;
        }

        return $fromRate / $toRate;
    }

    /**
     * The smallest `$from` amount, in minor units, that converts to at least
     * one minor unit of `$to`.
     *
     * `round(x, 0) >= 1` needs `x >= 0.5`, and `x = minor / divisor` — so the
     * floor is half a divisor. Exists so a refusal can name a real number
     * instead of telling the customer their payment is "too small" and stopping
     * there. Null when the pair cannot be priced at all.
     */
    public function smallestConvertibleMinor(string $from, string $to): ?int
    {
        $divisor = $this->divisorFor($from, $to);

        if ($divisor === null) {
            return null;
        }

        // The epsilon keeps an exact half (e.g. a divisor of 2 at 1 minor unit)
        // from being pushed up a cent by binary floating point.
        return (int) ceil(0.5 * $divisor - 1e-9);
    }

    /**
     * When the rate behind a `$from`→`$to` conversion was last published.
     *
     * The *oldest* stamp of the sides involved, because a conversion is only as
     * fresh as its stalest operand. Null when the pair converts at par (nothing
     * was consulted) or when either side has no row — the same condition under
     * which `divisorFor()` returns null.
     */
    public function ratedAt(string $from, string $to): ?Carbon
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return null;
        }

        $stamps = [];

        foreach ([$from, $to] as $code) {
            // The base quotes against itself; it has no row and needs none.
            if ($code === self::BASE) {
                continue;
            }

            $row = $this->snapshot()[$code] ?? null;

            if ($row === null) {
                return null;
            }

            $stamps[] = $row['fetched_at'];
        }

        return $stamps === [] ? null : min($stamps);
    }

    /**
     * Units of `$code` per one USD, or null when unknown.
     *
     * USD itself resolves to 1.0 without a row: the base needs no quotation, and
     * requiring one would take the whole table down whenever the API omitted the
     * pair it is itself based on.
     */
    private function rateFor(string $code): ?float
    {
        if ($code === self::BASE) {
            return 1.0;
        }

        return $this->snapshot()[$code]['rate'] ?? null;
    }

    /**
     * The whole table, by quote currency, cached. One query per ten minutes
     * rather than one per provider per checkout.
     *
     * @return array<string, array{rate: float, fetched_at: Carbon}>
     */
    private function snapshot(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $snapshot = [];

            foreach (ExchangeRate::query()->where('base', self::BASE)->get() as $row) {
                $snapshot[strtoupper((string) $row->quote)] = [
                    'rate' => (float) $row->rate,
                    'fetched_at' => $row->fetched_at,
                ];
            }

            return $snapshot;
        });
    }

    private function isTwoDecimal(string $code): bool
    {
        return in_array($code, self::TWO_DECIMAL_CURRENCIES, true);
    }
}
