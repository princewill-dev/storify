<?php

namespace App\Services\Payments\Payouts;

use App\Jobs\CheckPayoutStatus;
use App\Jobs\SettlePayouts;
use Illuminate\Support\Facades\Cache;

/**
 * Stops one silent provider from spending the whole worker asking it questions.
 *
 * ## The arithmetic this exists for
 *
 * Every client behind {@see PayoutRail} times out after thirty seconds. A provider
 * that accepts sends and then stops answering lookups — an outage, a hung status
 * endpoint, a DNS failure — therefore costs **up to thirty seconds of worker time
 * per question**, and the ladder asks nine questions per payout
 * ({@see CheckPayoutStatus}). Fifty transfers out is four hundred and
 * fifty questions; if they all hang, that is nearly four hours of worker time
 * arriving inside a two-hour window. The queue is FIFO and the ladder shares the
 * `default` lane with {@see SettlePayouts}, so the backlog does not just
 * delay status checks: it stops money.
 *
 * None of that work buys anything. When the provider is unreachable every question
 * returns `unresolved`, and the answer arrives in one second or in thirty — there
 * is nothing to learn until the provider is back.
 *
 * ## What it does, in three states
 *
 * - **Closed** (no keys): every check asks. This is the normal state, and it stays
 *   out of the way — two `has()` calls per lookup.
 * - **Open** (a key with a short TTL): every check is answered `unresolved`
 *   without touching the provider, so the whole ladder costs no HTTP. The ladder
 *   itself keeps running and still ends in its one alert, because a transfer
 *   nobody can account for must still reach a person. **The breaker suppresses
 *   the question, never the alert.**
 * - **Half-open** (the open key expired but the episode marker has not): exactly
 *   one check probes. If it gets through, the provider is back and everything
 *   resumes; if it does not, the breaker re-opens and the other checks keep
 *   waiting. That gate is not decoration: without it, every check due in the same
 *   second would probe at once and the herd would reproduce the burst the breaker
 *   was built to prevent.
 *
 * ## Why it lives in the cache, and why that is safe
 *
 * This is deliberately the one piece of custody state that is *allowed* to
 * evaporate. `php artisan optimize:clear` wipes it, a deploy empties it, and a
 * forgotten database cache forgets it — and in every one of those cases the
 * breaker simply closes, which is the fail-open direction. The cost of being
 * wrong here is one extra round of questions, never a lost payout or a missing
 * alert. The report marker a future digest would need is the opposite kind of
 * fact and must not be stored this way.
 *
 * Keys are per provider, because Bachs being down says nothing about Paystack.
 * The platform selects one processor at a time today, but a key that outlives
 * that assumption costs nothing and failing to have one would be invisible.
 */
final class PayoutLookupBreaker
{
    private const OPEN = 'custody:lookup:open:';

    private const EPISODE = 'custody:lookup:episode:';

    private const PROBE = 'custody:lookup:probe:';

    /**
     * How long the breaker stays open after a failed look-up, in seconds.
     *
     * Short on purpose. This is not a backoff for the provider — it is a pause so
     * the queue can do useful work while the provider is broken, and a provider
     * that comes back should start being asked again within a minute. A long
     * window would delay resolving every payout by that long for no benefit.
     */
    public static function windowSeconds(): int
    {
        return max(1, (int) config('custody.payout_lookup_breaker_seconds', 60));
    }

    /**
     * Whether this check may make a provider request.
     *
     * `false` means "do not ask, and do not treat that as a failure" — the caller
     * returns `unresolved` and the ladder advances.
     */
    public function allows(string $provider): bool
    {
        $provider = $this->key($provider);

        if (Cache::has(self::OPEN.$provider)) {
            return false;
        }

        // No episode means the provider has been answering. Everybody may ask.
        if (! Cache::has(self::EPISODE.$provider)) {
            return true;
        }

        // Half-open: the first caller after the open window takes the probe. The
        // key is `add()`-ed rather than checked and written, so this is atomic on
        // the database store and two workers cannot both win it.
        return Cache::add(self::PROBE.$provider, 1, self::windowSeconds());
    }

    /**
     * The provider could not be reached. Stop asking for one window.
     */
    public function recordUnreachable(string $provider): void
    {
        $provider = $this->key($provider);
        $seconds = self::windowSeconds();

        Cache::put(self::OPEN.$provider, true, $seconds);

        // Outlives the open window by one more, so the moment the open key
        // expires is visibly a *half-open* moment rather than an empty cache.
        // That is what distinguishes "we are recovering" from "we never failed".
        Cache::put(self::EPISODE.$provider, true, $seconds * 2);

        // The probe that just failed must not hold the gate for the next window.
        Cache::forget(self::PROBE.$provider);
    }

    /**
     * The provider answered — including "I have nothing for this reference".
     *
     * A provider that answers is up, whatever it said about one transfer, so this
     * is the signal that closes the breaker. It is called only where the rail was
     * actually asked; a Bachs send with no provider id never reaches the provider
     * at all and must not be mistaken for evidence that it is back.
     */
    public function recordAnswered(string $provider): void
    {
        $provider = $this->key($provider);

        Cache::forget(self::OPEN.$provider);
        Cache::forget(self::EPISODE.$provider);
        Cache::forget(self::PROBE.$provider);
    }

    private function key(string $provider): string
    {
        return strtolower(trim($provider));
    }
}
