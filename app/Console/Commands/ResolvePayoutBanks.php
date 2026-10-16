<?php

namespace App\Console\Commands;

use App\Models\StoreBank;
use App\Services\Payments\Payouts\PayoutBankResolver;
use App\Services\Payments\Payouts\PayoutRailRegistry;
use Illuminate\Console\Command;

/**
 * Works out, for every bank account already on file, how each payout provider
 * sees it — and registers the destination at the provider while it is there.
 *
 * ## Why this has to exist
 *
 * Accounts named before {@see PayoutBankResolver} existed carry a bank code in
 * Paystack's space and nothing else. A payout through Bachs took that number at
 * face value, was refused with `BANK_NOT_PAYABLE`, and was filed as a provider
 * fault. So the businesses it broke are exactly the ones already holding money,
 * and they break the same way every time.
 *
 * Accounts named *after* it exists are resolved as they are created, so this is a
 * repair for the backlog rather than part of the payout path.
 *
 * ## Reports by default
 *
 * A dry run is the default because this makes **outbound calls to a payment
 * provider**, and one of them creates an object there. That is not a column on our
 * own books and it is not ours to undo — so the first run says what it would do and
 * `--apply` is the deliberate second one.
 *
 * ## What it will not do
 *
 * - **Delete a row.** An account whose bank cannot be resolved keeps its row with
 *   the reason on it. An absent row is indistinguishable from one nobody has got
 *   to yet, which is the state this command exists to end.
 * - **Register a second destination for an account that already has one.** Bachs
 *   does not deduplicate — three identical registrations returned three ids — so a
 *   run that re-registered on every invocation would grow destinations without
 *   bound. An account already resolved and payable is reported `unchanged` and left
 *   alone, including under `--provider`. To force one to be re-checked, delete its
 *   `payout_bank_codes` row first; that is deliberate, and rare.
 *
 * ## Reading the output
 *
 * The outcomes, and only the last two want anything from a person:
 *
 * | | |
 * |---|---|
 * | `resolved` | registered and payable; the code may have been translated (only with `--apply`) |
 * | `would-resolve` | a dry run worked the code out; nothing was registered |
 * | `unchanged` | already resolved and payable; the provider was not asked again |
 * | `refused` | the provider cannot pay this bank — the business needs another account |
 * | `unchecked` | the provider could not be reached, or refused the account; run again |
 */
class ResolvePayoutBanks extends Command
{
    protected $signature = 'custody:resolve-payout-banks
        {--apply : Register destinations and write the rows, instead of only reporting}
        {--business= : Limit to a single business id}
        {--provider= : Limit to one payout provider}';

    protected $description = 'Resolve each stored bank account into every payout provider\'s code space';

    public function handle(PayoutBankResolver $resolver): int
    {
        $apply = (bool) $this->option('apply');

        $providers = $this->providers();

        if ($providers === []) {
            return self::FAILURE;
        }

        if (! $apply) {
            $this->warn('Dry run: nothing will be registered or written. Add --apply to do it.');
        }

        $rows = [];

        StoreBank::query()
            ->when($this->option('business'), fn ($query, $id) => $query->where('business_id', (int) $id))
            ->orderBy('id')
            ->chunkById(200, function ($banks) use ($resolver, $providers, $apply, &$rows) {
                foreach ($banks as $bank) {
                    foreach ($providers as $provider) {
                        $rows[] = $this->resolveOne($resolver, $bank, $provider, $apply);
                    }
                }
            });

        $this->report($rows, $apply);

        return self::SUCCESS;
    }

    /**
     * Resolve one account at one provider, and write it when told to.
     *
     * @return array{provider: string, outcome: string, translated: bool, written: bool}
     */
    private function resolveOne(PayoutBankResolver $resolver, StoreBank $bank, string $provider, bool $apply): array
    {
        $before = $resolver->stored($bank, $provider);

        // Already answered and payable. Asking the provider again would register a
        // destination that is already registered — and a second one every run, since
        // Bachs does not deduplicate.
        if ($before?->payable()) {
            return ['provider' => $provider, 'outcome' => 'unchanged', 'translated' => false, 'written' => false];
        }

        // `register: $apply` — a dry run works out the code and stops. Everything
        // below this line is reporting, and the provider is not asked to create
        // anything unless the operator said so.
        $resolution = $resolver->resolve($bank, $provider, register: $apply);

        $written = $apply;

        if ($apply) {
            $resolution->store($bank);
        }

        $outcome = match (true) {
            $resolution->payable() => 'resolved',
            ! $apply => 'would-resolve',
            $resolution->conclusive => 'refused',
            default => 'unchecked',
        };

        // On a dry run "resolved" is not available — nothing was registered — so the
        // translation is reported against the code that was worked out instead.
        $translated = $resolution->bankCode !== '' && $resolution->translated($bank);

        $this->line(sprintf(
            '  [%s] %s bank#%d "%s" -> %s%s',
            $outcome,
            $provider,
            $bank->id,
            $bank->bank_name,
            $resolution->bankCode !== '' ? $resolution->bankCode : '—',
            $translated ? sprintf(' (translated from %s)', $bank->bank_code) : '',
        ));

        if ($resolution->reason !== null && $outcome !== 'resolved') {
            // Indented under the row it belongs to, because the reason is the only
            // actionable part of a `refused` and the operator's next step is to tell
            // the business which bank to move to.
            $this->line('        '.$resolution->reason);
        }

        return ['provider' => $provider, 'outcome' => $outcome, 'translated' => $translated, 'written' => $written];
    }

    /**
     * @param  array<int, array{provider: string, outcome: string, translated: bool, written: bool}>  $rows
     */
    private function report(array $rows, bool $apply): void
    {
        if ($rows === []) {
            $this->info('No bank accounts to look at.');

            return;
        }

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['provider']][$row['outcome']] = ($counts[$row['provider']][$row['outcome']] ?? 0) + 1;
        }

        $table = [];

        foreach ($counts as $provider => $outcomes) {
            ksort($outcomes);

            foreach ($outcomes as $outcome => $count) {
                $table[] = [$provider, $outcome, $count];
            }
        }

        $this->newLine();
        $this->table(['provider', 'outcome', 'accounts'], $table);

        $translated = count(array_filter($rows, fn (array $row): bool => $row['translated']));

        if ($translated > 0) {
            // The headline, and the whole reason this command exists: each of these
            // is an account that would have been sent a code the provider does not
            // recognise, and a payout that would have been refused and blamed on the
            // provider.
            $this->info(sprintf(
                '%d account(s) needed their bank code translated into another provider\'s numbering.',
                $translated,
            ));
        }

        if (! $apply) {
            $this->warn('Nothing was written. Re-run with --apply to register destinations and store the codes.');

            return;
        }

        // Counted from what was actually written rather than from the rows looked
        // at. The difference matters on a second run, where every account comes back
        // `unchanged`: reporting the number of accounts would tell an operator that
        // rows had just been rewritten when nothing had been touched.
        $written = count(array_filter($rows, fn (array $row): bool => $row['written']));

        $this->info($written === 0
            ? 'Nothing to write: every account looked at was already resolved.'
            : sprintf('Wrote %d payout_bank_codes row(s).', $written));
    }

    /**
     * Which providers to ask about.
     *
     * @return array<int, string>
     */
    private function providers(): array
    {
        $requested = (string) ($this->option('provider') ?? '');

        if ($requested === '') {
            return PayoutRailRegistry::codes();
        }

        if (! PayoutRailRegistry::has($requested)) {
            $this->error(sprintf(
                'No payout rail for provider "%s". Available: %s.',
                $requested,
                implode(', ', PayoutRailRegistry::codes()),
            ));

            return [];
        }

        return [$requested];
    }
}
