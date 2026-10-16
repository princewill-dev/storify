<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Payout;
use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Ends every payout that is still open, and gives the money back where it is ours
 * to give back.
 *
 * ## Why a one-time command and not a schedule
 *
 * A payout row is a claim on a business's money held outside the balance it can
 * see. Rows left behind when the payout path could not send — a bank code in the
 * wrong provider's numbering, a send whose job exhausted its three attempts — never
 * complete on their own, and nothing retries them any more: they are not failures
 * the queue will pick up again and they are not transfers the provider can be asked
 * about. They simply sit there holding a balance down and making a business look
 * blocked.
 *
 * So this is an operator's clear-up, run deliberately, once. It is **not** a payout
 * path: it sends nothing and asks no provider anything.
 *
 * ## It shows you the rows before it touches them
 *
 * Every pass prints what it found, with the money at stake, and asks first.
 * `--force` is for a scripted run and skips the question, not the report.
 *
 * ## And it will not touch a `processing` row unless told to
 *
 * A `processing` payout was handed to a provider, and the provider has not said
 * what became of it. Closing one gives the business its balance back on a transfer
 * that may still arrive — they would be paid twice, and the platform would be the
 * one that did it. Those rows are listed and left alone; `--include-processing`
 * overrides that, for an operator who has already confirmed with the provider that
 * the transfer is gone, and the confirmation below says the amount out loud when
 * it is used.
 *
 * ## It keeps a quiet window
 *
 * `--older-than=5` (the default) ignores anything touched in the last five minutes.
 * A `pending` row is stamped as sent *before* the provider is called, so a row
 * inside that window may be a send in progress — and closing one of those credits
 * the money back while the transfer is still going out.
 *
 * The window needs to be no longer than one attempt, which is seconds; five minutes
 * is the shortest retry gap, and it is the number that makes the common case
 * obvious — a retry attempt at the five-minute mark has just stamped the row, so it
 * is still inside the window and is left alone. A row that is stuck rather than
 * sending does not change, so the next run finds it and closes it.
 *
 * ## Reading the output
 *
 * | | |
 * |---|---|
 * | `pending` | reserved, never sent — closed, and the money credited back to the wallet |
 * | `failed` | already had its money back — the row is closed so it stops counting against the business |
 * | `processing` | left alone; its provider webhook or a reconcile is what closes it |
 *
 * @see PayoutSweepService::closeOutstanding() for what each status means, and why
 *      only that service is allowed to move a payout's status.
 */
class CloseOutstandingPayouts extends Command
{
    protected $signature = 'custody:close-outstanding-payouts
        {--business= : Limit to a single business id}
        {--include-processing : Also close payouts already handed to a provider, which may still land}
        {--older-than=5 : Skip anything touched in the last N minutes, so a send in flight is not closed}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Close every payout that is still open, crediting back the money it was holding';

    public function handle(PayoutSweepService $sweep): int
    {
        $business = $this->business();

        if ($this->option('business') !== null && $business === null) {
            return self::FAILURE;
        }

        $includeProcessing = (bool) $this->option('include-processing');
        $quiescent = max(0, (int) $this->option('older-than'));

        // The same query the service will act on, so the list below is the list
        // that gets closed and not a second opinion about it.
        $open = $sweep->closeableQuery($business, $includeProcessing, $quiescent)->get();

        if ($open->isEmpty()) {
            $this->info($this->nothingToDo($sweep, $business, $includeProcessing, $quiescent));
            $this->reportProcessing($business, $includeProcessing);

            return self::SUCCESS;
        }

        if ($quiescent > 0) {
            $this->line(sprintf(
                'Ignoring anything touched in the last %d minute(s), so a payout being sent right now is left alone.',
                $quiescent,
            ));
        }

        $this->table(
            ['id', 'business', 'status', 'amount', 'reference', 'requested'],
            $open->map(fn (Payout $payout): array => [
                $payout->id,
                $payout->business_id,
                $payout->status,
                $this->money((int) $payout->amount_kobo, (string) $payout->currency),
                $payout->reference,
                $payout->requested_at?->toDateTimeString() ?? '—',
            ])->all(),
        );

        $this->warn($this->headline($open));

        if (! $this->option('force') && ! $this->confirm('Close these payouts now?', false)) {
            $this->line('Nothing was closed.');

            return self::SUCCESS;
        }

        $result = $sweep->closeOutstanding($business, $includeProcessing, $quiescent);

        $this->newLine();
        $this->info(sprintf('Closed %d payout(s).', $result['closed']));

        foreach ($result['by_status'] as $status => $count) {
            $this->line(sprintf('  %-12s %d', $status, $count));
        }

        if ($result['restored_kobo'] > 0) {
            $this->info(sprintf(
                'Credited %s back to business wallets.',
                $this->money($result['restored_kobo'], (string) config('custody.currency', 'NGN')),
            ));
        }

        $this->reportProcessing($business, $includeProcessing);

        return self::SUCCESS;
    }

    /**
     * One line the operator has to read before saying yes.
     *
     * @param  Collection<int, Payout>  $open
     */
    private function headline(Collection $open): string
    {
        $pending = $open->where('status', Payout::STATUS_PENDING);
        $processing = $open->where('status', Payout::STATUS_PROCESSING);
        $failed = $open->where('status', Payout::STATUS_FAILED);

        $parts = [];

        if ($pending->isNotEmpty()) {
            $parts[] = sprintf(
                '%d pending holding %s will be credited back to wallets',
                $pending->count(),
                $this->money((int) $pending->sum('amount_kobo'), (string) config('custody.currency', 'NGN')),
            );
        }

        if ($failed->isNotEmpty()) {
            $parts[] = sprintf('%d failed (money already returned) will only be closed', $failed->count());
        }

        if ($processing->isNotEmpty()) {
            $parts[] = sprintf('%d processing left untouched', $processing->count());
        }

        return ucfirst(implode('; ', $parts)).'.';
    }

    /**
     * What to say when the query came back empty.
     *
     * "Nothing outstanding" and "nothing outstanding *that this run was willing to
     * touch*" are different answers, and an operator who has just been told there is
     * nothing to do needs to know which one they got — otherwise the rows are still
     * open, the run looks like it worked, and the next person to look has no reason
     * to doubt it.
     */
    private function nothingToDo(
        PayoutSweepService $sweep,
        ?Business $business,
        bool $includeProcessing,
        int $quiescent,
    ): string {
        if ($quiescent <= 0) {
            return 'No outstanding payouts.';
        }

        $recent = $sweep->closeableQuery($business, $includeProcessing)->count();

        if ($recent === 0) {
            return 'No outstanding payouts.';
        }

        return sprintf(
            'No outstanding payouts older than %d minute(s). %d were touched more recently than that, which is '
            .'the quiet window this command keeps so it cannot close one that is being sent right now. Re-run it '
            .'once they have settled, or pass --older-than=0 if you know nothing is sending.',
            $quiescent,
            $recent,
        );
    }

    /**
     * What was deliberately left open, and how to close it if that is still wanted.
     */
    private function reportProcessing(?Business $business, bool $includeProcessing): void
    {
        if ($includeProcessing) {
            return;
        }

        $processing = Payout::query()
            ->where('status', Payout::STATUS_PROCESSING)
            ->when($business !== null, fn ($query) => $query->where('business_id', $business->id))
            ->count();

        if ($processing === 0) {
            return;
        }

        $this->newLine();
        $this->warn(sprintf(
            '%d payout(s) are `processing` — handed to a provider with no answer back yet. They were left alone '
            .'on purpose: their money may still be in flight, and closing one would pay the business twice if it '
            .'lands. Their route out is the provider webhook, or `php artisan custody:payouts`, which asks the '
            .'provider what became of them.',
            $processing,
        ));

        $this->line('To close them anyway, once you have confirmed with the provider that they are gone:');
        $this->line('  php artisan custody:close-outstanding-payouts --include-processing');
    }

    /**
     * The business this run is limited to, or null for all of them.
     *
     * Null when no filter was asked for, so the caller can tell "every business"
     * from "a business that does not exist" — the second is a typo, not a scope.
     */
    private function business(): ?Business
    {
        $id = $this->option('business');

        if ($id === null) {
            return null;
        }

        $business = Business::query()->find((int) $id);

        if ($business === null) {
            $this->error(sprintf('No business with id %s.', $id));
        }

        return $business;
    }

    private function money(int $kobo, string $currency): string
    {
        return number_format($kobo / 100, 2).' '.$currency;
    }
}
