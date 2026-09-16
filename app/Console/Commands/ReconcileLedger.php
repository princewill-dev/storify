<?php

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Console\Command;

class ReconcileLedger extends Command
{
    protected $signature = 'ledger:reconcile
        {--business= : Limit to a single business id}
        {--post : Post missing entries instead of only reporting them}
        {--limit=500 : Maximum transactions to inspect}';

    protected $description = 'Detect confirmed transactions and orders that have no ledger journal entry';

    public function handle(LedgerPostingService $posting): int
    {
        $businessId = $this->option('business') ? (int) $this->option('business') : null;
        $shouldPost = (bool) $this->option('post');
        $limit = (int) $this->option('limit');

        $missing = 0;
        $posted = 0;

        $transactions = Transaction::query()
            ->whereIn('status', [TransactionStatus::CONFIRMED, TransactionStatus::PAID])
            ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
            ->with(['order.items', 'invoice', 'paymentMethod'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($transactions as $transaction) {
            if ($transaction->order_id && $this->hasEntry($transaction->business_id, 'sale:order:'.$transaction->order_id)) {
                continue;
            }

            if ($this->hasEntry($transaction->business_id, 'payment:txn:'.$transaction->id)) {
                continue;
            }

            // POS sales are posted as a single sale entry per order.
            if ($transaction->order && $transaction->order->isPos()) {
                $missing++;

                if ($shouldPost) {
                    $posting->safe(fn () => $posting->postSale($transaction->order, null));
                    $posted++;
                }

                continue;
            }

            // Subscription mirrors post to platform books.
            if (! $transaction->order_id && ! $transaction->invoice_id) {
                continue;
            }

            $missing++;

            if ($shouldPost) {
                $posting->safe(fn () => $posting->postPaymentReceived($transaction, null));
                $posted++;
            }
        }

        $ordersWithoutRevenue = Order::query()
            ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
            ->whereIn('source', ['checkout', 'shop4me'])
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->filter(fn (Order $order) => ! $this->hasEntry($order->business_id, 'order_revenue:'.$order->id));

        $missing += $ordersWithoutRevenue->count();

        if ($shouldPost) {
            foreach ($ordersWithoutRevenue as $order) {
                $posting->safe(fn () => $posting->postOrderRevenue($order, null));
                $posted++;
            }
        }

        $this->info("Unposted money events detected: {$missing}");
        if ($shouldPost) {
            $this->info("Entries posted: {$posted}");
        } elseif ($missing > 0) {
            $this->warn('Run again with --post to backfill these entries.');
        }

        return self::SUCCESS;
    }

    private function hasEntry(?int $businessId, string $key): bool
    {
        return JournalEntry::query()
            ->where('business_id', $businessId)
            ->where('idempotency_key', $key)
            ->exists();
    }
}
