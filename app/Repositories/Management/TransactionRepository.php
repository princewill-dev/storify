<?php

namespace App\Repositories\Management;

use App\Enums\TransactionStatus;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money\Naira;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WS-18 — query building for the transactions API.
 *
 * Scoping, filters and eager loads live here so the list, the CSV export, the
 * store filter options and the workflow's per-row store lookup all share one
 * definition. Reads and query building only: transaction boundaries and
 * abort() calls belong to services and controllers, not to this layer.
 */
class TransactionRepository
{
    /**
     * @var array<int, string>
     */
    public const LIST_RELATIONS = [
        'order:id,order_number,store_id,customer_id,meta',
        'order.store:id,name,store_id',
        'order.customer:id,first_name,last_name,email,phone',
        'invoice:id,invoice_number,store_id,recipient_name',
        'invoice.store:id,name,store_id',
        'paymentMethod',
        // The settlement column on a list row — "which payout delivered this sale".
        // Eager-loaded here rather than left to the resource, which would ask the
        // same question once per row.
        'payoutAllocations.payout:id,reference,status,amount_kobo,paid_at',
    ];

    /**
     * @var array<int, string>
     */
    public const DETAIL_RELATIONS = [
        'order.store',
        'order.customer',
        'order.items',
        'order.staff',
        'invoice.store',
        'paymentMethod',
        'storeBank',
        'payoutAllocations.payout',
    ];

    /**
     * The list/export query: tenant scoping, legacy filters, eager loads and
     * the inherited newest-first ordering.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listQuery(User $user, array $filters): Builder
    {
        return $this->applyFilters($this->scopedQuery($user), $filters)
            ->with(self::LIST_RELATIONS)
            ->latest();
    }

    /**
     * The money behind the rows the list is showing, for the totals bar.
     *
     * Scoped and filtered exactly like `listQuery()`, minus the paging, and
     * narrowed to settled rows: a pending payment is a claim on money, not money,
     * and adding it to a figure labelled "received" would count a customer's
     * unpaid invoice as income.
     *
     * Summed in kobo in the database rather than in PHP over the page, so the
     * figures describe the whole filtered set and not the twenty rows on screen.
     *
     * `net` is gross minus fees, **never** SUM(net_kobo). The column is null on
     * every row whose provider fee is not known yet, and summing it would quietly
     * drop those rows' entire amounts — turning an incomplete figure into a
     * smaller-looking one, which is the failure this whole change exists to
     * remove. Computing it from the sums instead keeps those rows counted.
     *
     * Reads the commission from its column, which the backfill fills. Until that
     * has run, totals understate by the commission on sales settled before the
     * column existed — see the deploy order in the backfill command.
     *
     * @param  array<string, mixed>  $filters
     * @return array{amount: float, processing_fee: float, net: float}
     */
    public function totals(User $user, array $filters): array
    {
        $query = $this->applyFilters($this->scopedQuery($user), $filters)
            ->where('status', TransactionStatus::CONFIRMED->value);

        $sums = (clone $query)
            ->selectRaw('COALESCE(SUM(amount * 100), 0) as gross_kobo')
            ->selectRaw('COALESCE(SUM(fee_kobo), 0) as provider_fee_kobo')
            ->selectRaw('COALESCE(SUM(platform_fee_kobo), 0) as commission_kobo')
            ->first();

        $grossKobo = (int) round((float) $sums->gross_kobo);
        $processingFeeKobo = (int) round((float) $sums->provider_fee_kobo)
            + (int) round((float) $sums->commission_kobo);

        return [
            'amount' => Naira::floatFromKobo($grossKobo),
            'processing_fee' => Naira::floatFromKobo($processingFeeKobo),
            'net' => Naira::floatFromKobo(max(0, $grossKobo - $processingFeeKobo)),
        ];
    }

    /**
     * Pending payments visible to the user — the number the sidebar badge
     * renders.
     */
    public function pendingCount(User $user): int
    {
        return $this->scopedQuery($user)
            ->where('status', TransactionStatus::PENDING->value)
            ->count();
    }

    /**
     * Store filter options, scoped exactly like the rows: a store-assigned
     * staff member must not be offered a store their list can never return.
     *
     * @return Collection<int, Store>
     */
    public function storeFilterOptions(User $user): Collection
    {
        $query = $user->isStaff()
            ? Store::query()->whereIn('id', $this->staffStoreIds($user))
            : $user->accessibleStores();

        return $query
            ->where('status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get();
    }

    /**
     * Stores a staff member may see transactions for.
     *
     * The legacy `isRestrictedStaff()` flag keyed on the transactions-view
     * permission — the very permission that guards these routes — so the
     * store scoping it implied could never engage. An explicit store
     * assignment is the reachable (and correct) definition of "restricted",
     * so it wins over the permission heuristic; staff with no assignments
     * fall back to their accessible stores.
     *
     * @return Collection<int, int>
     */
    public function staffStoreIds(User $user): Collection
    {
        if ($user->assignedStores()->exists()) {
            return $user->assignedStores()->pluck('stores.id')->map(fn ($id) => (int) $id);
        }

        return $user->accessibleStoreIds()->map(fn ($id) => (int) $id);
    }

    /**
     * The store a transaction's money belongs to: its order's store, or its
     * invoice's store.
     */
    public function storeFor(Transaction $transaction): ?Store
    {
        return $transaction->order?->store ?? $transaction->invoice?->store;
    }

    /**
     * Eager-load the detail read model onto a transaction for the show/action
     * responses.
     */
    public function loadDetail(Transaction $transaction): Transaction
    {
        return $transaction->load(self::DETAIL_RELATIONS);
    }

    /**
     * The rows this API is about: the business's own trade.
     *
     * Two narrowings, and the second is the one worth explaining.
     *
     * **The business**, because a transaction carries a `business_id` and nothing
     * here may cross it.
     *
     * **Trade, not platform billing.** `transactions` holds two kinds of row and
     * they are not the same money. A sale is one: a store charged a customer, and
     * the business is owed the proceeds. A subscription charge is the other — the
     * business paying *Storify* — and it is written by
     * `App\Repositories\Subscription\SubscriptionPaymentRepository::createPendingTransaction()`,
     * which copies the `payments` row's own reference into the transaction. Listing
     * the two together put the platform's bill to the business in the middle of its
     * sales, under a "Confirmed" figure that had quietly counted it, and it left a
     * phantom pending payment in the sidebar badge every time somebody opened a
     * checkout they did not finish.
     *
     * The marker is `metadata.payment_id`, the id of the `payments` row this
     * transaction is the ledger echo of — set by that one writer, and set by
     * nothing else in the codebase. Two alternatives were considered and both are
     * worse:
     *
     * - *Has an order or an invoice.* Structural and tempting, and wrong: a
     *   transaction without either is a shape the schema permits for reasons that
     *   predate platform billing, so the test would hide real sales — silently,
     *   and out of the totals with them.
     * - *`metadata.payment_type` in the subscription types.* True today, but it
     *   enumerates the billing kinds rather than the fact of being billed, so the
     *   next kind added leaks into the sales list until somebody remembers this
     *   line. `payment_id` needs no such list.
     *
     * Applied to the list, the totals, the CSV export and the pending badge alike,
     * because they are one question asked four ways: a badge counting rows the
     * list will not show is a badge that lies.
     *
     * Subscription charges are not hidden by any of this — they have a home of
     * their own at `/subscription/payments`, which reads the `payments` table they
     * were written from and can say more about them than a list of sales.
     */
    private function scopedQuery(User $user): Builder
    {
        $query = Transaction::query()
            ->where('business_id', $user->business_id)
            ->whereNull('metadata->payment_id');

        if ($user->isStaff()) {
            $storeIds = $this->staffStoreIds($user);

            $query->where(function ($scope) use ($storeIds) {
                $scope->whereHas('order', fn ($order) => $order->whereIn('store_id', $storeIds))
                    ->orWhereHas('invoice', fn ($invoice) => $invoice->whereIn('store_id', $storeIds));
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $reference = $filters['reference'] ?? $filters['q'] ?? null;

        return $query
            ->when($reference, fn ($q, $term) => $q->where('reference', 'like', '%'.$term.'%'))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->where(function ($scope) use ($storeId) {
                $scope->whereHas('order', fn ($order) => $order->where('store_id', $storeId))
                    ->orWhereHas('invoice', fn ($invoice) => $invoice->where('store_id', $storeId));
            }))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
    }
}
