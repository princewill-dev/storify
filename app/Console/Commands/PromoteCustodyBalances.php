<?php

namespace App\Console\Commands;

use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\PayoutSweepService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Turns money Storify owes into money Storify may pay.
 *
 * The wallet has two buckets, and this is the only thing that moves money between
 * them. A collected sale is owed the moment it is confirmed but is not necessarily
 * *settled to us* — the provider holds it for a day or two first — so it lands in
 * `pending_balance`, and only here does it become `available_balance`, which is the
 * one the payout sweep draws from.
 *
 * The reason for the split is the whole reason this command exists: paying a
 * business out of money the provider has not released means Storify funding the
 * difference from its own balance, and several businesses doing that at once
 * overdraws the platform's provider account — which the provider then reports as a
 * negative balance and chases. That is a worse failure than a business waiting an
 * extra day.
 *
 * ## The window is a floor; the provider's balance is the gate
 *
 * "Has the provider released this money?" used to be answered entirely by the
 * settlement window in `config/custody.php`, which is an assumption nothing
 * verified. It is now answered by asking the provider what it actually holds —
 * {@see PayoutSweepService::promotionGate()} — and maturing only up to that. The
 * window stays as a floor, and it is a small one, because the two errors are not
 * symmetric: too long and a business waits for money it is owed, too short and
 * Storify pays out funds it has not received.
 *
 * The gate can only ever make this run mature **less**. And when the balance
 * cannot be read at all it falls back to the conservative window rather than to no
 * gate, so a provider that cannot be asked degrades the run to the slow path
 * instead of to an instant payout.
 *
 * ## Why it can run as often as it likes
 *
 * A promotion is a reclassification, not a payment: the business's total is
 * identical before and after, and every sale is marked as promoted so a second run
 * cannot move it twice. Running it with nothing due costs one indexed query.
 *
 * ## What it reports
 *
 * Counts. The one worth watching is `unpriced` — sales that settled while the
 * provider had not yet reported its charge, and so cannot be credited yet because
 * the business's share is not knowable. A number that only grows means those sales
 * are accumulating with nobody owed for them, which is a support ticket in the
 * making rather than a rounding difference.
 */
class PromoteCustodyBalances extends Command
{
    protected $signature = 'custody:promote
                            {--dry-run : Report what is pending without promoting anything}';

    protected $description = 'Mature settled custody sales from the pending wallet bucket to the payable one';

    public function handle(CustodyService $custody, PayoutSweepService $payouts): int
    {
        // Asked once, and both halves of the answer used together. The gate is a
        // reading of the provider's own balance, and asking for the budget and the
        // fallback separately could answer one from one reading and the other from
        // another.
        $gate = $payouts->promotionGate();

        if ($this->option('dry-run')) {
            return $this->dryRun($custody, $gate);
        }

        $counts = $custody->promoteSettled($gate['budget'], $gate['floor']);

        // Logged as well as printed, because this decides what the payout sweep is
        // allowed to send and the log is where anyone reviewing a payout after the
        // fact will look for why it was or was not sent.
        Log::info('custody.promote', $counts + ['gate' => $gate]);

        $this->info(sprintf(
            'Matured %d, still settling %d, unpriced %d.',
            $counts['matured'],
            $counts['pending'],
            $counts['uncredited'],
        ));

        if ($counts['uncredited'] > 0) {
            // Said plainly rather than left in the count. This is not an error —
            // a till leg has no provider fee until the provider settles — but it
            // is the one number here that can be wrong for a long time without
            // anything else noticing.
            $this->warn(sprintf(
                '%d collected sale(s) have no provider fee on record and cannot be credited yet.',
                $counts['uncredited'],
            ));
        }

        $this->reportGate($counts['budget_limited'], $gate);

        // Never a failure. A sale waiting on its provider's fee is the normal
        // state of a till leg for a day or two, and failing the run would put a
        // red mark on every hour of every day for something that is working.
        return self::SUCCESS;
    }

    /**
     * Report what would be promoted, without moving anything.
     *
     * Reads the same predicate and the same budget fit the real run uses, so what
     * this prints is the number the next non-dry run would move rather than an
     * estimate — including when the provider has not funded everything that is due,
     * which is the case a second, similar query would have got wrong.
     */
    private function dryRun(CustodyService $custody, array $gate): int
    {
        $due = $custody->pendingPromotion($gate['budget'], $gate['floor']);

        if ($due['matured'] === 0 && $due['uncredited'] === 0) {
            $this->info('Nothing to promote.');

            $this->reportGate($due['budget_limited'], $gate);

            return self::SUCCESS;
        }

        $this->table(
            ['bucket', 'sales', 'amount (NGN)'],
            [
                ['matured on next run', $due['matured'], number_format($due['matured_kobo'] / 100, 2)],
                ['awaiting the provider\'s fee', $due['uncredited'], number_format($due['uncredited_kobo'] / 100, 2)],
                ['still inside their settlement window', $due['settling'], number_format($due['settling_kobo'] / 100, 2)],
            ],
        );

        $this->reportGate($due['budget_limited'], $gate);

        return self::SUCCESS;
    }

    /**
     * Say what the provider's balance did to this run, when it did anything.
     *
     * The two sentences are deliberately different, because they are two different
     * situations that would otherwise both read as "the run matured less than
     * expected". One is money on its way to us; the other is money we could not ask
     * about.
     *
     * @param  array{budget: int|null, floor: CarbonInterface|null, measured: bool}  $gate
     */
    private function reportGate(int $budgetLimited, array $gate): void
    {
        if ($budgetLimited > 0) {
            $this->warn(sprintf(
                '%d sale(s) are past their window but the provider has not released the money behind them. '
                .'They stay payable and mature on a later run; nothing is lost.',
                $budgetLimited,
            ));
        }

        if (! $gate['measured'] && $gate['floor'] !== null) {
            $this->warn(sprintf(
                'The provider\'s balance could not be read, so only sales paid before %s were considered.',
                $gate['floor']->toDateTimeString(),
            ));
        }
    }
}
