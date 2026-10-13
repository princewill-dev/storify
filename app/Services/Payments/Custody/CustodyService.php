<?php

namespace App\Services\Payments\Custody;

use App\Models\Business;
use App\Models\Transaction;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Payments\Payouts\TransferOutcome;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The wallet Storify holds a business's money in, and the only thing that moves it.
 *
 * Under split-at-source there was no wallet: the provider settled each business
 * directly, and the provider's own per-business balance was the answer to "how much
 * is this business owed". Collecting the money ourselves removes that answer, so
 * the question is answered here instead — and the whole risk of the change is that
 * the replacement is weaker than what it replaced.
 *
 * Three things keep it honest, and every method below is one of them:
 *
 * 1. **Two buckets.** A confirmed sale is owed immediately but is not necessarily
 *    *settled to us*; only after the provider's window does it become payable. Paying
 *    out of `pending` means Storify advancing money it does not have.
 * 2. **One writer.** Nothing outside this class touches `pending_balance` or
 *    `available_balance`. Every transition has an idempotency marker on the row it
 *    came from, so a replayed webhook cannot credit twice — and only a sale the
 *    platform itself collected can be credited at all, so a business's own-key sale
 *    cannot put money in a wallet Storify would then pay out a second time.
 * 3. **The ledger moves with the wallet.** A balance change that has no journal
 *    entry behind it is money that exists in one place and not the other, and the
 *    discrepancy would only ever be found by a business being paid the wrong amount.
 *
 * Deliberately *not* here: the payout orchestration. This class decides what a
 * business is owed and whether it is payable; {@see PayoutSweepService}
 * decides when to send it. Keeping the wallet to one writer is what makes the
 * "can this be paid" question answerable by reading one file.
 */
final class CustodyService
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
        private readonly CustodyNotifier $notifier,
    ) {}

    /**
     * A collected sale settled, so the business is owed its share.
     *
     * `settle_at` is stamped here as the marker that the wallet has been credited,
     * and its absence is what makes this safe to call again — a webhook replayed,
     * a return-leg verify racing the webhook, a backfill re-running. Both paths
     * that settle a payment already guard on the transaction's status, but that
     * guard is one `if` away from being edited, and this one cannot be: a credited
     * sale carries its own proof.
     *
     * **A sale whose provider fee is not yet known is not credited at all.** The
     * business's share is `gross − provider fee − commission`, and the middle term
     * is not always there when the sale is written: a provider that reports its own
     * charge only when the charge settles leaves the row unpriced for days. Crediting
     * the commission alone would understate the debt, and crediting the gross would
     * overstate it by a fee that is coming. Neither is recoverable by a later
     * correction a business would ever see, so the sale waits, and
     * `custody:promote` reports how many are waiting.
     *
     * @param  string|null  $providerCode  the provider, where the caller knows it better than the row does
     * @return bool whether the wallet was credited
     */
    public function recordCollectedSale(Transaction $transaction, ?string $providerCode = null): bool
    {
        $business = $transaction->business;

        if (! $business instanceof Business) {
            return false;
        }

        // **The platform has to have collected it, which is not the same question as
        // the business being under custody.** A custody business can still charge a
        // till leg on keys of its own, and that money lands in its own provider
        // account — crediting the wallet for it would be Storify paying the business
        // out of its own balance for money the business already has, which is a
        // double payment rather than a delayed one. Every caller already guards on
        // this; the guard is repeated here because this method is the wallet's single
        // writer, and the rule is that it cannot be talked into crediting money we
        // never collected, however it is called.
        //
        // **And this is the only one of the two questions that is asked here.**
        // `businesses.custody_enabled` used to be tested beside it, and it was wrong
        // in a way that cost money: the flag is how a business is *charged*, decided
        // when the customer pays, while this method runs when the payment settles —
        // which is up to two days later, and `custody:disable` is available at any
        // moment. A business rolled back inside that window had a sale collected into
        // Storify's balance and then refused a credit for it, so the money was ours
        // to hold and nobody's to pay out: exactly the state the rollback's own
        // docblock promises cannot happen. Nothing was gained by the extra test
        // either — a row reading `platform_managed` *is* the record that the platform
        // charged on its own credentials with no per-business account, because the
        // resolver only ever writes that mode when it is collecting.
        if (! $transaction->isPlatformManaged()) {
            return false;
        }

        // **And it has to be money the wallet can represent.** `net_kobo` is computed
        // from `amount` — the store's own figure — while the provider fee and the
        // commission are the figures the provider and we charged, which for a
        // converted sale are not the same currency. See
        // {@see Transaction::isInCustodyCurrency()}: the wallet would be credited the
        // store's number read as naira. Refused rather than converted, because the
        // conversion would need a rate at the moment of the credit and the wallet,
        // the platform's custody accounts and the payout rail are all denominated in
        // one currency. The resolver refuses to charge such a sale in the first place
        // (`PaymentGatewayResolver::custodyCanHold()`); this is the guard for a row
        // that became one anyway — a store whose currency was changed while the sale
        // was in flight, or a backfill.
        if (! $transaction->isInCustodyCurrency()) {
            Log::warning('custody.sale_wrong_currency', [
                'transaction_id' => $transaction->id,
                'business_id' => $business->id,
                'currency' => $transaction->currency,
                'wallet_currency' => config('custody.currency'),
            ]);

            return false;
        }

        // Already credited. Not an error, and not something to log: the second
        // delivery of a webhook is the normal case.
        if ($transaction->settle_at !== null) {
            return false;
        }

        $netKobo = $transaction->netKobo();

        if ($netKobo === null) {
            Log::info('custody.sale_awaiting_provider_fee', [
                'transaction_id' => $transaction->id,
                'business_id' => $business->id,
            ]);

            return false;
        }

        if ($netKobo <= 0) {
            return false;
        }

        $settleAt = $transaction->settlementDueAt($providerCode);

        // A sale with no `paid_at` has no settlement window, and stamping null
        // would leave the row matching "not yet credited" forever — the credit
        // would replay on every pass. Refuse instead.
        if ($settleAt === null) {
            Log::warning('custody.sale_without_paid_at', [
                'transaction_id' => $transaction->id,
                'business_id' => $business->id,
            ]);

            return false;
        }

        // Claimed, then credited, in one transaction — and claimed *by
        // condition* rather than by reading the model. The webhook and the
        // return-leg verify can both arrive for the same sale at the same
        // moment, each holding its own copy of the row with `settle_at` still
        // null; only one of them gets to move `settle_at` off null, and only
        // that one credits. Reading `$transaction->settle_at` above is the
        // cheap first pass, not the guarantee.
        //
        // If the credit fails the claim rolls back with it, so a row is never
        // stamped as credited without having been.
        $credited = DB::transaction(function () use ($transaction, $business, $netKobo, $settleAt) {
            $claimed = Transaction::query()
                ->whereKey($transaction->id)
                ->whereNull('settle_at')
                ->update(['settle_at' => $settleAt]);

            if ($claimed === 0) {
                return false;
            }

            $business->creditPending($netKobo);

            return true;
        });

        if (! $credited) {
            return false;
        }

        // The write above went through the query builder, so the caller's
        // instance still says null. Bring it level — not saved again, just
        // correct for anything that reads it next.
        $transaction->settle_at = $settleAt;

        // The business's own books already carry this sale — `postManagedPaymentReceived`
        // is called by both settlement paths, and it is what credits `1040 Funds Held
        // by Storify` rather than gateway clearing. What has no entry yet is *our*
        // side: that we are holding money that is not ours. Keyed on the transaction
        // so a replay cannot post it twice, and wrapped so a ledger fault cannot
        // unwind a wallet credit that already happened — a business owed money is
        // the state to be in, and it is reconcilable; a business credited and then
        // silently un-credited is not.
        $this->ledger->safe(fn () => $this->ledger->postCustodyCollection($transaction, $netKobo));

        // Told, after the money and after the claim. Both orderings matter: the
        // credit is committed before the message says it happened (so the message
        // is never about money that then failed to arrive), and the hook is past
        // the `settle_at` claim above (so a replayed webhook and a return-leg
        // verify racing it both reach a claim that has already been taken and
        // return before this line).
        //
        // Nothing here can unwind the credit: the notifier catches its own mail
        // failures, and a notification that cannot be written is the notification
        // channel's problem rather than a reason to un-credit a business that has
        // been paid.
        $this->notifier->walletCredited($transaction, $netKobo);

        return true;
    }

    /**
     * Mature every pending credit whose settlement window has closed — and which
     * the provider has actually funded.
     *
     * Idempotent by `promoted_at`, which is stamped in the same transaction that
     * moves the money — so a run interrupted halfway moves some sales and leaves
     * the rest for the next run, rather than moving any of them twice.
     *
     * A promotion is a reclassification, never a creation: the business's total is
     * identical before and after. That is what makes it safe for this to run as
     * often as it likes.
     *
     * ## The budget, and why the window is no longer the gate
     *
     * `$promoteBudgetKobo` is what the provider says it holds, less everything
     * already spoken for — see {@see PayoutSweepService::promotionGate()}. Passing
     * it is what allows the settlement window in config to be a floor of nearly
     * nothing: the question "has the provider released this money?" stops being
     * answered by a guess about a window and starts being answered by the provider's
     * own balance. It can only ever make a run mature **less**, never more.
     *
     * When the budget is null the gate did not run, and `$floor` is the reason:
     * an unmeasurable provider falls back to `custody.default_hours` rather than to
     * no gate at all, so a balance we could not read degrades the run to the
     * conservative path instead of to an instant payout.
     *
     * @param  int|null  $promoteBudgetKobo  what this run may mature, or null for no cap
     * @param  CarbonInterface|null  $floor  only mature sales paid before this, when the balance could not be read
     * @return array{matured: int, pending: int, uncredited: int, budget_limited: int}
     */
    public function promoteSettled(?int $promoteBudgetKobo = null, ?CarbonInterface $floor = null): array
    {
        $counts = ['matured' => 0, 'pending' => 0, 'uncredited' => 0, 'budget_limited' => 0];

        // First, sales that settled while we could not price them. A provider fee
        // that arrived late is the normal case for a POS leg, and without this pass
        // the sale would sit uncredited forever with nothing reporting it.
        $counts['uncredited'] = $this->creditAwaitingSales();

        $due = $this->dueQuery($floor)
            ->orderBy('id')
            ->limit((int) config('custody.promote_batch', 500))
            ->get();

        [$fitted, $counts['budget_limited']] = $this->fitToBudget($due, $promoteBudgetKobo);

        foreach ($fitted as $transaction) {
            $netKobo = $transaction->netKobo();

            if ($netKobo === null || $netKobo <= 0) {
                continue;
            }

            $business = $transaction->business;

            if (! $business instanceof Business) {
                continue;
            }

            // Claimed before it is acted on, so two workers cannot both promote the
            // same sale. The second one finds the row already stamped and does
            // nothing — the same claim-then-act shape the payout table uses.
            $claimed = Transaction::query()
                ->whereKey($transaction->id)
                ->whereNull('promoted_at')
                ->update(['promoted_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            $business->promoteToAvailable($netKobo);
            $counts['matured']++;
        }

        $counts['pending'] = $this->promotableQuery()->count();

        return $counts;
    }

    /**
     * What the next promote run would move, without moving any of it.
     *
     * The counts come from the same predicate and the same budget fit the real run
     * uses, so they cannot drift from it — including the fit, which is the whole
     * point of reporting this: when the provider has not funded everything due, the
     * number that matters is not "how much is due" but "how much of it can actually
     * be released", and a dry run that printed the first would make every budgeted
     * run look like a bug.
     *
     * The naira figures are read from a single batch — the same working set the run
     * uses — because pricing a sale means running it through the fee arithmetic, and
     * a dry run is a diagnostic rather than a report anyone should reconcile against.
     *
     * @return array{matured: int, matured_kobo: int, budget_limited: int, uncredited: int, uncredited_kobo: int, settling: int, settling_kobo: int}
     */
    public function pendingPromotion(?int $promoteBudgetKobo = null, ?CarbonInterface $floor = null): array
    {
        $now = now();
        $batch = (int) config('custody.promote_batch', 500);

        [$fitted, $leftBehind] = $this->fitToBudget(
            $this->dueQuery($floor)->orderBy('id')->limit($batch)->get(),
            $promoteBudgetKobo,
        );

        $settling = $this->promotableQuery()->where('settle_at', '>', $now);
        $uncredited = $this->awaitingFeeQuery();

        return [
            'matured' => $fitted->count(),
            'matured_kobo' => $this->sumNet($fitted),
            'budget_limited' => $leftBehind,
            'uncredited' => (clone $uncredited)->count(),
            'uncredited_kobo' => $this->sumNet((clone $uncredited)->limit($batch)->get()),
            'settling' => (clone $settling)->count(),
            'settling_kobo' => $this->sumNet((clone $settling)->limit($batch)->get()),
        ];
    }

    /**
     * Everything the run may consider: settled, unpromoted, and old enough.
     *
     * `settle_at` is the sale's own window; `$floor` is the run's, applied only when
     * the provider's balance could not be read. Two different answers to "how long
     * do we wait", and the stricter of the two always wins because both are `<=`
     * conditions on the same query.
     *
     * @return Builder<Transaction>
     */
    private function dueQuery(?CarbonInterface $floor): Builder
    {
        $query = $this->promotableQuery()->where('settle_at', '<=', now());

        if ($floor !== null) {
            $query->where('paid_at', '<=', $floor);
        }

        return $query;
    }

    /**
     * The sales this run may mature, and how many the provider has not funded.
     *
     * **First in, first out, and the walk stops rather than skips.** A sale too big
     * for what is left of the budget blocks everything behind it, even a smaller,
     * newer sale that would have fitted. Reaching past it would mean a business's
     * older sale waiting behind its newer one, and on a business that trades
     * steadily in mixed sizes that wait would grow without bound — the queue would
     * starve from the front. Blocking is self-correcting: the next run reads the
     * provider again, and the money arrives.
     *
     * Sales priced at zero or less are skipped rather than blocking, because there
     * is nothing to fit and nothing to wait for.
     *
     * @param  Collection<int, Transaction>  $due
     * @return array{0: Collection<int, Transaction>, 1: int} what fits, and how many are left behind by the budget
     */
    private function fitToBudget(Collection $due, ?int $budget): array
    {
        if ($budget === null) {
            return [$due, 0];
        }

        $fitted = [];
        $remaining = $budget;

        foreach ($due as $index => $transaction) {
            $netKobo = (int) ($transaction->netKobo() ?? 0);

            if ($netKobo <= 0) {
                continue;
            }

            if ($netKobo > $remaining) {
                $leftBehind = $due->slice($index)
                    ->filter(fn (Transaction $candidate) => (int) ($candidate->netKobo() ?? 0) > 0)
                    ->count();

                return [collect($fitted), $leftBehind];
            }

            $remaining -= $netKobo;
            $fitted[] = $transaction;
        }

        return [collect($fitted), 0];
    }

    /**
     * Sales the wallet has credited and not yet made payable.
     *
     * One predicate, shared by the run that promotes them and the dry run that
     * reports them, because a dry run whose numbers come from a second, similar
     * query is a dry run that eventually lies.
     *
     * **Not filtered on `custody_enabled`, and that is the point.** The flag
     * decides how a business is *charged*; it says nothing about money already
     * collected into Storify's balance, which is what every row here is. A
     * business rolled back with a credit inside its settlement window was
     * previously dropped by this query — so the credit never matured, the sweep
     * never saw a balance, and the money sat in Storify's account with nobody
     * owed it in any table anything reads. `settle_at` being set is the whole
     * evidence needed: only {@see self::recordCollectedSale()} stamps it, and only
     * for a sale the platform itself collected.
     *
     * @return Builder<Transaction>
     */
    private function promotableQuery(): Builder
    {
        return Transaction::query()
            ->whereNotNull('settle_at')
            ->whereNull('promoted_at')
            // A credited sale that has since been refunded or charged back is no
            // longer currency the business is owed, so it must not be matured
            // into a bucket the sweep will pay out of. `unwound_at` is the only
            // marker that can answer this: the credit itself happened, so
            // `settle_at` is set, and `promoted_at` is still null because it was
            // taken back before it matured.
            ->whereNull('unwound_at');
    }

    /**
     * Sales that settled while the provider had not yet said what it charged.
     *
     * Also unfiltered on `custody_enabled`, for the same reason and with the same
     * failure behind it: these are sales Storify collected — `payment_mode` is
     * read from the row, not from the business — and a rollback in the window
     * between settlement and the provider's fee arriving would otherwise leave
     * them uncredited forever.
     *
     * @return Builder<Transaction>
     */
    private function awaitingFeeQuery(): Builder
    {
        return Transaction::query()
            ->where('metadata->payment_mode', 'platform_managed')
            ->whereNull('settle_at')
            ->whereNotNull('paid_at')
            ->whereIn('status', ['confirmed', 'paid'])
            // A sale refunded or charged back before its provider fee ever
            // arrived. Without this it matches again — it was never credited, so
            // it still has no `settle_at` — and the catch-up pass would hand the
            // business money for a sale the customer has already had back.
            ->whereNull('unwound_at');
    }

    /**
     * The sales that settled with no provider fee on them — the half that
     * {@see creditAwaitingSales()} cannot reach.
     *
     * `creditAwaitingSales()` selects `whereNotNull('fee_kobo')`, which is right:
     * it credits and nothing else. This is the other half of the same predicate —
     * `whereNull('fee_kobo')` — exposed so the one command that can close the gap
     * asks the same question this service does rather than rebuilding it and
     * drifting. A sale is here because a writer refused to price it, and it stays
     * here until something reads the provider's charge and writes the fee.
     *
     * @return Builder<Transaction>
     */
    public function salesAwaitingFee(): Builder
    {
        return $this->awaitingFeeQuery()->whereNull('fee_kobo');
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function sumNet(Collection $transactions): int
    {
        return (int) $transactions->sum(fn (Transaction $transaction) => (int) ($transaction->netKobo() ?? 0));
    }

    /**
     * Credit sales that settled before their provider fee was known.
     *
     * The catch-up for {@see recordCollectedSale()}'s deliberate refusal to guess.
     * A business's own wallet is the *only* thing that can turn a wrong number into
     * a support ticket, so an unpriced sale waits rather than being approximated —
     * and something has to come back for it.
     *
     * @return int how many are still unpriced after this pass
     */
    private function creditAwaitingSales(): int
    {
        $awaiting = $this->awaitingFeeQuery()
            ->whereNotNull('fee_kobo')
            ->orderBy('id')
            ->limit((int) config('custody.promote_batch', 500))
            ->get();

        foreach ($awaiting as $transaction) {
            $this->recordCollectedSale($transaction);
        }

        return $this->awaitingFeeQuery()->count();
    }

    /**
     * Take a payout's money out of the payable bucket, at the moment it is asked for.
     *
     * Debited at **reservation**, not at confirmation. The window between asking a
     * provider to send money and hearing that it sent it is exactly when a
     * concurrent sweep would otherwise reserve the same money a second time.
     *
     * `$allowNegative` stays false: a payout must never overdraw. If there is not
     * enough settled money, the right answer is to pay less or wait — never to
     * invent the difference. A refund is the one operation allowed to overdraw,
     * and it does not come through here.
     *
     * @throws \Exception when the business cannot cover it
     */
    public function releaseForPayout(Business $business, int $amountKobo): void
    {
        $business->debitAvailable($amountKobo);
    }

    /**
     * Give back a reservation for a payout that did not leave.
     *
     * Two cases, one operation: the provider refused the transfer, or it landed
     * and was reversed back to us. In both the business does not have the money
     * and Storify does, so the wallet owes it again — and the total is what it was
     * before the payout, which is what makes paying it again correct rather than
     * double.
     *
     * Straight to `available`, because the money had already settled: returning it
     * to `pending` would make the business wait a second settlement window for a
     * transfer that was declined on the spot.
     *
     * It also has to work when the business owes *us* — a refund taken after a
     * payout leaves `available` negative, and a later failed payout on top of that
     * has to be able to repair it rather than be refused. That is why this goes
     * through {@see Business::creditAvailable()}, which alone among the wallet
     * mutators does not refuse to run below zero.
     *
     * **Never call this on an unknown outcome.** A timeout is not a refusal: the
     * provider may have moved the money, and restoring here is precisely how the
     * same debt gets paid twice. {@see TransferOutcome::isRefused()}
     * is the gate.
     */
    public function restoreRefusedPayout(Business $business, int $amountKobo): void
    {
        $business->creditAvailable($amountKobo);
    }

    /**
     * A collected sale came back, so both halves of what it did are undone.
     *
     * The wallet and the journal are unwound together, because they are the two
     * halves of one fact — Storify was holding this money for the business, and
     * now it is not. Doing one without the other is the failure this method
     * exists to make impossible: a wallet debited with the ledger still crediting
     * the business is a liability the books claim and the business does not, and
     * it would be found only by a payout the journal cannot explain.
     *
     * **Called for a refund and for a chargeback alike.** On our books they are
     * the same movement — the gross goes back to the customer and we stop holding
     * the business's share — and the caller has already made the only decision
     * this method cannot make, which is whether the money is *certainly* gone.
     * `$reference` says whose decision it was and lands in the journal, so an
     * accountant reading it can tell a refund we chose from a chargeback we did
     * not.
     *
     * ## Claimed, then acted on
     *
     * `unwound_at` is stamped by condition and the affected-row count is the
     * answer to "is this mine to do" — the same shape the credit and the promote
     * use. Three ways to reach here twice on one sale, and all three are ordinary:
     * a refund retried after a provider timeout, a `dispute.updated` redelivered,
     * and a chargeback that arrives after we have already refunded the customer
     * ourselves. Only the first of each pair may move money.
     *
     * @return bool whether this call is the one that unwound it
     */
    public function unwindCollectedSale(Transaction $transaction, string $reference, ?int $userId = null): bool
    {
        // Read before the claim, which changes nothing but is easier to reason
        // about as the state the row was in when we decided.
        $promoted = $transaction->promoted_at !== null;

        try {
            // Claim and move together. A wallet that refuses the debit must not
            // leave `unwound_at` behind, or the retry would find the claim taken
            // and return without ever having taken the money back — the debt
            // recorded as settled by nothing but a timestamp.
            $unwound = DB::transaction(function () use ($transaction) {
                $claimed = Transaction::query()
                    ->whereKey($transaction->id)
                    ->whereNull('unwound_at')
                    ->update(['unwound_at' => now()]);

                if ($claimed === 0) {
                    return false;
                }

                // A sale that was never credited has no wallet movement to
                // reverse — most often a refund of a sale whose provider fee had
                // not arrived, or of one the platform never collected at all. The
                // claim is still the right thing to have taken: it is what stops
                // the promote job crediting a sale the customer has already had
                // refunded.
                if ($transaction->settle_at !== null) {
                    $this->reverseCollectedSale($transaction);
                }

                return true;
            });
        } catch (\Throwable $e) {
            // Logged rather than thrown: by the time this runs the provider has
            // been paid back and the sale is marked refunded, so a failure here
            // is a books-and-wallet disagreement for somebody to look at, not a
            // refund to refuse.
            Log::error('custody.collection.unwind_failed', [
                'transaction_id' => $transaction->id,
                'business_id' => $transaction->business_id,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $unwound) {
            return false;
        }

        // Wrapped, so a ledger fault cannot un-do a wallet movement that is
        // already recorded: a business debited with no journal entry behind it is
        // reconcilable against the transaction row, and one credited twice is not.
        $this->ledger->safe(fn () => $this->ledger->postCustodyCollectionReturned(
            $transaction,
            $reference,
            'custody_return:txn:'.$transaction->id,
            $userId,
        ));

        Log::warning('custody.collection.returned', [
            'transaction_id' => $transaction->id,
            'business_id' => $transaction->business_id,
            'reference' => $reference,
            'promoted' => $promoted,
            'net_kobo' => $transaction->netKobo(),
        ]);

        return true;
    }

    public function reverseCollectedSale(Transaction $transaction): bool
    {
        $business = $transaction->business;

        if (! $business instanceof Business || $transaction->settle_at === null) {
            // Nothing was ever credited, so there is nothing to take back. This is
            // the ordinary case for a business Storify never collected for.
            return false;
        }

        $netKobo = $transaction->netKobo();

        if ($netKobo === null || $netKobo <= 0) {
            return false;
        }

        // Order matters: a sale that was paid out has `promoted_at` set, and one
        // that was not does not. Reading the marker is what keeps the caller from
        // having to know which bucket the money is sitting in.
        if ($transaction->promoted_at === null) {
            $business->debitPending($netKobo);
        } else {
            $business->debitAvailable($netKobo, allowNegative: true);
        }

        // **Deliberately no allocation is touched here.** A refund has to stop this
        // sale being paid out again, and it is stopped by `unwound_at`, which the
        // allocation walk excludes on — not by releasing the claim a payout
        // already holds on it. Both alternatives are worse than doing nothing:
        //
        // - Releasing on a payout that has *left* would break the one invariant
        //   the allocations exist for — a payout's rows summing to its amount — and
        //   would say a payout did not cover a sale it did cover. The money did
        //   leave; the claw-back is the wallet going negative, which is recorded
        //   three lines above.
        // - Releasing on a payout that has *not* left means rewriting its amount
        //   and re-reserving the difference, and that races the send: `dispatch()`
        //   reads the amount off the row and calls the provider, so a shrink landing
        //   between the two would have the provider send money the wallet no longer
        //   holds.
        //
        // And it changes no money either way: a refunded sale is debited here
        // whether or not it was already allocated.
        return true;
    }
}
