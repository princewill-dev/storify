<?php

namespace App\Services\Pos;

use App\Enums\TransactionStatus;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Pos\OrderRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The POS refund request workflow.
 *
 * The transaction boundary, the locked re-read of the order, the
 * confirmed/duplicate guards and the refund row's creation live here; the
 * controller keeps the outcome messages and status codes, and the repository
 * keeps the query building (including the lock).
 *
 * The refund row is written as REFUND_PENDING — approval happens elsewhere —
 * against the first confirmed transaction, and the order is locked before the
 * guards run so two concurrent requests cannot both pass them.
 *
 * The request log fires only on a created refund, after the commit, at the
 * same point in the sequence the controller used to emit it.
 */
final class OrderRefundService
{
    public function __construct(
        private readonly OrderRepository $orders,
    ) {}

    /**
     * Returns 'created' when a REFUND_PENDING transaction was written,
     * 'not_confirmed' when the order has no confirmed transaction, and
     * 'duplicate' when a refund is already refunded or pending.
     *
     * @return 'created'|'not_confirmed'|'duplicate'
     */
    public function requestRefund(Store $store, int $orderId, User $user, string $reason): string
    {
        $result = DB::transaction(function () use ($store, $orderId, $user, $reason): string {
            $order = $this->orders->findLockedForStore($store, $orderId);

            $confirmedTransaction = $order->transactions()
                ->where('status', TransactionStatus::CONFIRMED)
                ->first();

            if (! $confirmedTransaction) {
                return 'not_confirmed';
            }

            if ($order->transactions()->whereIn('status', [
                TransactionStatus::REFUNDED,
                TransactionStatus::REFUND_PENDING,
            ])->exists()) {
                return 'duplicate';
            }

            Transaction::create([
                'reference' => 'RFND-'.Str::upper(Str::random(10)),
                'order_id' => $order->id,
                'business_id' => $store->business_id,
                'payment_method_id' => $confirmedTransaction->payment_method_id,
                'amount' => $order->total,
                'status' => TransactionStatus::REFUND_PENDING,
                'metadata' => [
                    'refund_reason' => $reason,
                    'refund_requested_by' => $user->id,
                    'refund_requested_at' => now()->toDateTimeString(),
                    'original_transaction_id' => $confirmedTransaction->id,
                ],
            ]);

            return 'created';
        });

        if ($result === 'created') {
            Log::info('pos.refund_requested', [
                'order_id' => $orderId,
                'staff_id' => $user->id,
                'store_id' => $store->id,
                'reason' => $reason,
            ]);
        }

        return $result;
    }
}
