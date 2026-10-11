<?php

namespace App\Console\Commands;

use App\Services\Payments\Connect\PlatformPayoutService;
use App\Services\Subscription\PlatformBillingGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Withdraws every business's settled money from its Bachs account to its bank.
 *
 * This is the half of the managed model that makes "we hold nothing" true: sales
 * land in the business's *own* connected account, and this is what empties it
 * without anyone clicking anything.
 *
 * ## Why it runs often, and why that is safe
 *
 * The provider settles a charge into `available_balance` on its own schedule —
 * days — and nothing can withdraw money that has not settled. So the useful
 * thing is to look frequently and pay only what the provider itself reports as
 * available, which is why this is scheduled repeatedly rather than once a day.
 * Running it when there is nothing to pay costs one balance read per account and
 * moves nothing.
 *
 * Three properties keep it from being dangerous, all of them in
 * {@see PlatformPayoutService}: the amount is the provider's own
 * `available_balance`; each request carries a derived `Idempotency-Key`, so a
 * retry after a crash is the same request rather than a second payment; and a
 * payout cannot be cancelled, so nothing is sent until the account has been read
 * back and its destination checked.
 *
 * ## What it reports
 *
 * Counts, and a log line per account whether it paid or not. `--dry-run` answers
 * what the sweep *would* do without sending anything, which is the only way to
 * see the first run's scope before granting it.
 */
class SweepPlatformPayouts extends Command
{
    protected $signature = 'connect:payouts
                            {--dry-run : Report what would be paid without requesting any payout}';

    protected $description = 'Withdraw settled balances from businesses\' connected provider accounts';

    public function handle(PlatformPayoutService $payouts, PlatformBillingGateway $billing): int
    {
        // Refused before anything is attempted, and as a failure rather than a
        // silent success: an unconfigured platform means no business's money is
        // moving, which is worth waking someone up for.
        if (($problem = $billing->credentialProblem('bachs')) !== null) {
            Log::error('bachs.payout.sweep_unavailable', ['reason' => $problem]);
            $this->error('Connected payouts cannot run: '.$problem);

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($payouts);
        }

        $counts = $payouts->sweep();

        // Logged as well as printed: this moves money with no human in the loop,
        // and the log is where anyone reviewing it after the fact will look.
        Log::info('bachs.payout.sweep', $counts);

        $this->info(sprintf(
            'Paid %d, held %d, skipped %d, failed %d.',
            $counts['paid'],
            $counts['held'],
            $counts['skipped'],
            $counts['failed'],
        ));

        // A run where every account failed is a failure, so the scheduler's
        // output and any monitoring on it reflect that. Individual failures that
        // sit beside successes do not fail the run — those accounts are named in
        // the log, and the next tick will retry them.
        return $counts['failed'] > 0 && $counts['paid'] === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Report what each account can move, without asking for any of it.
     *
     * Reads `available_balance` only, exactly as the sweep does, so what this
     * prints is what the sweep would pay — not an estimate. An account an admin
     * has switched off is reported with its balance and the reason, because the
     * businesses an operator is looking for when a transfer "never arrived" are
     * exactly the ones that will not move however much is sitting in them.
     */
    private function dryRun(PlatformPayoutService $payouts): int
    {
        $accounts = $payouts->connectedAccountBalances();

        if ($accounts === []) {
            $this->info('No connected accounts have a registered payout account.');

            return self::SUCCESS;
        }

        $rows = [];
        $total = 0;
        $held = 0;

        foreach ($accounts as $account) {
            $available = $account['available'];
            $isHeld = ! $payouts->autoPayoutEnabled((int) $account['business_id']);

            if ($available !== null) {
                $isHeld ? $held += $available : $total += $available;
            }

            $rows[] = [
                $account['account_id'],
                $account['business_id'],
                $account['store_id'] ?? '—',
                $available === null ? '—' : number_format($available / 100, 2),
                match (true) {
                    // Held first, and deliberately: it outranks the rest, because
                    // an account that will not move is the answer to the
                    // question, whatever else is also true of it.
                    $isHeld => 'held: automatic payouts off',
                    // Three different situations, said differently. No bank
                    // account registered yet is a business that has to finish
                    // signing up; an unreadable balance is usually transient.
                    ! $account['destination'] => 'no destination',
                    $available === null => 'unreadable',
                    default => '',
                },
            ];
        }

        $this->table(['account', 'business', 'store', 'available (NGN)', 'note'], $rows);
        $this->info('Would pay '.number_format($total / 100, 2).' NGN in total.');

        if ($held > 0) {
            $this->info('And hold '.number_format($held / 100, 2).' NGN, for businesses whose automatic payouts are switched off.');
        }

        return self::SUCCESS;
    }
}
