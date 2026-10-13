<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Puts a business onto collect-and-pay-out — or takes it off, and says what that
 * leaves owing.
 *
 * ## What this used to be, and why it is smaller now
 *
 * This began as the S3/S4 switchover, and most of its length was a drain check:
 * before flipping a business, read the balance still sitting in its own account
 * at the provider and refuse while there was any, because an account orphaned
 * mid-flight is money nobody is watching. That check needed the connected
 * account, and the connected account is what collect-and-pay-out exists to
 * delete. With the rail gone there is no per-business provider account, no
 * balance of the business's to leave behind, and nothing to detect — so the check
 * is gone rather than disabled, and what is left is the flag.
 *
 * That is worth saying plainly, because the command now looks like an
 * under-built version of its former self and is not: there is simply nothing
 * left for it to be careful about. Every charge lands in Storify's balance and
 * every business is paid from its wallet, so a flip moves no money and strands
 * none. The one thing it still refuses to do quietly is leave a business unable
 * to be paid — see below.
 *
 * ## It is now the reverse of itself
 *
 * With every business on the new rail by default, flipping *off* is the operation
 * an operator is more likely to want, and {@see DisableCustody} owns that. This
 * one exists for the business that was taken off and needs putting back, which is
 * why the two are separate commands rather than one with a `--off` flag: they
 * print different things about what is left behind, and a flag would have made
 * one of those messages conditional.
 *
 * ## A missing bank is a warning, not a refusal
 *
 * A business with no payout bank on file cannot be *paid*, and there was a time
 * when that was a reason to refuse the flip — the old rail could still settle it,
 * so holding it back kept its money moving. That is not true any more. Refusing
 * now would leave the business on no rail at all: it could not charge through the
 * platform and it has nothing else, so a refusal would stop it selling to protect
 * it from a delay. It is flipped, and told.
 */
class EnableCustody extends Command
{
    protected $signature = 'custody:enable
                            {businesses* : Business ids or business codes}
                            {--dry-run : Report what the flip would find, and change nothing}';

    protected $description = 'Start collecting a business\'s payments and paying it out from Storify\'s balance';

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

        $flipped = 0;

        foreach ($businesses as $business) {
            if ($this->flip($business, $payouts)) {
                $flipped++;
            }
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        if ($flipped > 0) {
            $this->newLine();
            $this->info(sprintf('%d business(es) now collect through Storify.', $flipped));
            $this->line('Their next settled sale is picked up by `custody:promote`, then paid by `custody:payouts`.');
        }

        return self::SUCCESS;
    }

    /**
     * @return bool whether anything was actually flipped
     */
    private function flip(Business $business, PayoutSweepService $payouts): bool
    {
        $label = "#{$business->id} {$business->name}";

        if ($business->isUnderCustody()) {
            $this->line("<comment>{$label}</comment> is already collecting through Storify. Nothing to do.");

            return false;
        }

        // Warnings rather than blockers. All of them are things an operator should
        // read before they walk away, and none is a reason to leave the business on
        // no rail at all.
        $notes = [];

        $bank = $business->payoutBank;

        if ($bank === null) {
            $notes[] = 'no payout bank on file — it can sell, but there is nowhere to send the money until one is named';
        } elseif (blank($bank->bank_code) || blank($bank->account_number)) {
            $notes[] = "payout bank #{$bank->id} has no ".($bank->bank_code === null ? 'bank code' : 'account number');
        } elseif ($payouts->destinationIsExhausted($business)) {
            // The bank is on file and the provider has refused it as many times as
            // the ceiling allows, so the sweep has stopped asking. Said here because
            // this command is run by somebody looking at the business right now, and
            // the alternative is discovering it when the money does not arrive.
            $notes[] = 'the provider keeps refusing its payout bank — run `custody:retry-payouts` '
                .'once you have confirmed the account is right, or name a different one';
        }

        if ((int) $business->walletBalance() !== 0) {
            // Reachable on a business flipped on, off, and on again. Said out loud
            // because the wallet is not cleared by a rollback, and a number that
            // was already there is money somebody is still owed.
            $notes[] = sprintf(
                'wallet already holds %s NGN from a previous custody period',
                number_format($business->walletBalance() / 100, 2),
            );
        }

        foreach ($notes as $note) {
            $this->warn("  {$note}");
        }

        if ($this->option('dry-run')) {
            $this->info("{$label} would be flipped to custody.");

            return true;
        }

        $business->enableCustody();

        // Logged as well as printed: the flip is the moment a business's money
        // starts arriving in Storify's account rather than its own, and the log is
        // where anyone reconciling that decision later will look.
        Log::warning('custody.enabled', [
            'business_id' => $business->id,
            'business_code' => $business->business_code,
            'warnings' => $notes,
        ]);

        $this->info("<info>{$label}</info> now collects through Storify.");

        return true;
    }

    private function resolve(string $needle): ?Business
    {
        return Business::query()
            ->when(is_numeric($needle), fn ($query) => $query->where('id', (int) $needle))
            ->when(! is_numeric($needle), fn ($query) => $query->where('business_code', $needle))
            ->first();
    }
}
