<?php

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\Payout;
use App\Models\PayoutAllocation;
use App\Models\Store;
use App\Models\Transaction;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Takes every open settlement off the books, so the platform owes nothing.
 *
 * ## Why this is a command and not a feature
 *
 * The wallet's rule is that a collected sale is the business's money until the
 * customer is given it back — so the only way to stop owing it is to mark the sale
 * unwound, which is the same mark a refund leaves. There is no "forget this sale"
 * switch, deliberately: this is not a thing the product should be able to do, and
 * it exists only for a test platform whose balances have become an obstacle to
 * reading real behaviour out of the payout path.
 *
 * On a live platform it would be an accounting event — a customer's money taken for
 * a sale nobody refunded. That is why it is a one-time operator's tool, prints
 * everything it would do, and asks before doing it.
 *
 * ## Two movements, in this order
 *
 * 1. **Close every open payout** ({@see PayoutSweepService::closeOutstanding()}), so
 *    money reserved against a transfer that will never complete goes back to the
 *    wallets it came from and the sales behind it stop being claimed.
 * 2. **Void every sale still standing**, through
 *    {@see CustodyService::unwindCollectedSale()} — the same routine a refund
 *    uses, so the wallet and the journal move together and a sale cannot be voided
 *    twice.
 *
 * The order is not cosmetic. A sale inside an open payout is claimed by it, and a
 * claimed sale must not be voided: voiding one would take back money the payout is
 * still holding, while the payout's own close-out credits it back separately — two
 * credits for one sale. Closing first releases the claim, and the sale becomes
 * ordinary.
 *
 * The two movements cancel on the wallet: the close credits, the void debits, and
 * what is left is the platform owing nothing rather than the platform having paid
 * out. That is the whole point of running them as one command.
 *
 * ## What it will not touch
 *
 * - **A sale a payout already paid.** Its money is in a bank account, not in a
 *   wallet, and debiting the wallet for it would leave the business owing Storify
 *   for money it received. Reported, left alone.
 * - **A sale a payout is still carrying.** The transfer is out; its fate is the
 *   provider's to tell us. Reported, left alone.
 * - **A `processing` payout**, unless `--include-processing` — the same rule
 *   {@see CloseOutstandingPayouts} keeps, for the same reason: closing one credits
 *   a wallet on a transfer that may still land.
 * - **A payout or a sale touched in the last five minutes**, unless `--older-than=0`.
 *   A send stamps its row before it calls the provider, so a row inside that window
 *   may be mid-flight.
 *
 * ## What a voided sale looks like afterwards
 *
 * `settlement_state` reads **`refunded`**, because `unwound_at` is the only mark
 * that stops a wallet credit arriving later — a paid sale whose provider fee has
 * not been seen yet would otherwise be credited by the catch-up pass, and the
 * platform would owe money again the moment it had finished clearing it. So the
 * state is a consequence, not a claim that anybody was refunded; the journal says
 * which it was, because the reference this command posts is its own name
 * (`Test cleanup by custody:void-test-sales`) rather than a refund's.
 *
 * ## Reading the output
 *
 * | | |
 * |---|---|
 * | `from available` | the money had matured; it is debited from the spendable balance |
 * | `from pending` | credited but not yet matured; debited from the pending balance |
 * | `nothing to take back` | never credited, so no balance moved — cleared only to stop one arriving later |
 * | `already paid` | a payout landed and the money is with the business; untouched |
 * | `in flight` | a payout is still carrying it; untouched |
 *
 * The closing line prints what the platform owed before and after **for the scope
 * this run was given**. A remainder is not a failure of the run: it is money on
 * sales this command deliberately left alone, or a negative left by an earlier
 * refund, and the businesses holding it are listed underneath by name.
 */
class VoidTestSales extends Command
{
    /**
     * What the wallet and the journal record as the reason.
     *
     * Named after the command rather than described as a refund, because an
     * accountant reading `Collection returned ...` months from now is owed the
     * difference between a customer who got their money back and a test platform
     * that was wiped.
     */
    private const REFERENCE = 'Test cleanup by custody:void-test-sales';

    /** Promoted already: the money is in `available_balance`. */
    private const FROM_AVAILABLE = 'available';

    /** Credited but not matured: the money is in `pending_balance`. */
    private const FROM_PENDING = 'pending';

    /** No balance to move; the void is only to stop one arriving. */
    private const NOTHING = 'nothing';

    /** A payout landed, so the sale is not ours to take back. */
    private const SKIP_PAID = 'paid';

    /** A payout is carrying it right now. */
    private const SKIP_IN_FLIGHT = 'in_flight';

    protected $signature = 'custody:void-test-sales
        {--business= : Limit to a single business id}
        {--include-processing : Also close payouts already handed to a provider, which may still land}
        {--older-than=5 : Skip payouts touched in the last N minutes, so a send in flight is not closed}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Void every open settlement so the platform owes nothing';

    public function handle(CustodyService $custody, PayoutSweepService $sweep): int
    {
        $business = $this->business();

        if ($this->option('business') !== null && $business === null) {
            return self::FAILURE;
        }

        $includeProcessing = (bool) $this->option('include-processing');
        $quiescent = max(0, (int) $this->option('older-than'));

        // The same query the sweep will act on, so the list approved below is the
        // list that gets closed.
        $open = $sweep->closeableQuery($business, $includeProcessing, $quiescent)->get();

        // Closing a payout gives its claim on the sales back, so the sales behind it
        // become voidable *in this run* — and it only does that for a row whose
        // money is being credited back. A `failed` row has already given its money
        // back and released its claims, so its id belongs in neither list; if one is
        // somehow still claiming a sale, that sale is left alone, which is the safe
        // direction to be wrong in.
        $releasing = $open
            ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_PROCESSING])
            ->pluck('id');

        $before = $this->owed($business);
        $preview = $this->plan($business, $releasing);

        // `nothing` counts too: a sale the provider collected but the wallet has not
        // credited moves no balance, and is still the sale this command exists to
        // mark before something credits it.
        if ($open->isEmpty() && $preview['voided'] === 0 && $preview[self::NOTHING] === 0) {
            $this->info($this->nothingToDo($sweep, $business, $includeProcessing, $quiescent));
            $this->reportOwed('The platform owed', $before);

            // And what it refused to touch, if anything. "Nothing to do" and
            // "nothing this command will act on" are different answers, and the
            // second one is the reassuring one to be given when the platform is
            // still holding money that is not its to clear.
            $this->reportLeftAlone($preview);

            return self::SUCCESS;
        }

        $this->previewPayouts($open, $quiescent);
        $this->previewSales($preview);

        $this->warn($this->headline($open, $preview, $includeProcessing));

        if (! $this->option('force') && ! $this->confirm('Close these payouts and void these sales now?', false)) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        $closed = $sweep->closeOutstanding($business, $includeProcessing, $quiescent);

        $this->newLine();
        $this->info(sprintf('Closed %d payout(s), returning %s to the wallets.', $closed['closed'], $this->money($closed['restored_kobo'])));

        foreach ($closed['by_status'] as $status => $count) {
            $this->line(sprintf('  %-12s %d', $status, $count));
        }

        $result = $this->void($custody, $business);

        $this->newLine();
        // The two together, because both are sales this run took off the books and
        // only the split between them is what moved money. A count of the
        // balance-moving ones alone would read "Voided 0 sale(s)" on a run that
        // cleared a sale nobody had credited yet.
        $this->info(sprintf('Voided %d sale(s).', $result['voided'] + $result[self::NOTHING]));
        $this->line(sprintf('  took back from available   %s', $this->money($result[self::FROM_AVAILABLE])));
        $this->line(sprintf('  took back from pending     %s', $this->money($result[self::FROM_PENDING])));

        if ($result[self::NOTHING] > 0) {
            $this->line(sprintf(
                '  of those, %d held no balance (never credited, or a zero share) and were marked so no credit can land later',
                $result[self::NOTHING],
            ));
        }

        $this->reportLeftAlone($result);

        $after = $this->owed($business);

        $this->newLine();
        $this->reportOwed('The platform owed', $before);
        $this->reportOwed('It now owes', $after);

        if ($after['available'] !== 0 || $after['pending'] !== 0) {
            $this->newLine();
            $this->warn(
                'A balance is still standing, and this run was not able to clear it: money on a sale a payout '
                .'already paid, money a payout is still carrying, or a negative left behind by an earlier refund.'
            );
            $this->residuals($business);
        }

        return self::SUCCESS;
    }

    /**
     * Void every sale this run is willing to touch, and report what actually moved.
     *
     * Run after the payouts are closed, and reading the claim state fresh: the
     * preview decided against the world as it was before the close, and the close is
     * what made half these sales ordinary. Nothing is exempted here for the same
     * reason — every release that was going to happen has happened.
     *
     * @return array{voided: int, available: int, pending: int, nothing: int, paid: int, in_flight: int, refused: int}
     */
    private function void(CustodyService $custody, ?Business $business): array
    {
        $result = ['voided' => 0, 'available' => 0, 'pending' => 0, 'nothing' => 0, 'paid' => 0, 'in_flight' => 0, 'refused' => 0];

        // Stepping forward by id rather than by offset, which is what makes it safe
        // for the loop to change the predicate it is reading: a voided sale leaves
        // `unwound_at IS NULL` behind it, and a chunk that counted rows would skip
        // the next one.
        $this->salesQuery($business)
            ->with('payoutAllocations')
            ->orderBy('id')
            ->chunkById(200, function (Collection $sales) use ($custody, &$result) {
                foreach ($sales as $sale) {
                    $bucket = $this->classify($sale, collect());

                    if ($bucket === self::SKIP_PAID || $bucket === self::SKIP_IN_FLIGHT) {
                        $result[$bucket]++;

                        continue;
                    }

                    if ($bucket === self::NOTHING) {
                        // No balance moves, so there is no failure to report — but
                        // the mark is still made, because a sale that is paid and
                        // not yet credited is one the catch-up pass will credit.
                        $voided = $custody->unwindCollectedSale($sale, self::REFERENCE, null);

                        $result[$voided ? self::NOTHING : 'refused']++;

                        continue;
                    }

                    // The claim is taken inside, by condition, against `unwound_at` —
                    // so a sale a refund got to first is left alone rather than
                    // debited twice.
                    if (! $custody->unwindCollectedSale($sale, self::REFERENCE, null)) {
                        $result['refused']++;

                        continue;
                    }

                    $kobo = (int) ($sale->netKobo() ?? 0);

                    $result['voided']++;
                    $result[$bucket] += $kobo;
                }
            });

        return $result;
    }

    /**
     * Which of the three void shapes a sale is, or why it is left alone.
     *
     * @param  Collection<int, int>  $releasing  payouts this run will close and give their claims back
     */
    private function classify(Transaction $sale, Collection $releasing): string
    {
        $claim = $sale->claimingAllocation();

        if ($claim !== null && ! $releasing->contains($claim->payout_id)) {
            // `settled` means the transfer landed: the money is with the business and
            // the wallet already gave it up. `reserved` means one is out right now.
            return $claim->status === PayoutAllocation::STATUS_SETTLED ? self::SKIP_PAID : self::SKIP_IN_FLIGHT;
        }

        // Paid but not credited, or credited with a share of zero: nothing is held
        // against it, so there is no balance to take back.
        if ($sale->settle_at === null) {
            return self::NOTHING;
        }

        $net = $sale->netKobo();

        if ($net === null || $net <= 0) {
            return self::NOTHING;
        }

        // The bucket the credit went into, read the same way the reversal reads it.
        return $sale->promoted_at !== null ? self::FROM_AVAILABLE : self::FROM_PENDING;
    }

    /**
     * The sales this command considers settled money, in one predicate.
     *
     * Two ways to be holding a business's money, and the second is easy to forget:
     *
     * - **Credited** — `settle_at` is set, so the wallet has it, in `pending` or in
     *   `available`. Only {@see CustodyService::recordCollectedSale()} stamps it.
     * - **Collected and not yet priced** — the provider charged the customer, the
     *   wallet has not credited it because the provider's own fee has not been seen.
     *   The catch-up pass will credit it the moment the fee arrives, so leaving it
     *   alone would have the platform owing money again shortly after this command
     *   cleared it.
     *
     * `unwound_at IS NULL` in both, because a sale that was already given back is not
     * owed and has already left every balance.
     *
     * @return Builder<Transaction>
     */
    private function salesQuery(?Business $business): Builder
    {
        return Transaction::query()
            ->where('metadata->payment_mode', 'platform_managed')
            ->whereNull('unwound_at')
            ->where(function (Builder $query) {
                $query->whereNotNull('settle_at')
                    ->orWhere(function (Builder $query) {
                        $query->whereNotNull('paid_at')
                            ->whereIn('status', [TransactionStatus::PAID->value, TransactionStatus::CONFIRMED->value]);
                    });
            })
            ->when($business !== null, fn (Builder $query) => $query->where('business_id', $business->id));
    }

    /**
     * Count and price the sales, without touching any of them.
     *
     * The same predicate and the same classification the run itself uses, so the
     * table an operator approves cannot describe a different set of sales from the
     * one that gets voided.
     *
     * @param  Collection<int, int>  $releasing
     * @return array{voided: int, available: int, pending: int, nothing: int, paid: int, in_flight: int, by_business: array<int, array<string, int>>}
     */
    private function plan(?Business $business, Collection $releasing): array
    {
        $plan = $this->blankPlan();

        $this->salesQuery($business)
            ->with('payoutAllocations')
            ->orderBy('id')
            ->chunkById(200, function (Collection $sales) use (&$plan, $releasing) {
                foreach ($sales as $sale) {
                    $this->tally($plan, $sale, $this->classify($sale, $releasing));
                }
            });

        return $plan;
    }

    /** @return array{voided: int, available: int, pending: int, nothing: int, paid: int, in_flight: int, by_business: array<int, array<string, int>>} */
    private function blankPlan(): array
    {
        return ['voided' => 0, 'available' => 0, 'pending' => 0, 'nothing' => 0, 'paid' => 0, 'in_flight' => 0, 'by_business' => []];
    }

    /**
     * @param  array{voided: int, available: int, pending: int, nothing: int, paid: int, in_flight: int, by_business: array<int, array<string, int>>}  $plan
     */
    private function tally(array &$plan, Transaction $sale, string $bucket): void
    {
        $businessId = (int) $sale->business_id;

        $plan['by_business'][$businessId] ??= ['voided' => 0, 'available' => 0, 'pending' => 0, 'nothing' => 0];

        if ($bucket === self::SKIP_PAID || $bucket === self::SKIP_IN_FLIGHT) {
            $plan[$bucket]++;

            return;
        }

        if ($bucket === self::NOTHING) {
            $plan['nothing']++;
            $plan['by_business'][$businessId]['nothing']++;

            return;
        }

        $kobo = (int) ($sale->netKobo() ?? 0);

        $plan['voided']++;
        $plan[$bucket] += $kobo;
        $plan['by_business'][$businessId]['voided']++;
        $plan['by_business'][$businessId][$bucket] += $kobo;
    }

    /**
     * What the platform owes across every wallet it keeps.
     *
     * Both columns on both tables, because that is the sum the promotion gate calls
     * the liability and this command is judged by the same figure: with per-store
     * wallets on, a business's money sits on its stores and its own column holds
     * only what no store accounts for, so counting one and not the other would
     * report a platform owing nothing on a platform that still owes.
     *
     * `pending` is reported beside it and is deliberately not part of "what is owed"
     * in the gate's sense — it is owed, but it is not yet spendable, and it is what
     * this command debits for a sale that never matured.
     *
     * @return array{available: int, pending: int}
     */
    private function owed(?Business $business): array
    {
        return [
            'available' => (int) Business::query()->when($business, fn (Builder $query, Business $scope) => $query->whereKey($scope->id))->sum('available_balance')
                + (int) Store::query()->when($business, fn (Builder $query, Business $scope) => $query->where('business_id', $scope->id))->sum('available_balance'),
            'pending' => (int) Business::query()->when($business, fn (Builder $query, Business $scope) => $query->whereKey($scope->id))->sum('pending_balance')
                + (int) Store::query()->when($business, fn (Builder $query, Business $scope) => $query->where('business_id', $scope->id))->sum('pending_balance'),
        ];
    }

    /**
     * The businesses still holding a balance after the run, named.
     *
     * An operator who has just been told the platform owes nothing and finds out
     * otherwise from a screenshot deserves to have been told which business and by
     * how much, so this is a list rather than a total.
     */
    private function residuals(?Business $business): void
    {
        $stores = Store::query()
            ->when($business, fn (Builder $query, Business $scope) => $query->where('business_id', $scope->id))
            ->selectRaw('business_id, SUM(available_balance) as available, SUM(pending_balance) as pending')
            ->groupBy('business_id')
            ->get()
            ->keyBy('business_id');

        $rows = [];

        foreach (Business::query()
            ->when($business, fn (Builder $query, Business $scope) => $query->whereKey($scope->id))
            ->orderBy('id')
            ->get(['id', 'name', 'available_balance', 'pending_balance']) as $row) {

            $store = $stores->get($row->id);

            $available = (int) $row->available_balance + (int) ($store->available ?? 0);
            $pending = (int) $row->pending_balance + (int) ($store->pending ?? 0);

            if ($available === 0 && $pending === 0) {
                continue;
            }

            $rows[] = [$row->id, $row->name, $this->money($available), $this->money($pending)];
        }

        if ($rows === []) {
            return;
        }

        $this->table(['business', 'name', 'available', 'pending'], $rows);
    }

    /**
     * @param  Collection<int, Payout>  $open
     */
    private function previewPayouts(Collection $open, int $quiescent): void
    {
        if ($open->isEmpty()) {
            return;
        }

        if ($quiescent > 0) {
            $this->line(sprintf(
                'Ignoring payouts touched in the last %d minute(s), so a transfer being sent right now is left alone.',
                $quiescent,
            ));
        }

        $this->table(
            ['id', 'business', 'status', 'amount', 'reference', 'requested'],
            $open->map(fn (Payout $payout): array => [
                $payout->id,
                $payout->business_id,
                $payout->status,
                $this->money((int) $payout->amount_kobo),
                $payout->reference,
                $payout->requested_at?->toDateTimeString() ?? '—',
            ])->all(),
        );
    }

    /**
     * @param  array{voided: int, available: int, pending: int, nothing: int, paid: int, in_flight: int, by_business: array<int, array<string, int>>}  $plan
     */
    private function previewSales(array $plan): void
    {
        if ($plan['by_business'] === []) {
            return;
        }

        $names = Business::query()
            ->whereIn('id', array_keys($plan['by_business']))
            ->pluck('name', 'id');

        $rows = [];

        foreach ($plan['by_business'] as $id => $totals) {
            $rows[] = [
                $id,
                $names[$id] ?? '—',
                $totals['voided'],
                $this->money($totals['available']),
                $this->money($totals['pending']),
                $totals['nothing'],
            ];
        }

        $this->newLine();
        $this->table(['business', 'name', 'sales to void', 'out of available', 'out of pending', 'no balance'], $rows);
    }

    /**
     * @param  Collection<int, Payout>  $open
     * @param  array{voided: int, available: int, pending: int, nothing: int, paid: int, in_flight: int, by_business: array<int, array<string, int>>}  $plan
     */
    private function headline(Collection $open, array $plan, bool $includeProcessing): string
    {
        $parts = [];

        $pending = $open->where('status', Payout::STATUS_PENDING);

        if ($pending->isNotEmpty()) {
            $parts[] = sprintf(
                '%d open payout(s) holding %s will be closed and credited back to wallets',
                $pending->count(),
                $this->money((int) $pending->sum('amount_kobo')),
            );
        }

        $processing = $open->where('status', Payout::STATUS_PROCESSING);

        if ($processing->isNotEmpty() && $includeProcessing) {
            // Said out loud on purpose: this is the one flag that can pay a business
            // twice, and the amount is what the operator is risking.
            $parts[] = sprintf(
                '%d processing payout(s) holding %s will be closed although the provider has not said whether the '
                .'transfers landed',
                $processing->count(),
                $this->money((int) $processing->sum('amount_kobo')),
            );
        }

        $failed = $open->where('status', Payout::STATUS_FAILED);

        if ($failed->isNotEmpty()) {
            $parts[] = sprintf('%d failed payout(s), whose money is already back, will only be closed', $failed->count());
        }

        if ($plan['voided'] > 0) {
            $parts[] = sprintf(
                '%d sale(s) worth %s will be voided and taken back out of the wallets',
                $plan['voided'],
                $this->money($plan['available'] + $plan['pending']),
            );
        }

        if ($plan[self::NOTHING] > 0) {
            $parts[] = sprintf('%d sale(s) with no balance to take back will only be marked', $plan[self::NOTHING]);
        }

        $parts[] = 'the close credits the wallets and the void debits them again, so the two cancel and what is left is nothing owed';

        return ucfirst(implode('; ', $parts)).'.';
    }

    /**
     * @param  array{paid: int, in_flight: int, refused?: int}  $result
     */
    private function reportLeftAlone(array $result): void
    {
        $parts = [];

        if ($result[self::SKIP_PAID] > 0) {
            $parts[] = sprintf(
                '%d sale(s) a payout already paid — that money is with the business, and debiting the wallet for it '
                .'would leave them owing the platform',
                $result[self::SKIP_PAID],
            );
        }

        if ($result[self::SKIP_IN_FLIGHT] > 0) {
            $parts[] = sprintf('%d sale(s) a payout is still carrying', $result[self::SKIP_IN_FLIGHT]);
        }

        if (($result['refused'] ?? 0) > 0) {
            $parts[] = sprintf('%d sale(s) that were already unwound by something else while this ran', $result['refused']);
        }

        if ($parts === []) {
            return;
        }

        $this->newLine();
        $this->warn('Left alone: '.implode('; ', $parts).'.');
    }

    /** @param array{available: int, pending: int} $owed */
    private function reportOwed(string $label, array $owed): void
    {
        $this->line(sprintf(
            '%s %s (of which %s was spendable).',
            $label,
            $this->money($owed['available'] + $owed['pending']),
            $this->money($owed['available']),
        ));
    }

    /**
     * What to say when neither movement had anything to do.
     *
     * "Nothing to do" and "nothing this run was willing to touch" are different
     * answers, and the operator needs to know which one they got — a quiet window
     * that swallowed the whole run looks exactly like a clean platform otherwise.
     */
    private function nothingToDo(PayoutSweepService $sweep, ?Business $business, bool $includeProcessing, int $quiescent): string
    {
        if ($quiescent > 0) {
            $recent = $sweep->closeableQuery($business, $includeProcessing)->count();

            if ($recent > 0) {
                return sprintf(
                    'Nothing to do: %d payout(s) are open but were touched inside the %d-minute quiet window this '
                    .'command keeps so it cannot close one that is being sent right now. Re-run once they have '
                    .'settled, or pass --older-than=0 if you know nothing is sending.',
                    $recent,
                    $quiescent,
                );
            }
        }

        return 'Nothing to do: no open payouts, and no sale left to clear.';
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

    private function money(int $kobo): string
    {
        return number_format($kobo / 100, 2).' '.config('custody.currency', 'NGN');
    }
}
