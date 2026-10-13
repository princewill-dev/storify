<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Lets the sweep try a bank again after it has given up on it.
 *
 * ## Why this has to exist
 *
 * `custody:payouts` stops asking after `config('custody.max_destination_failures')`
 * refusals of the same bank account, because a destination the provider will not
 * register does not become registrable by being offered again, and a business
 * that needs looking at should not look like one that is merely between payouts.
 *
 * The ceiling is a guess about *the account*, though, made from what the provider
 * said — and the provider can be the one that is wrong. A destination service
 * having a bad afternoon produces exactly the refusals the ceiling reads as "this
 * bank is unusable", for a bank that is perfectly usable, and without this command
 * the businesses caught by it would need their bank changed and changed back to
 * be paid. That is a workaround dressed as a procedure, and it is the kind of
 * thing that gets done at the wrong moment.
 *
 * So: a person looks at the destination, decides it is right, and says so here.
 * The measured refusals are retired and the next sweep starts from nothing.
 *
 * ## It moves no money, and it cannot lose any
 *
 * Every row it touches is a `failed` payout, which already returned its
 * reservation to the wallet when it failed. Nothing is re-sent and nothing is
 * restored — the money has been sitting in `available_balance` the whole time,
 * and the run that follows this one is what pays it. The worst case of running
 * this on a genuinely unusable account is three more refusals to reach the same
 * ceiling again.
 *
 * Which is also why it takes named businesses rather than sweeping the fleet: the
 * judgement being recorded is about one destination, and there is no version of
 * "clear every business's ceiling" that is a decision rather than a reflex.
 */
class RetryCustodyPayouts extends Command
{
    protected $signature = 'custody:retry-payouts
                            {businesses* : Business ids or business codes}
                            {--dry-run : Report what would be cleared, and change nothing}';

    protected $description = 'Lift the payout ceiling for a business whose bank has been refusing payouts';

    public function handle(PayoutSweepService $payouts): int
    {
        $businesses = [];

        foreach ($this->argument('businesses') as $needle) {
            $business = $this->resolve($needle);

            if ($business === null) {
                $this->error("No business matches \"{$needle}\".");

                return self::FAILURE;
            }

            $businesses[] = $business;
        }

        $cleared = 0;

        foreach ($businesses as $business) {
            $cleared += $this->resume($business, $payouts);
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        if ($cleared > 0) {
            $this->newLine();
            $this->info(sprintf('%d refusal(s) retired.', $cleared));
            $this->line('The next `custody:payouts` run will try these banks again from a clean count.');
        }

        return self::SUCCESS;
    }

    /**
     * @return int the number of refusals retired for this business
     */
    private function resume(Business $business, PayoutSweepService $payouts): int
    {
        $label = "#{$business->id} {$business->name}";
        $bank = $business->payoutBank;

        if ($bank === null) {
            // Not a failure: there is nothing to clear because nothing was ever
            // attempted. The sweep's own output already names this business as
            // skipped for having no bank.
            $this->warn("  {$label} has no payout bank on file, so nothing has failed against one.");

            return 0;
        }

        $label .= sprintf(' — %s %s', (string) $bank->bank_code, (string) $bank->account_number);

        // Read from the same predicate the ceiling counts, so an operator is never
        // told "nothing to clear" by a command that would then clear something.
        $failures = $payouts->destinationFailures($business);

        if ($failures === 0) {
            $this->line("<comment>{$label}</comment> has no recorded refusals against this bank. Nothing to do.");

            return 0;
        }

        if ($this->option('dry-run')) {
            $this->info("{$label} would have {$this->plural($failures)} retired.");

            return $failures;
        }

        $cleared = $payouts->clearDestinationFailures($business);

        // Logged as well as printed. This is a person deciding that the evidence
        // against a bank is wrong, and the log is where anyone reading the payout
        // history later will find out who decided it and when.
        Log::warning('custody.payout.retry_authorised', [
            'business_id' => $business->id,
            'business_code' => $business->business_code,
            'bank_code' => (string) $bank->bank_code,
            'account_number' => (string) $bank->account_number,
            'failures_cleared' => $cleared,
        ]);

        $this->info("<info>{$label}</info>: {$this->plural($cleared)} retired.");

        return $cleared;
    }

    private function plural(int $count): string
    {
        return $count === 1 ? '1 refusal' : "{$count} refusals";
    }

    private function resolve(string $needle): ?Business
    {
        return Business::query()
            ->when(is_numeric($needle), fn ($query) => $query->where('id', (int) $needle))
            ->when(! is_numeric($needle), fn ($query) => $query->where('business_code', $needle))
            ->first();
    }
}
