<?php

namespace App\Services\Payments\Custody;

use App\Models\Business;
use App\Models\Payout;
use App\Models\PayoutAllocation;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Works out which sales a payout is paying, and keeps that answer true.
 *
 * A payout sends one amount for a whole business, so the money that leaves has to
 * be attributed back to the sales it settled — otherwise "which sales did this
 * ₦47,500 cover" has no answer anywhere in the system, and neither does the
 * reverse question a business will actually ask: "which payout paid me for this
 * order". See {@see PayoutAllocation} for why a pointer on either table could not
 * do it.
 *
 * ## The walk, and why it is FIFO and partial
 *
 * The sales are taken oldest first, and the last one taken may be **split**: a
 * payout that pays ₦50 out of a ₦150 sale records 50 against it, and the remaining
 * 100 stays available for the next payout. That is what makes the amounts add up
 * exactly — one payout's allocations sum to its `amount_kobo` to the kobo, always —
 * and it is why `amount_kobo` is a column on the join rather than an assumption
 * that a sale belongs to one payout.
 *
 * FIFO rather than "fit as many as possible": the alternative is a walk that skips
 * an older sale it cannot afford in full to reach a smaller newer one, which pays
 * the newest sale first and leaves the oldest waiting indefinitely. It also makes
 * the order of the allocation rows mean something — they are the order money went
 * out.
 *
 * ## The cap is the cap
 *
 * `plan()` never plans more than the caller's cap, which the sweep sets to the
 * business's payable balance. The remainder walk and the balance agree in the
 * ordinary case, but not when a refund taken after a payout has left the wallet
 * negative: the sales still show more unallocated money than the wallet says is
 * owed, and the wallet is the one that is right. So the walk stops at the cap and
 * the *sale* keeps the rest, which is exactly the case that made a sale-level
 * pointer insufficient.
 *
 * ## One writer, and the plan is computed once
 *
 * Nothing else writes these rows. The plan is built before the payout row is
 * written and committed inside the same transaction as the reservation, so the
 * amount on the payout and the rows that explain it are written together or not
 * at all — a payout whose allocations came up short would be a payout the books
 * disagree with.
 */
final class PayoutAllocationService
{
    /**
     * How many of a business's sales one pass loads.
     *
     * The walk stops as soon as it has covered the cap, so this is a working-set
     * bound on the first pass rather than on the walk: a business whose oldest
     * sales already cover the payout loads one chunk, and only a business whose
     * older sales are already spoken for reads on.
     */
    private const WALK_CHUNK = 200;

    /**
     * "Sales from every bucket" — the default, and what a business-level payout
     * has always meant.
     *
     * A string rather than a nullable int because `null` already has a job here:
     * it names the *business* bucket, which is not the same as naming no bucket
     * at all.
     */
    public const ANY_BUCKET = 'any';

    /**
     * The sales a payout of at most `$capKobo` would be drawn from.
     *
     * Returns the plan rather than writing anything: the sweep needs the total
     * *before* it writes the payout row, because the row's amount has to be the
     * amount the walk actually found — see {@see total()}.
     *
     * `$excludeStoreIds` is where a store-scoped hold enters. It is applied here,
     * in the one place sales are chosen, rather than by subtracting a share from
     * the total afterwards: a store that is held simply does not contribute, and
     * the amount sent is the sum of what did. That is what makes it correct when
     * the wallet is worth less than the sum of its sales — the held sales absorb
     * none of the shortfall, and a payment can never exceed what is in the wallet.
     *
     * ## `$bucket` is what makes per-store payout possible
     *
     * A sale's bucket is `custody_store_id` — decided once at credit and recorded,
     * never re-derived, so these two cannot disagree about where money is. Passing
     * a store id draws only from that store's takings; passing `null` draws only
     * from money no store accounts for; the default draws from all of it, which is
     * the business-level payout that existed before stores had wallets.
     *
     * Without this a store's payout would be capped by the business's whole wallet
     * and could be funded by another store's sales — money leaving an account that
     * never earned it, and the store that did earn it left short.
     *
     * @param  array<int, int>  $excludeStoreIds
     * @param  int|string|null  $bucket  A store id, `null` for the business bucket, or {@see ANY_BUCKET}.
     * @return Collection<int, array{transaction_id: int, store_id: int|null, amount_kobo: int}>
     */
    public function plan(
        Business $business,
        int $capKobo,
        array $excludeStoreIds = [],
        int|string|null $bucket = self::ANY_BUCKET,
    ): Collection {
        $plan = collect();

        if ($capKobo <= 0) {
            return $plan;
        }

        $claimed = PayoutAllocation::claimedForBusiness((int) $business->id);
        $remaining = $capKobo;

        Transaction::query()
            ->where('business_id', $business->id)
            // Matured, not merely credited: a sale still in `pending` is money the
            // provider has not released yet, and paying it out would be Storify
            // funding the difference.
            ->whereNotNull('promoted_at')
            // Refunded or charged back. Excluded here rather than by releasing the
            // allocations a payout already holds — see the note in
            // `CustodyService::reverseCollectedSale()`.
            ->whereNull('unwound_at')
            ->with(['order:id,store_id', 'invoice:id,store_id'])
            ->orderBy('id')
            // The bucket test, in SQL rather than after loading: a business with
            // thousands of sales and one store should not read the other stores'
            // rows to decide they are not its.
            ->when(
                $bucket !== self::ANY_BUCKET,
                fn ($query) => $query->where(
                    fn ($inner) => $bucket === null
                        ? $inner->whereNull('custody_store_id')
                        // A sale recorded against a store that has since been
                        // deleted keeps its id; the bucket still names it, and the
                        // sweep decides separately whether that store can be paid.
                        : $inner->where('custody_store_id', $bucket),
                ),
            )
            ->chunkById(self::WALK_CHUNK, function (Collection $sales) use (&$plan, &$remaining, $claimed, $excludeStoreIds) {
                foreach ($sales as $sale) {
                    if ($remaining <= 0) {
                        // Stop the chunking entirely, not just this chunk: the cap
                        // is covered, and reading further would only read sales
                        // this payout is not going to pay.
                        return false;
                    }

                    $free = $this->freeKobo($sale, (int) ($claimed[$sale->id] ?? 0));

                    if ($free <= 0) {
                        continue;
                    }

                    $storeId = $this->storeIdFor($sale);

                    if ($storeId !== null && in_array($storeId, $excludeStoreIds, true)) {
                        continue;
                    }

                    $take = min($free, $remaining);

                    $plan->push([
                        'transaction_id' => (int) $sale->id,
                        'store_id' => $storeId,
                        'amount_kobo' => $take,
                    ]);

                    $remaining -= $take;
                }
            });

        return $plan;
    }

    /**
     * What a plan adds up to — the amount the payout row should carry.
     *
     * @param  Collection<int, array{transaction_id: int, store_id: int|null, amount_kobo: int}>  $plan
     */
    public function total(Collection $plan): int
    {
        return (int) $plan->sum('amount_kobo');
    }

    /**
     * Write the plan's rows against a payout.
     *
     * Called inside the same transaction that reserves the payout and debits the
     * wallet, so the three cannot disagree. One insert rather than a row at a
     * time — the plan is already fully known, and a partial write would be the
     * failure this method exists to prevent.
     *
     * No conflict is possible on the unique index: the payout was just created, so
     * none of these sales can already be allocated to it.
     *
     * @param  Collection<int, array{transaction_id: int, store_id: int|null, amount_kobo: int}>  $plan
     */
    public function commit(Payout $payout, Collection $plan, string $currency): void
    {
        if ($plan->isEmpty()) {
            return;
        }

        $now = now();

        PayoutAllocation::query()->insert($plan->map(fn (array $line) => [
            'payout_id' => $payout->id,
            'transaction_id' => $line['transaction_id'],
            'business_id' => $payout->business_id,
            'store_id' => $line['store_id'],
            'amount_kobo' => $line['amount_kobo'],
            'currency' => $currency,
            'status' => PayoutAllocation::STATUS_RESERVED,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /**
     * The payout did not happen, so the sales it was going to pay are owed again.
     *
     * Released rather than deleted: the fact that this payout was meant to pay
     * these sales is worth keeping — it is why the next payout's rows exist — and
     * a released row no longer claims the money, so the walk can pick the sales up
     * again.
     */
    public function release(Payout $payout): int
    {
        $released = $this->transition($payout, [PayoutAllocation::STATUS_RESERVED], [
            'status' => PayoutAllocation::STATUS_RELEASED,
            'released_at' => now(),
        ]);

        $this->log($released, 'custody.allocation.released', $payout);

        return $released;
    }

    /**
     * The payout landed, so these sales' shares have left for good.
     *
     * Nothing about the wallet moves here — it moved at reservation. This is the
     * point at which the attribution stops being a plan and becomes a record.
     */
    public function settle(Payout $payout): int
    {
        $settled = $this->transition($payout, [PayoutAllocation::STATUS_RESERVED], [
            'status' => PayoutAllocation::STATUS_SETTLED,
            'settled_at' => now(),
        ]);

        $this->log($settled, 'custody.allocation.settled', $payout);

        return $settled;
    }

    /**
     * The payout landed and was then recalled, so the sales are owed again.
     *
     * `settled` is claimed as well as `reserved`, because a recall is the one
     * thing the provider tells us about *after* it has already told us the money
     * arrived. Leaving it out would strand the sales as paid when the money is
     * back in the wallet, and the business would never be paid for them again.
     */
    public function reverse(Payout $payout): int
    {
        $reversed = $this->transition(
            $payout,
            [PayoutAllocation::STATUS_RESERVED, PayoutAllocation::STATUS_SETTLED],
            [
                'status' => PayoutAllocation::STATUS_REVERSED,
                'released_at' => now(),
            ],
        );

        $this->log($reversed, 'custody.allocation.reversed', $payout);

        return $reversed;
    }

    /**
     * What a payout's allocations are currently worth, for a caller that wants to
     * know whether the attribution adds up.
     */
    public function claimedKobo(Payout $payout): int
    {
        return (int) PayoutAllocation::query()
            ->where('payout_id', $payout->id)
            ->whereIn('status', PayoutAllocation::CLAIMING)
            ->sum('amount_kobo');
    }

    /**
     * The store a sale belongs to, where one can be determined.
     *
     * `transactions` has no `store_id`: a sale is attributed through its order or
     * its invoice, and a managed till leg can have neither. Null means "not
     * attributable", which matters to exactly one caller — a store-scoped hold
     * cannot withhold a sale it cannot place in a store, and a sale nobody can
     * place is paid rather than held.
     */
    public function storeIdFor(Transaction $transaction): ?int
    {
        $storeId = $transaction->order?->store_id ?? $transaction->invoice?->store_id;

        return $storeId !== null ? (int) $storeId : null;
    }

    /**
     * How much of this sale is not already spoken for.
     *
     * `netKobo()` and not the `net_kobo` column: the column overstates a managed
     * sale by the commission, so allocating from it would claim money the business
     * was never owed. Nulls are treated as nothing to allocate — a sale whose
     * provider fee is unknown has no knowable net, and it has not been credited to
     * the wallet either, so it cannot be in this position.
     */
    private function freeKobo(Transaction $transaction, int $claimedKobo): int
    {
        return max(0, (int) ($transaction->netKobo() ?? 0) - $claimedKobo);
    }

    /**
     * Move every allocation on a payout out of the statuses given, and nothing else.
     *
     * By condition rather than by model, for the same reason every other transition
     * in this subsystem is: two of the three ways into a payout can be looking at
     * it at the same moment — a webhook and a read-back — and the affected-row
     * count is how each of them learns whether it was the one that acted.
     *
     * @param  array<int, string>  $from
     * @param  array<string, mixed>  $to
     */
    private function transition(Payout $payout, array $from, array $to): int
    {
        return PayoutAllocation::query()
            ->where('payout_id', $payout->id)
            ->whereIn('status', $from)
            ->update($to);
    }

    /**
     * Only when something moved, so the log stays a record of transitions rather
     * than of calls.
     */
    private function log(int $rows, string $event, Payout $payout): void
    {
        if ($rows === 0) {
            return;
        }

        Log::info($event, [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'business_id' => $payout->business_id,
            'allocations' => $rows,
        ]);
    }
}
