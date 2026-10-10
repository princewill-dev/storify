<?php

namespace App\Console\Commands;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Support\Money\ExchangeRateService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls today's rates from the public API and stores the ones we can price.
 *
 * Runs daily off the scheduler. The endpoint is USD-based and keyless, so a row
 * is stored for every **quote** currency in the local `currencies` table —
 * roughly three, not the ~160 the API returns. That keeps the table to
 * currencies this app can actually charge in, and means adding a currency to the
 * seeder is the whole of the work to start pricing it.
 *
 * ## Failure is not destructive
 *
 * On any failure — no network, a non-200, a body that is not shaped like rates —
 * existing rows are left exactly as they are. The user's decision was that a
 * stale rate still applies, and wiping rows on a transient blip would take every
 * USD gateway offline until the next run, which is strictly worse than pricing
 * from yesterday.
 *
 * What is *not* silent is a scheduler that has stopped: {@see warnIfStale()}
 * logs when the newest row is already old, which is the only signal that a rate
 * is being trusted long past its freshness.
 */
class FetchExchangeRates extends Command
{
    protected $signature = 'rates:fetch';

    protected $description = 'Fetch today\'s exchange rates from the public API';

    /**
     * How old the newest stored rate may be before the run complains. Past
     * daily-plus-slack: one missed run is normal, three is a broken scheduler.
     */
    private const STALE_AFTER_HOURS = 72;

    public function handle(): int
    {
        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->acceptJson()
                ->get((string) config('services.exchange_rates.url'));
        } catch (ConnectionException $e) {
            $this->failGracefully('rates.fetch.failed', 'Could not reach the exchange rate provider.', $e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->failGracefully('rates.fetch.failed', 'The exchange rate provider answered with '.$response->status().'.', null);

            return self::FAILURE;
        }

        $body = $response->json() ?? [];
        $rates = $body['rates'] ?? null;

        if (! is_array($rates) || $rates === []) {
            // A 200 carrying something that is not a rate table — an error
            // envelope, an HTML page, a changed schema. Treated as a failure
            // rather than allowed to write zero rows over good ones.
            $this->failGracefully('rates.fetch.failed', 'The exchange rate response did not contain any rates.', null);

            return self::FAILURE;
        }

        // The provider's own publication time. Falls back to now, which is the
        // honest answer when the body omits it and only makes a stale rate look
        // slightly fresher, never older.
        $publishedAt = $this->publishedAt($body);
        $stored = 0;

        foreach (Currency::query()->pluck('code') as $code) {
            $quote = strtoupper((string) $code);

            if ($quote === ExchangeRateService::BASE) {
                // USD against itself is not a rate. Nothing stores it, and the
                // service resolves it structurally.
                continue;
            }

            $rate = $rates[$quote] ?? null;

            if (! is_numeric($rate) || (float) $rate <= 0) {
                // The API does not know this currency. Leave whatever we had.
                $this->warn("No rate returned for {$quote}; its existing row was left alone.");

                continue;
            }

            ExchangeRate::updateOrCreate(
                ['base' => ExchangeRateService::BASE, 'quote' => $quote],
                ['rate' => (string) $rate, 'fetched_at' => $publishedAt],
            );

            $stored++;
        }

        // The service caches the table for ten minutes; without this the job
        // would appear to do nothing for that long after a manual run.
        ExchangeRateService::forget();

        $this->info("Stored {$stored} of ".Currency::query()->count().' rates.');

        $this->warnIfStale();

        return self::SUCCESS;
    }

    /**
     * Says so loudly when the newest row predates the tolerance, so a scheduler
     * that has quietly stopped is visible in the logs rather than inferred from
     * a mispriced sale.
     */
    private function warnIfStale(): void
    {
        $newest = ExchangeRate::query()->max('fetched_at');

        if ($newest === null) {
            return;
        }

        $age = Carbon::parse($newest)->diffInHours(now());

        if ($age > self::STALE_AFTER_HOURS) {
            Log::warning('rates.fetch.stale', [
                'newest_fetched_at' => (string) $newest,
                'age_hours' => (int) $age,
            ]);

            $this->warn("The newest rate is {$age} hours old — the scheduler may not be running.");
        }
    }

    /**
     * `time_last_update_unix` when the provider supplies it, else now.
     *
     * Deliberately the *publication* time rather than the fetch time: a job that
     * has been down for a week and then runs should not stamp last week's rate
     * as this morning's.
     */
    private function publishedAt(array $body): Carbon
    {
        $unix = $body['time_last_update_unix'] ?? null;

        if (is_numeric($unix) && (int) $unix > 0) {
            return Carbon::createFromTimestamp((int) $unix);
        }

        return now();
    }

    /**
     * Logs and reports a failure that leaves the stored rates untouched.
     */
    private function failGracefully(string $event, string $message, ?string $error): void
    {
        Log::warning($event, array_filter([
            'message' => $message,
            'error' => $error,
        ]));

        $this->error($message.' Existing rates were left unchanged.');
    }
}
