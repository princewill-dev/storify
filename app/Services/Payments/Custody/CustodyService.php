<?php

namespace App\Services\Payments\Custody;

use App\Enums\ProviderFeeBearer;
use App\Models\Business;
use App\Models\Store;
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
        private readonly CustodyBucket $bucket,
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
     * **A sale whose provider fee is not yet known is not credited at all — unless
     * that fee is not the business's problem.** The business's share is
     * `gross − provider fee − commission`, and on a sale charged through the
     * business's own keys the middle term is not always there when the sale is
     * written: a provider that reports its own charge only when the charge settles
     * leaves the row unpriced for days. Crediting the commission alone would
     * understate the debt, and crediting the gross would overstate it by a fee that
     * is coming. Neither is recoverable by a later correction a business would ever
     * see, so those sales wait, and `custody:promote` reports how many are waiting.
     *
     * A sale **Storify** charged has no middle term — the provider's fee is our own
     * cost and comes out of our balance, not the business's — so the business's
     * share is known the moment the sale is paid, and this credits it there and
     * then. Which of the two a sale is, is read off the row's own
     * `provider_fee_bearer`, which is what decides whether the missing fee is a
     * reason to wait.
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
        // would replay on every attempt. Refuse instead.
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
        // The one place the bucket is decided. Derived once, set on the model so
        // the credit below reads what the row is about to hold rather than
        // deriving a second time, and recorded with the claim so everything that
        // moves this money afterwards — promote, refund, payout — reads it back
        // instead of asking again. Deriving it later would give a different answer
        // the moment the order changed store.
        $custodyStoreId = $this->bucket->storeIdFor($transaction, $business);
        $transaction->custody_store_id = $custodyStoreId;

        $credited = DB::transaction(function () use ($transaction, $business, $netKobo, $settleAt, $custodyStoreId) {
            $claimed = Transaction::query()
                ->whereKey($transaction->id)
                ->whereNull('settle_at')
                ->update(['settle_at' => $settleAt, 'custody_store_id' => $custodyStoreId]);

            if ($claimed === 0) {
                return false;
            }

            $this->bucket->creditPending($transaction, $business, $netKobo);

            return true;
        });

        if (! $credited) {
            return false;
        }

        // The write above went through the query builder, so the caller's
        // instance still says null. Bring it level — not saved again, just
        // correct for anything that reads it next — including the bucket, which
        // would otherwise re-derive a store the row has just recorded.
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

        // And, when the provider has already said what its charge was, our own side
        // of it. Both the business's share and the commission are the same either
        // way — this only decides whether that fee is recognised now or by the
        // sweep below, which is why it is not a condition on any figure the
        // business sees.
        //
        // Ordered *after* the collection entry on purpose: that one debits the full
        // gross to clearing, and recognising the fee first would take clearing below
        // zero for the length of one entry — a figure a reconciliation would find
        // and nobody could explain.
        //
        // The common case for a till leg is a fee we do not have yet, since the
        // provider reports it at settlement days later. The promote in
        // {@see promoteSettled()} is what posts it then. Both paths share the
        // entry's idempotency key, so whichever arrives first posts once and the
        // other is a no-op.
        if ($transaction->fee_kobo !== null && (int) $transaction->fee_kobo > 0) {
            $providerFeeKobo = (int) $transaction->fee_kobo;

            $this->ledger->safe(fn () => $this->ledger->postCustodyProviderFeeSettled($transaction, $providerFeeKobo));
        }

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
     * the rest for the next attempt, rather than moving any of them twice.
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

        // Then our own side of the provider's charge, for the sales the pass above
        // cannot reach — money the business is already owed, and a cost we have
        // already incurred. Not reported through the array below: nothing about a
        // business's balance changes, and a count in a run summary is a promise
        // somebody will have to keep. It is logged instead.
        $this->recogniseProviderFees();

        $due = $this->dueQuery($floor)
            ->orderBy('id')
            ->limit((int) config('custody.promote_batch', 500))
            ->get();

        [$counts['matured'], $counts['budget_limited']] = $this->mature($due, $promoteBudgetKobo);

        $counts['pending'] = $this->promotableQuery()->count();

        return $counts;
    }

    /**
     * Mature one business's due sales, and only that business's.
     *
     * The same predicate, the same budget and the same claim as
     * {@see self::promoteSettled()}, narrowed to a single business so that the
     * moment a sale is collected its money can become payable without waiting for
     * the promote run to come round. See {@see InstantPayoutService}.
     *
     * **The narrowing is the business, never the sale.** A single-sale version
     * would let a just-collected sale overtake an older one on the same business
     * that the provider has not funded yet, and the queue would starve from the
     * front — the contract {@see self::fitToBudget()} exists to keep. Walking the
     * business's own queue in order means the new sale matures after the backlog,
     * which is the same answer the run would give, only sooner.
     *
     * Not a second way to move money: it goes through the same claim
     * (`promoted_at` off null, taken by condition) and the same
     * {@see Business::promoteToAvailable()}, so a trigger and a run landing at the
     * same instant cannot both mature the same sale.
     *
     * @param  int|null  $promoteBudgetKobo  what may be matured, or null for no cap
     * @param  CarbonInterface|null  $floor  applied only when the provider's balance could not be read
     * @return int how many were matured
     */
    public function promoteForBusiness(Business $business, ?int $promoteBudgetKobo = null, ?CarbonInterface $floor = null): int
    {
        $due = $this->dueQuery($floor)
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->limit((int) config('custody.promote_batch', 500))
            ->get();

        [$matured] = $this->mature($due, $promoteBudgetKobo);

        return $matured;
    }

    /**
     * The one loop that moves a sale from `pending` to `available`.
     *
     * @param  Collection<int, Transaction>  $due  already ordered and already fitted
     * @return array{0: int, 1: int} matured, and how many the budget left behind
     */
    private function mature(Collection $due, ?int $promoteBudgetKobo): array
    {
        [$fitted, $leftBehind] = $this->fitToBudget($due, $promoteBudgetKobo);
        $matured = 0;

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

            // Through the bucket, so the store a sale was credited to is the
            // store it settles in. With the flag off this is the business, which
            // is what every promote did before.
            $this->bucket->promoteToAvailable($transaction, $business, $netKobo);
            $matured++;
        }

        return [$matured, $leftBehind];
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
     * starve from the front. Blocking is self-correcting: the next attempt reads
     * the provider again, and the money arrives.
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
     * previously dropped by this query — so the credit never matured, the send
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
            // into a bucket the send will pay out of. `unwound_at` is the only
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
     * Since the provider's fee became Storify's own cost, only a sale charged on
     * the business's **own** keys can be waiting here for its fee, and this set
     * drains to nothing as those age out. A sale Storify charged is credited
     * immediately and never appears — see {@see creditAwaitingSales()}, whose
     * predicate is the one that decides.
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
     * The collected sales whose provider fee we have not learned yet — settled or
     * not.
     *
     * This is the other half of {@see awaitingFeeQuery()}: that one plus
     * `whereNotNull('fee_kobo')` is what {@see creditAwaitingSales()} credits, and
     * this one with the fee still missing is what cannot be credited yet. But it
     * is deliberately **not** the same predicate minus a clause, because the
     * question it answers is a different one.
     *
     * `awaitingFeeQuery()` asks "which sales can the wallet not credit yet", and
     * so requires them uncredited. This asks "which sales cost us something we have
     * not written down", which has nothing to do with the wallet: a sale Storify
     * charged is credited the moment it is paid, days before the provider says
     * what its charge was, so it is never uncredited and would never be found
     * through that predicate — while its fee is exactly the expense this set is
     * about.
     *
     * Read by the one command that can close that gap (`custody:repair-sale-fees`),
     * so the question is asked here rather than rebuilt there and left to drift.
     *
     * @return Builder<Transaction>
     */
    public function managedSalesWithNoFee(): Builder
    {
        return Transaction::query()
            ->where('metadata->payment_mode', 'platform_managed')
            ->whereNull('fee_kobo')
            ->whereNotNull('paid_at')
            ->whereIn('status', ['confirmed', 'paid'])
            ->whereNull('unwound_at');
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
     * A sale whose fee never needs to be known before it is credited — one Storify
     * charged, where the fee is our own cost — is credited on the spot and never
     * reaches this pass. See the predicate below.
     *
     * @return int how many are still unpriced after this pass
     */
    private function creditAwaitingSales(): int
    {
        $awaiting = $this->awaitingFeeQuery()
            // The old rule is welded into this predicate, and it has to be said out
            // loud what is left of it: a fee is required to credit a sale only when
            // the business's share depends on one. On a sale Storify charged it does
            // not, so waiting for it would strand money the business is already owed
            // — for days, on a till leg — for a number that is not part of the sum.
            ->where(fn ($query) => $query
                ->where('provider_fee_bearer', ProviderFeeBearer::PLATFORM->value)
                ->orWhereNotNull('fee_kobo'))
            ->orderBy('id')
            ->limit((int) config('custody.promote_batch', 500))
            ->get();

        foreach ($awaiting as $transaction) {
            $this->recordCollectedSale($transaction);
        }

        return $this->awaitingFeeQuery()->count();
    }

    /**
     * Our own expense, once the provider says what it charged.
     *
     * A sale Storify charged is credited the moment it is paid — its fee is not
     * part of what the business is owed — so the fee arrives afterwards, on its own,
     * with nothing about the business waiting on it. This is what recognises it
     * when it does; {@see recordCollectedSale()} handles the case where the provider
     * had already reported it by the time the sale was credited.
     *
     * Both go through one entry under one idempotency key
     * (`managed:provider_fee:txn:{id}`), so the two cannot double-post and a
     * replayed run posts nothing.
     *
     * Only the platform's own books are touched. A sale the business paid the
     * provider on has its fee inside the collection entry, where it already was,
     * and nothing here needs to find it.
     *
     * ## Why it is found by the entry's key and not by a marker column
     *
     * `provider_fee_recognised_at` on the transaction would be a second record of
     * something the journal already states, and the two would eventually disagree —
     * a row stamped as recognised with no entry, or an entry with no stamp. The key
     * that makes the posting idempotent is the same key that answers "has it been
     * posted", so the journal is asked directly. {@see LedgerReportService::integrity()}
     * finds unposted transactions the same way.
     */
    private function recogniseProviderFees(): int
    {
        $candidates = Transaction::query()
            ->where('provider_fee_bearer', ProviderFeeBearer::PLATFORM->value)
            ->whereNotNull('fee_kobo')
            ->where('fee_kobo', '>', 0)
            // Only a sale the wallet actually collected: that is what put the full
            // gross into clearing, and the entry this posts credits clearing back
            // out of it. Without the guard it would recognise a cost against an
            // account that was never debited for the sale.
            ->whereNotNull('settle_at')
            // A refunded sale's fee entry was reversed with its collection
            // ({@see LedgerPostingService::postCustodyCollectionReturned()}). The
            // reversal is a new entry under its own key rather than a deletion, so
            // the check below would still find the original and skip — this says so
            // directly rather than leaving it to an accident of key naming.
            ->whereNull('unwound_at')
            // The same currency guard the wallet and the collection entry apply.
            // A converted sale's figures are the store's own, and clearing was
            // never debited in the custody currency for it.
            ->whereRaw('UPPER(currency) = ?', [strtoupper((string) config('custody.currency', 'NGN'))])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('journal_entries')
                    ->whereColumn(
                        'journal_entries.idempotency_key',
                        DB::raw("CONCAT('managed:provider_fee:txn:', transactions.id)"),
                    );
            })
            ->orderBy('id')
            ->limit((int) config('custody.promote_batch', 500))
            ->get();

        foreach ($candidates as $transaction) {
            $this->ledger->safe(fn () => $this->ledger->postCustodyProviderFeeSettled(
                $transaction,
                (int) $transaction->fee_kobo,
            ));
        }

        if ($candidates->isNotEmpty()) {
            Log::info('custody.provider_fees_recognised', ['transactions' => $candidates->count()]);
        }

        return $candidates->count();
    }

    /**
     * Take a payout's money out of the payable bucket, at the moment it is asked for.
     *
     * Debited at **reservation**, not at confirmation. The window between asking a
     * provider to send money and hearing that it sent it is exactly when a
     * concurrent send would otherwise reserve the same money a second time.
     *
     * `$allowNegative` stays false: a payout must never overdraw. If there is not
     * enough settled money, the right answer is to pay less or wait — never to
     * invent the difference. A refund is the one operation allowed to overdraw,
     * and it does not come through here.
     *
     * @throws \Exception when the business cannot cover it
     */
    public function releaseForPayout(Business $business, int $amountKobo, ?Collection $plan = null): void
    {
        $plan ??= collect();

        $debited = 0;

        // Grouped by the *bucket*, not by `store_id`. An allocation's `store_id`
        // is attribution — which store's takings this was — and with per-store
        // wallets off it names a store whose own wallet is empty, because the
        // money is on the business. Grouping by it debited the wrong wallet and
        // the reservation was refused for insufficient funds.
        foreach ($plan->groupBy(fn (array $row) => $row['bucket'] ?? '') as $storeId => $rows) {
            $share = (int) collect($rows)->sum('amount_kobo');

            if ($share <= 0) {
                continue;
            }

            $scope = $storeId === ''
                ? $business
                : (Store::query()->find((int) $storeId) ?? $business);

            $scope->debitAvailable($share);
            $debited += $share;
        }

        // Whatever the walk could not attribute to a sale. Unattributable money
        // lives in the business bucket, and with per-store wallets off every row
        // grouped above is the business's too — so this remainder plus those
        // shares is exactly the single debit this used to be.
        $remainder = $amountKobo - $debited;

        if ($remainder > 0) {
            $business->debitAvailable($remainder);
        }
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
            $this->bucket->debitPending($transaction, $business, $netKobo);
        } else {
            // The same bucket the credit went into, which is the point of
            // recording it: a refund on one store drives that store's balance
            // negative and leaves every other store alone.
            $this->bucket->debitAvailable($transaction, $business, $netKobo, allowNegative: true);
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
