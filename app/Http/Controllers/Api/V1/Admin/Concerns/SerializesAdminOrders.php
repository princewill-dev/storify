<?php

namespace App\Http\Controllers\Api\V1\Admin\Concerns;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WS-5 — one serialization for the admin order list, the Shop4Me list and the
 * order detail screen.
 *
 * The main list and the Shop4Me queue render the same columns and cards, so
 * both controllers share these helpers instead of drifting into two divergent
 * copies. The payment badge is derived from transactions in one place: the
 * legacy main list worked that way, but the Shop4Me view compared enum objects
 * to strings so every row rendered "Unpaid" — that bug is not cloned, both
 * screens read `derivedPaymentStatus()` now.
 */
trait SerializesAdminOrders
{
    /**
     * Columns the lists may be sorted by. Legacy passed the raw `sort_by`
     * column straight into `orderBy`; anything outside this list falls back to
     * newest first instead of reaching the query builder.
     */
    protected const ORDER_SORTABLE = ['order_number', 'status', 'total', 'created_at'];

    /**
     * The payment-status facets both lists can filter on. `pending` and
     * `partial` are offered because the derived badge can return them (the
     * legacy dropdown silently could not match two of its own states).
     */
    protected const ORDER_PAYMENT_FILTERS = ['unpaid', 'pending', 'partial', 'paid', 'refunded', 'failed'];

    /**
     * Transaction states the derived badge treats as live. A cancelled-only
     * order (or no transactions at all) derives as unpaid.
     */
    protected const LIVE_TRANSACTION_STATUSES = [
        TransactionStatus::PENDING->value,
        TransactionStatus::PAID->value,
        TransactionStatus::CONFIRMED->value,
        TransactionStatus::REFUNDED->value,
        TransactionStatus::REFUND_PENDING->value,
    ];

    /**
     * @return array<string, mixed>
     */
    protected function orderListFilters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'store' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'payment_status' => ['nullable', Rule::in(self::ORDER_PAYMENT_FILTERS)],
            'source' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort_by' => ['nullable', Rule::in(self::ORDER_SORTABLE)],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            // `sort`/`direction` are the house list convention and ride the
            // same whitelist as the legacy-named `sort_by`/`sort_order`.
            'sort' => ['nullable', Rule::in(self::ORDER_SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyOrderFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $like = '%'.$term.'%';
                $q->where(fn ($inner) => $inner->where('order_number', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like)));
            })
            ->when($filters['store_id'] ?? null, fn (Builder $q, $storeId) => $q->where('store_id', $storeId))
            ->when($filters['store'] ?? null, fn (Builder $q, string $name) => $q->whereHas('store', fn ($s) => $s->where('name', 'like', '%'.$name.'%')))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['source'] ?? null, fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('created_at', '<=', $to));

        if (isset($filters['payment_status'])) {
            $this->applyPaymentStatusFilter($query, $filters['payment_status']);
        }
    }

    protected function applyOrderSort(Builder $query, ?string $sortBy, ?string $sortOrder): void
    {
        $column = in_array($sortBy, self::ORDER_SORTABLE, true) ? $sortBy : 'created_at';
        $direction = strtolower((string) $sortOrder) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($column, $direction)->orderByDesc('id');
    }

    /**
     * The list page every admin order screen renders: rows, paginator and the
     * stat-card block legacy showed above the table.
     *
     * @param  array<string, mixed>  $filters
     * @return array{orders: array<int, array<string, mixed>>, paginator: LengthAwarePaginator, stats: array<string, mixed>}
     */
    protected function paginateOrderIndex(Builder $base, array $filters): array
    {
        $query = clone $base;
        $this->applyOrderFilters($query, $filters);
        $this->applyOrderSort(
            $query,
            $filters['sort_by'] ?? $filters['sort'] ?? null,
            $filters['sort_order'] ?? $filters['direction'] ?? null,
        );

        $orders = $query
            ->with([
                'customer:id,first_name,last_name,email,phone',
                'store:id,name,store_id',
                // Loaded as a set so the derived badge costs no extra query
                // per row.
                'transactions:id,order_id,amount,status',
            ])
            ->withCount('items')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        // Stat cards describe the filtered slice but ignore the status and
        // payment facets themselves — filtering to one status must not zero
        // every other count on the row.
        $statsFilters = $filters;
        unset($statsFilters['status'], $statsFilters['payment_status']);

        $statsQuery = clone $base;
        $this->applyOrderFilters($statsQuery, $statsFilters);

        return [
            'orders' => $orders->getCollection()->map(fn (Order $order) => $this->orderSummary($order))->values()->all(),
            'paginator' => $orders,
            'stats' => $this->orderStats($statsQuery),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderStats(Builder $query): array
    {
        $counts = (clone $query)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = [];
        $total = 0;

        foreach (OrderStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($counts[$status->value] ?? 0);
            $total += $byStatus[$status->value];
        }

        $byPayment = [];

        foreach (self::ORDER_PAYMENT_FILTERS as $paymentStatus) {
            $paymentQuery = clone $query;
            $this->applyPaymentStatusFilter($paymentQuery, $paymentStatus);
            $byPayment[$paymentStatus] = $paymentQuery->count();
        }

        return [
            'total' => $total,
            'pending' => $byStatus[OrderStatus::PENDING->value],
            'processing' => $byStatus[OrderStatus::PROCESSING->value],
            // Revenue counts CONFIRMED transactions only — the working legacy
            // semantics the dashboard also sums.
            'revenue' => (float) (clone $query)
                ->whereHas('transactions', fn ($q) => $q->where('status', TransactionStatus::CONFIRMED->value))
                ->sum('total'),
            'by_status' => $byStatus,
            'by_payment' => $byPayment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderSummary(Order $order): array
    {
        $paymentStatus = $this->derivedPaymentStatus($order);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer' => $this->customerBlock($order),
            'store' => $order->store?->name,
            'store_id' => $order->store_id,
            'items_count' => $order->items_count !== null
                ? (int) $order->items_count
                : ($order->relationLoaded('items') ? $order->items->count() : $order->items()->count()),
            'source' => $order->source,
            'is_shop4me' => $order->isShop4me(),
            'is_pos' => $order->isPos(),
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'remaining' => (float) $order->remainingBalance(),
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'status_label' => $order->status_label,
            'payment_status' => $paymentStatus->value,
            'payment_status_label' => $paymentStatus->label(),
            'created_at' => $order->created_at?->toISOString(),
        ];
    }

    /**
     * Everything the legacy show page rendered: items + totals, customer,
     * delivery, every transaction, notes, the order's activity timeline and
     * the quick-info card (store, owner, dates).
     *
     * @return array<string, mixed>
     */
    protected function orderDetail(Order $order): array
    {
        $order->loadMissing([
            'items',
            'customer',
            'store.user',
            'store.business',
            'deliveryAddress',
            'deliveryRoute',
            'transactions.paymentMethod',
        ]);

        return [
            ...$this->orderSummary($order),
            'subtotal' => (float) $order->subtotal,
            'shipping_fee' => (float) $order->shipping_fee,
            'tax' => (float) $order->tax,
            'service_charge' => (float) ($order->service_charge_amount ?? 0),
            'notes' => $order->notes,
            'customer' => $this->customerBlock($order, detailed: true),
            'delivery' => [
                'state' => $order->delivery_state,
                'area' => $order->delivery_area,
                'days' => $order->delivery_days !== null ? (int) $order->delivery_days : null,
                'route' => $order->deliveryRoute ? [
                    'area' => $order->deliveryRoute->area,
                    'state' => $order->deliveryRoute->state,
                    'fee' => (int) $order->deliveryRoute->fee,
                    'delivery_days' => $order->deliveryRoute->delivery_days !== null
                        ? (int) $order->deliveryRoute->delivery_days
                        : null,
                ] : null,
                'address' => $order->deliveryAddress ? [
                    'name' => $order->deliveryAddress->recipient_name,
                    'phone' => $order->deliveryAddress->recipient_phone,
                    'street' => $order->deliveryAddress->street_address,
                    'city' => $order->deliveryAddress->city,
                    'state' => $order->deliveryAddress->state,
                    'country' => $order->deliveryAddress->country,
                ] : null,
            ],
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'unit_price' => (float) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'subtotal' => (float) $item->subtotal,
                'is_digital' => (bool) $item->is_digital,
            ])->values()->all(),
            'transactions' => $order->transactions->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status?->value,
                'status_label' => $transaction->status?->label(),
                'payment_method' => $transaction->paymentMethod?->name,
                'payment_method_code' => $transaction->paymentMethod?->code,
                'paid_at' => $transaction->paid_at?->toISOString(),
                'created_at' => $transaction->created_at?->toISOString(),
            ])->values()->all(),
            'activity' => $this->orderActivity($order),
            'quick_info' => [
                'store' => $order->store?->name,
                'store_id' => $order->store?->store_id,
                'store_owner' => $order->store?->user?->name,
                'store_owner_email' => $order->store?->user?->email,
                'business' => $order->store?->business?->name,
                'business_code' => $order->store?->business?->business_code,
                'order_date' => $order->created_at?->toISOString(),
                'updated_at' => $order->updated_at?->toISOString(),
            ],
        ];
    }

    /**
     * The derived payment badge, computed from the eager-loaded transaction
     * set when there is one so a page of 20 orders costs no extra queries.
     * Mirrors Order::getPaymentStatusAttribute, which the list cannot call
     * without firing a query per row.
     */
    protected function derivedPaymentStatus(Order $order): PaymentStatus
    {
        $transactions = $order->relationLoaded('transactions')
            ? $order->transactions
            : $order->transactions()->get();

        $live = $transactions->filter(fn (Transaction $transaction) => in_array($transaction->status?->value, self::LIVE_TRANSACTION_STATUSES, true));

        if ($live->isEmpty()) {
            return PaymentStatus::UNPAID;
        }

        if ($live->contains(fn (Transaction $transaction) => in_array($transaction->status?->value, [
            TransactionStatus::REFUNDED->value,
            TransactionStatus::REFUND_PENDING->value,
        ], true))) {
            return PaymentStatus::REFUNDED;
        }

        $paidSum = (float) $transactions
            ->filter(fn (Transaction $transaction) => in_array($transaction->status?->value, [
                TransactionStatus::PAID->value,
                TransactionStatus::CONFIRMED->value,
            ], true))
            ->sum(fn (Transaction $transaction) => (float) $transaction->amount);

        if ($paidSum >= (float) $order->total) {
            return PaymentStatus::PAID;
        }

        if ($paidSum > 0) {
            return PaymentStatus::PARTIAL;
        }

        // The collection holds models, so the pending check has to compare
        // each row's enum — comparing the models to the 'pending' string would
        // never match.
        if ($live->contains(fn (Transaction $transaction) => $transaction->status === TransactionStatus::PENDING)) {
            return PaymentStatus::PENDING;
        }

        return PaymentStatus::UNPAID;
    }

    /**
     * The SQL twin of the accessor's decision tree, so a filter and the badge
     * it filters on cannot disagree.
     */
    protected function applyPaymentStatusFilter(Builder $query, string $status): void
    {
        switch ($status) {
            case PaymentStatus::UNPAID->value:
                // Absent and cancelled-only both derive as unpaid.
                $query->whereDoesntHave('transactions', fn ($q) => $q->whereIn('status', self::LIVE_TRANSACTION_STATUSES));
                break;

            case PaymentStatus::PENDING->value:
                // The accessor checks refunds first and any paid sum before
                // pending, so an order that is refunded or partly paid is not
                // a pending match even when an extra pending leg exists.
                $this->excludeRefundedLegs($query);

                $paidSum = $this->paidSumSubquery();
                $query->whereHas('transactions', fn ($q) => $q->where('status', TransactionStatus::PENDING->value))
                    ->whereRaw('('.$paidSum->toSql().') <= 0', $paidSum->getBindings())
                    ->whereRaw('('.$paidSum->toSql().') < orders.total', $paidSum->getBindings());
                break;

            case PaymentStatus::REFUNDED->value:
                $query->whereHas('transactions', fn ($q) => $q->whereIn('status', [
                    TransactionStatus::REFUNDED->value,
                    TransactionStatus::REFUND_PENDING->value,
                ]));
                break;

            case PaymentStatus::PAID->value:
                $this->excludeRefundedLegs($query);

                $paidSum = $this->paidSumSubquery();
                // At least one live leg must exist, mirroring the accessor's
                // unpaid short-circuit for orders with no transactions (or
                // cancelled-only ones) at a zero total.
                $query->whereHas('transactions', fn ($q) => $q->whereIn('status', self::LIVE_TRANSACTION_STATUSES))
                    ->whereRaw('('.$paidSum->toSql().') >= orders.total', $paidSum->getBindings());
                break;

            case PaymentStatus::PARTIAL->value:
                $this->excludeRefundedLegs($query);

                $paidSum = $this->paidSumSubquery();
                $query->whereRaw('('.$paidSum->toSql().') > 0', $paidSum->getBindings())
                    ->whereRaw('('.$paidSum->toSql().') < orders.total', $paidSum->getBindings());
                break;

            case PaymentStatus::FAILED->value:
                // TransactionStatus has no failed case; legacy mapped Failed
                // to CANCELED, so the filter keeps that mapping.
                $query->whereHas('transactions', fn ($q) => $q->where('status', TransactionStatus::CANCELED->value));
                break;
        }
    }

    /**
     * The accessor lets a refunded leg win outright, so every non-refunded
     * facet (paid/partial/pending) has to exclude orders carrying one.
     */
    private function excludeRefundedLegs(Builder $query): void
    {
        $query->whereDoesntHave('transactions', fn ($q) => $q->whereIn('status', [
            TransactionStatus::REFUNDED->value,
            TransactionStatus::REFUND_PENDING->value,
        ]));
    }

    /**
     * Correlated SUM of an order's paid/confirmed legs — the SQL twin of the
     * accessor's `$confirmedSum`.
     */
    private function paidSumSubquery(): \Illuminate\Database\Query\Builder
    {
        return Transaction::query()
            ->selectRaw('coalesce(sum(transactions.amount), 0)')
            ->whereColumn('transactions.order_id', 'orders.id')
            ->whereIn('transactions.status', [
                TransactionStatus::PAID->value,
                TransactionStatus::CONFIRMED->value,
            ])
            ->toBase();
    }

    /**
     * The customer block both screens render: a real customer for online
     * orders, the order meta for POS walk-ins (legacy hid the placeholder
     * `walkin@pos.local` account and fell back to the captured name/phone).
     *
     * @return array<string, mixed>
     */
    protected function customerBlock(Order $order, bool $detailed = false): array
    {
        $customer = $order->customer;
        $meta = $order->meta ?? [];

        $email = $customer?->email;
        $isWalkIn = ! $customer
            || ! $email
            || str_contains($email, 'walkin@pos.local')
            || str_contains($email, '@walkin.local');

        if ($isWalkIn) {
            $block = [
                'id' => $customer?->id,
                'name' => $meta['customer_name'] ?? ($customer?->first_name ? $customer->full_name : 'Walk-in'),
                'email' => null,
                'phone' => $meta['customer_phone'] ?? $customer?->phone,
                'is_walk_in' => true,
            ];
        } else {
            $block = [
                'id' => $customer->id,
                'name' => $customer->full_name,
                'email' => $email,
                'phone' => $customer->phone,
                'is_walk_in' => false,
            ];
        }

        if ($detailed) {
            $block['address'] = $customer ? [
                'street' => $customer->street_address,
                'city' => $customer->city,
                'state' => $customer->state,
                'country' => $customer->country,
            ] : null;
            $block['full_address'] = $customer
                ? collect([$customer->street_address, $customer->city, $customer->state, $customer->country])
                    ->filter()
                    ->implode(', ')
                : null;
        }

        return $block;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function orderActivity(Order $order): array
    {
        return ActivityLog::query()
            ->where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->with('user:id,name')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'user' => $log->user?->name,
                'created_at' => $log->created_at?->toISOString(),
                'created_at_human' => $log->created_at?->diffForHumans(),
            ])->all();
    }

    /**
     * The fields an audit row captures for an order. Kept deliberately small:
     * a full `toArray()` would drag relations and payment blobs into every
     * delete/update trail.
     *
     * @return array<string, mixed>
     */
    protected function orderAuditSnapshot(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'payment_status' => $this->derivedPaymentStatus($order)->value,
            'subtotal' => (float) $order->subtotal,
            'shipping_fee' => (float) $order->shipping_fee,
            'tax' => (float) $order->tax,
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'notes' => $order->notes,
            'store_id' => $order->store_id,
            'customer_id' => $order->customer_id,
            'source' => $order->source,
        ];
    }
}
