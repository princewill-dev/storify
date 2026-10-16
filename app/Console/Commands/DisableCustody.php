<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The rollback: stops Storify collecting a business's money and hands it back
 * its own account.
 *
 * Available on purpose at any moment and from any state, because this is the
 * command an operator reaches for when something is wrong. It therefore does the
 * one thing that is unambiguously right — stops the new behaviour — and stops
 * there.
 *
 * ## What it deliberately does not do
 *
 * **It does not clear the wallet.** A business rolled back with a settled balance
 * is still owed that money, and zeroing the wallet would keep it.
 *
 * **It does not stop payouts.** The flag decides how a business is *charged*
 * and nothing else, so every credit already in the wallet keeps maturing and keeps
 * being paid out, and so do the sales collected but not yet credited — the send
 * reads whether Storify collected a sale, not whether the business is still being
 * collected for. That is the behaviour an operator needs from a rollback taken
 * during an incident: stop taking new money, and finish honouring what was already
 * taken. It used to be the opposite, with the balance left owing and a line of
 * output telling the operator to pay it by hand — and no tool left to do that with,
 * since the payout console went with the account machinery.
 *
 * **It does not move money back to the provider.** There is nowhere to move it to
 * — the money is in Storify's own provider balance, which is the platform's
 * account and not the business's. Paying it out is what settles it, and a queued
 * settlement is what pays it out.
 *
 * ## What it cannot undo
 *
 * Charges already collected stay collected, and are paid out whether or not the
 * business is still on the rail. A business flipped for an hour and flipped back
 * has had its sales for that hour processed by Storify; the rollback changes the
 * *next* charge, not the last one. Said here because an operator rolling back an
 * incident needs to know how much of the timeline is already behind them.
 */
class DisableCustody extends Command
{
    protected $signature = 'custody:disable
                            {businesses* : Business ids or business codes}
                            {--dry-run : Report what the rollback would leave behind, and change nothing}';

    protected $description = 'Stop collecting a business\'s payments, leaving what it is owed in the wallet';

    public function handle(): int
    {
        foreach ($this->argument('businesses') as $needle) {
            $business = Business::query()
                ->when(is_numeric($needle), fn ($query) => $query->where('id', (int) $needle))
                ->when(! is_numeric($needle), fn ($query) => $query->where('business_code', $needle))
                ->first();

            if ($business === null) {
                $this->error("No business matches \"{$needle}\".");

                return self::FAILURE;
            }

            $this->rollBack($business);
        }

        return self::SUCCESS;
    }

    private function rollBack(Business $business): void
    {
        $label = "#{$business->id} {$business->name}";

        if (! $business->isUnderCustody()) {
            $this->line("<comment>{$label}</comment> is not collecting through Storify. Nothing to do.");

            return;
        }

        // Read before the flag is cleared, so the figures describe the state the
        // rollback is leaving behind rather than the one after it.
        $pending = (int) $business->pending_balance;
        $available = (int) $business->available_balance;
        $settling = Transaction::query()
            ->where('business_id', $business->id)
            ->whereNotNull('settle_at')
            ->whereNull('promoted_at')
            ->count();

        if ($this->option('dry-run')) {
            $this->info("{$label} would stop collecting through Storify.");
        } else {
            $business->disableCustody();

            Log::warning('custody.disabled', [
                'business_id' => $business->id,
                'business_code' => $business->business_code,
                'pending_balance' => $pending,
                'available_balance' => $available,
            ]);

            $this->info("<info>{$label}</info> no longer collects through Storify.");
        }

        if ($pending + $available === 0) {
            $this->line('  Its wallet is empty, so there is nothing left owing on it.');

            return;
        }

        // The whole reason this command prints anything at all.
        $this->warn(sprintf(
            '  Still owed: %s NGN (%s payable now, %s not yet settled) across %d sale(s) inside their settlement window.',
            number_format(($pending + $available) / 100, 2),
            number_format($available / 100, 2),
            number_format($pending / 100, 2),
            $settling,
        ));
        $this->line('  The settlement path is still paying this business, so the money will leave on the next payout attempt.');
        $this->line('  See docs/custody-migration-runbook.md, "Rolling a business back".');
    }
}
