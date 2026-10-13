<?php

namespace App\Console\Commands;

use App\Services\Payments\Custody\PayoutHoldService;
use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pays every custody business out of Storify's own balance, and chases whatever
 * did not resolve.
 *
 * The second half of collect-and-pay-out, and the half that exists where the
 * connected-account sweep used to: money Storify collected is sitting in its own
 * provider balance, and this is what turns "owed" into "in their bank". A business
 * under custody has no account at the provider to be settled into, so if this does
 * not run, that business is never paid — there is no fallback underneath it.
 *
 * ## Why it runs often
 *
 * `custody:promote` decides what *may* be paid and, since the provider's own
 * balance became the gate, what is *spendable*; this decides what actually is.
 * Both run every five minutes, promote registered first so a sale it just
 * promoted is one the pass that follows can send. Running it when there is
 * nothing to pay costs one indexed query per pass and moves no money, and running
 * it often is what keeps a business's wait down to the provider's settlement
 * rather than to that plus however long the scheduler felt like waiting.
 * Deliberately not guarded by a `when()`: the cheapest way to find out this has
 * stopped is a business asking where its money is.
 *
 * ## It is the only sweep, and it owns reconciliation
 *
 * It used to run beside `connect:payouts`, which emptied a business's own account
 * at the provider, on the argument that two rails both being live meant neither
 * could be trusted to stop for the other. That rail is deleted along with the
 * account it emptied, so there is nothing left to run beside.
 *
 * ## Reconciliation is not an extra
 *
 * A payout the provider has accepted but never confirmed is a business that has
 * been paid and does not know it, and a webhook that never arrived is ordinary
 * rather than exceptional. Every run therefore ends by asking the provider about
 * every row that is still unresolved, and resending with the same reference the
 * ones whose send never completed — the reference is the idempotency key, so a
 * resend is the same request rather than a second payment.
 *
 * ## A held business is named, with the reason
 *
 * Payout holds ({@see PayoutHoldService}) are reported as their own count with the
 * holds listed underneath, and never folded into `held` or `skipped`. `held` means
 * a business's own owner switched automatic payouts off, and `skipped` means
 * nothing was owed or another run got there first — neither of which is true of a
 * hold. A hold is a decision somebody made about this business, and the run's job
 * is to make that legible: the count without the reason is the message that sends
 * an operator looking for a bug in the sweep.
 */
class SweepCustodyPayouts extends Command
{
    protected $signature = 'custody:payouts
                            {--dry-run : Report what is owed and payable without sending anything}
                            {--skip-reconcile : Pay only, and leave unresolved rows for the next run}';

    protected $description = 'Pay custody businesses from the internal wallet, and reconcile payouts in flight';

    public function handle(PayoutSweepService $payouts, PayoutHoldService $holds): int
    {
        // Refused before anything is attempted, and as a failure rather than a
        // silent success: with no processor selected there is no provider holding
        // collected funds, so nothing can be paid and somebody should know.
        if (($problem = $payouts->unavailableReason()) !== null) {
            Log::error('custody.payout.sweep_unavailable', ['reason' => $problem]);
            $this->error('Custody payouts cannot run: '.$problem);

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($payouts, $holds);
        }

        $counts = $payouts->sweep();

        Log::info('custody.payout.sweep', $counts);

        $this->info(sprintf(
            'Sent %d, held %d, on hold %d, skipped %d, failed %d, blocked %d.',
            $counts['sent'],
            $counts['held'],
            $counts['on_hold'],
            $counts['skipped'],
            $counts['failed'],
            $counts['exhausted'],
        ));

        if ($counts['held'] > 0) {
            $this->line(sprintf(
                '  %d business(es) are holding a payable balance with automatic payouts switched off.',
                $counts['held'],
            ));
        }

        if ($counts['on_hold'] > 0) {
            // Named with their reasons, and never as a bare count. A hold is a
            // person's decision, so the number on its own is the message that has
            // somebody hunting for a bug in the sweep — and the reason is the thing
            // they need in order to decide whether to lift it.
            $this->warn(sprintf(
                '  %d business(es) are on a payout hold — nothing was sent for them.',
                $counts['on_hold'],
            ));

            $this->table(
                ['id', 'scope', 'over', 'since', 'reason'],
                $holds->openHolds()->map(fn ($hold) => [
                    $hold->id,
                    $hold->scope,
                    $hold->describe(),
                    $hold->placed_at?->toDateTimeString(),
                    $hold->reason,
                ])->all(),
            );

            $this->line('  Lift one with `custody:release --business=<id>` (or `--store=` / `--system`).');
        }

        if ($counts['exhausted'] > 0) {
            // Not a delay and not something that resolves on its own: the provider
            // has refused this bank account as many times as the ceiling allows, so
            // the sweep has stopped asking. Named here because the alternative is a
            // business whose money is owed, visible in a table, and never sent.
            $this->warn(sprintf(
                '  %d business(es) have a payout bank the provider keeps refusing — payouts are blocked until '
                .'the bank changes or `custody:retry-payouts` resumes them.',
                $counts['exhausted'],
            ));
        }

        if ($counts['skipped'] > 0) {
            // Named rather than counted down, because this number is the one that
            // means "Storify is holding money it cannot hand over": a business with
            // no bank on file, or one whose balance another run took first. The
            // first is a business that needs chasing and the second is nothing.
            $this->warn(sprintf(
                '  %d business(es) were skipped — no payout bank on file, or another run took the balance first.',
                $counts['skipped'],
            ));
        }

        $reconciled = ['settled' => 0, 'reversed' => 0, 'failed' => 0, 'unresolved' => 0, 'resent' => 0];

        if (! $this->option('skip-reconcile')) {
            $reconciled = $payouts->reconcile();

            Log::info('custody.payout.reconcile', $reconciled);

            $this->info(sprintf(
                'Reconciled: %d settled, %d resent, %d reversed, %d failed, %d still unresolved.',
                $reconciled['settled'],
                $reconciled['resent'],
                $reconciled['reversed'],
                $reconciled['failed'],
                $reconciled['unresolved'],
            ));
        }

        if ($reconciled['unresolved'] > 0) {
            // Not an error, and not a failure exit: a transfer the provider has
            // accepted but not yet confirmed is the normal state for a while, and
            // a second Bachs send whose id never came back can only be resolved by
            // its webhook. What matters is that the number is visible on every run
            // rather than discovered by a business.
            $this->warn(sprintf(
                '  %d payout(s) are still unresolved; their wallet reservations stay held until the provider answers.',
                $reconciled['unresolved'],
            ));
        }

        // A run where every send failed is a failure, so the scheduler's output and
        // any monitoring on it reflect that. Individual failures beside successes
        // do not fail the run — those are named in the log, their money is already
        // back in the wallet, and the next tick retries them. Reconcile failures do
        // not fail it either: a refusal found on a read-back has already been
        // handled, and the money is where it belongs.
        return $counts['failed'] > 0 && $counts['sent'] === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Report what each custody business is owed, without sending any of it.
     *
     * Reads the same predicate the sweep does, so this is the sweep's scope rather
     * than an estimate of it — and it deliberately includes the businesses the
     * sweep cannot pay, because those are the ones an operator is looking for.
     */
    private function dryRun(PayoutSweepService $payouts, PayoutHoldService $holds): int
    {
        $businesses = $payouts->candidates();

        if ($businesses->isEmpty()) {
            $this->info('No custody business is holding a payable balance.');

            return self::SUCCESS;
        }

        $rows = [];
        $total = 0;
        $stuck = 0;

        foreach ($businesses as $business) {
            $available = (int) $business->available_balance;

            $hold = $holds->holdOn($business);

            // One definition of "why not", shared with the API and with the sweep
            // itself — so this table cannot say a business is payable while the
            // sweep is refusing it, and the wallet page cannot contradict either.
            $blocker = $payouts->payoutBlocker($business);

            // The payable figure comes from the sweep's own arithmetic rather than
            // from the wallet, because a held store is exactly the case where the
            // two differ — and this report is the thing an operator reads to find
            // out why a business it can see is owed money is not being paid.
            $payable = $blocker === null ? $payouts->payableFor($business) : null;

            if ($payable !== null) {
                $total += $payable['payable_kobo'];
                $stuck += $available - $payable['payable_kobo'];
            } else {
                $stuck += $available;
            }

            $storeHolds = $hold === null ? $holds->storeHolds($business) : collect();

            $rows[] = [
                $business->id,
                $business->name,
                number_format($available / 100, 2),
                $payable === null ? '—' : number_format($payable['payable_kobo'] / 100, 2),
                match (true) {
                    // A hold first, matching the sweep's precedence: a decision a
                    // person made about this business is the answer to the question,
                    // whatever else is also true of it.
                    $blocker !== null => $blocker['label'],
                    $storeHolds->isNotEmpty() => sprintf(
                        'paying the rest: %s withheld',
                        $storeHolds->map(fn ($h) => 'store '.$h->store_id)->implode(', '),
                    ),
                    default => '',
                },
            ];
        }

        $this->table(['business', 'name', 'wallet (NGN)', 'payable (NGN)', 'note'], $rows);
        $this->info('Would pay '.number_format($total / 100, 2).' NGN in total.');

        if ($stuck > 0) {
            $this->warn(sprintf(
                'And hold %s NGN, for businesses that cannot currently be paid — for a bank that will not work, '
                .'automatic payouts switched off, or a payout hold somebody placed. This is money Storify is '
                .'holding that its owner cannot receive, and none of it resolves on its own.',
                number_format($stuck / 100, 2),
            ));

            if ($holds->openHolds()->isNotEmpty()) {
                $this->line('  Some of it is on a hold, and `custody:hold` with no options lists those.');
            }
        }

        return self::SUCCESS;
    }
}
