<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Support\Money\Naira;
use Illuminate\Console\Command;

/**
 * Fills in the two columns the split-at-source pipeline used to leave behind.
 *
 * `platform_fee_kobo` became a column after the model had already shipped, so
 * every sale settled before it carries the commission only in
 * `metadata.platform_fee_kobo`. `net_kobo` is worse: it was written by code that
 * subtracted the provider's fee and nothing else, so on a managed sale it
 * overstates what reached the business by exactly our commission.
 *
 * This is a command and not a migration for one reason: it rewrites money
 * columns on rows that are already on the books, and that is a thing to look at
 * before it happens rather than a thing to discover afterwards. It reports by
 * default; `--apply` is the deliberate second run.
 *
 * What it will not do, at any setting: touch `amount`, `currency`, `fee_kobo`,
 * `status` or `paid_at`; invent a commission that was never recorded; write a
 * net for a business-bearing row whose provider fee is unknown; or post, amend
 * or delete a journal entry. It is a repair of two derived columns, and nothing
 * else.
 *
 * **No historical net moves.** Every row that exists when this ships is
 * `business`-bearing — the column's own default — so each one is recomputed by
 * exactly the arithmetic it was written under. The bearer only changes the
 * answer for rows written after the rule changed, and those already carry the
 * right net, so the comparison below finds nothing to correct. That is the test
 * to run before `--apply`: a dry run must report zero net corrections on the
 * historical set.
 */
class BackfillProcessingFees extends Command
{
    protected $signature = 'payments:backfill-processing-fees
        {--apply : Write the values instead of only reporting them}
        {--business= : Limit to a single business id}
        {--limit=0 : Stop after this many transactions (0 for no limit)}';

    protected $description = 'Fill platform_fee_kobo from the charge-time metadata and recompute net_kobo';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $businessId = $this->option('business') ? (int) $this->option('business') : null;
        $limit = (int) $this->option('limit');

        $columnsFilled = 0;
        $netsCorrected = 0;
        $unexplainedCommission = 0;
        $awaitingFee = 0;
        $examined = 0;

        $query = Transaction::query()
            ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
            ->orderBy('id');

        $query->chunkById(500, function ($transactions) use (
            $apply, $limit, &$columnsFilled, &$netsCorrected, &$unexplainedCommission, &$awaitingFee, &$examined
        ) {
            foreach ($transactions as $transaction) {
                if ($limit > 0 && $examined >= $limit) {
                    return false;
                }

                $examined++;
                $updates = [];

                // The commission: into the column, from the charge-time record
                // that has been holding it since before the column existed.
                $recorded = $transaction->metadata['platform_fee_kobo'] ?? null;

                if ($transaction->platform_fee_kobo === null && is_numeric($recorded)) {
                    $updates['platform_fee_kobo'] = max(0, (int) $recorded);
                    $columnsFilled++;
                }

                // The net: derived by the same rule every writer uses, and
                // written only where it differs — so a sale on the business's
                // own keys, whose net was never wrong, is not rewritten at all.
                $providerFee = $transaction->fee_kobo !== null ? (int) $transaction->fee_kobo : null;

                // Every row this command was written for is a `business` row, and
                // reproduces its old net exactly. A `platform` row is one Storify
                // charged, where the provider's fee is not part of what the
                // business is owed — so its net is computable whether or not the
                // fee has arrived, and the null branch below must not hold it back.
                $bearer = $transaction->providerFeeBearer();

                if ($providerFee === null && $bearer->isBusiness()) {
                    // A till sale the provider has not settled yet. There is no
                    // net to write and no honest way to guess one.
                    if ($transaction->isPlatformManaged()) {
                        $awaitingFee++;
                    }
                } else {
                    $expected = Transaction::netKoboFor(
                        Naira::koboFromRounded($transaction->amount),
                        $providerFee,
                        $updates['platform_fee_kobo'] ?? $transaction->platformFeeKobo(),
                        $bearer,
                    );

                    if ($expected !== $transaction->net_kobo) {
                        $updates['net_kobo'] = $expected;
                        $netsCorrected++;
                    }

                    if (! $transaction->isPlatformManaged() && $transaction->platformFeeKobo() > 0) {
                        // An own-key sale carrying a commission is not something
                        // this command can explain, and it is not going to move
                        // money on a guess.
                        $unexplainedCommission++;
                    }
                }

                if ($apply && $updates !== []) {
                    // Query-builder update, not `save()`: `updated_at` should not
                    // move, because nothing about the transaction changed — only
                    // two derived columns were brought up to date.
                    Transaction::query()->whereKey($transaction->id)->update($updates);
                }
            }

            return true;
        });

        $this->report($examined, $columnsFilled, $netsCorrected, $awaitingFee, $unexplainedCommission, $apply);

        return self::SUCCESS;
    }

    private function report(
        int $examined,
        int $columnsFilled,
        int $netsCorrected,
        int $awaitingFee,
        int $unexplainedCommission,
        bool $apply,
    ): void {
        $this->info("Transactions examined: {$examined}");
        $this->info('Commission columns '.($apply ? 'filled' : 'to fill').": {$columnsFilled}");
        $this->info('Net amounts '.($apply ? 'corrected' : 'to correct').": {$netsCorrected}");

        if ($awaitingFee > 0) {
            $this->warn(
                "Managed sales awaiting the provider's fee: {$awaitingFee} — "
                .'their net stays empty until that arrives, which is the honest answer.'
            );
        }

        if ($unexplainedCommission > 0) {
            $this->warn(
                "Own-key sales carrying a commission: {$unexplainedCommission} — "
                .'inspect these by hand; a commission on a sale charged with the business\'s own keys is unexplained.'
            );
        }

        if (! $apply && ($columnsFilled > 0 || $netsCorrected > 0)) {
            $this->warn('Dry run. Run again with --apply to write these values.');
        }
    }
}
