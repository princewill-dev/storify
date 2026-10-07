<?php

namespace App\Http\Resources\Management\Pos;

use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\PosSession;
use App\Models\Transaction;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-17 — session detail: the summary widened with the duration, the sales
 * records, their payment legs and the cashier's recent sessions across the
 * business.
 *
 * The relations arrive eager-loaded by the repository; `staff_recent_sessions`
 * is fetched there under the same tenant scope and passed in, so shaping
 * issues no queries of its own beyond the loaded relations.
 *
 * @property PosSession $resource
 */
final class PosSessionDetailResource extends JsonResource
{
    /**
     * @param  Collection<int, PosSession>  $staffRecentSessions
     */
    public function __construct(PosSession $session, private readonly Collection $staffRecentSessions)
    {
        parent::__construct($session);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PosSession $session */
        $session = $this->resource;

        $orders = $session->orders;

        $transactions = $orders
            ->flatMap(fn (Order $order) => $order->transactions->map(fn (Transaction $transaction) => $this->transactionRow($order, $transaction)))
            ->values()
            ->all();

        return [
            ...(new PosSessionSummaryResource($session))->toArray($request),
            'duration_seconds' => $session->closed_at && $session->opened_at
                ? (int) $session->opened_at->diffInSeconds($session->closed_at)
                : null,
            'orders' => $orders->map(fn (Order $order) => $this->orderRow($order))->values()->all(),
            'transactions' => $transactions,
            'staff_recent_sessions' => $this->staffRecentSessions
                ->map(fn (PosSession $recent) => $this->recentSessionRow($recent))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'items_count' => (int) ($order->items_count ?? $order->items->count()),
            'total' => Naira::koboFromRounded($order->total),
            'payment_status' => $order->transactions->first()?->status instanceof TransactionStatus
                ? $order->transactions->first()->status->value
                : $order->transactions->first()?->status,
            'payment_method' => data_get($order->meta, 'payment_method')
                ?? $order->transactions->first()?->paymentMethod?->name,
            'reference' => $order->transactions->first()?->reference,
            'created_at' => $order->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionRow(Order $order, Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'order_number' => $order->order_number,
            'amount' => Naira::koboFromRounded($transaction->amount),
            'status' => $transaction->status instanceof TransactionStatus
                ? $transaction->status->value
                : $transaction->status,
            'payment_method' => $transaction->paymentMethod?->name,
            'paid_at' => $transaction->paid_at?->toISOString(),
            'created_at' => $transaction->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recentSessionRow(PosSession $recent): array
    {
        return [
            'id' => $recent->id,
            'session_code' => $recent->session_code,
            'store' => $recent->store ? [
                'id' => $recent->store->id,
                'store_id' => $recent->store->store_id,
                'name' => $recent->store->name,
            ] : null,
            'status' => $recent->status,
            'is_open' => $recent->isOpen(),
            'opened_at' => $recent->opened_at?->toISOString(),
            'closed_at' => $recent->closed_at?->toISOString(),
        ];
    }
}
