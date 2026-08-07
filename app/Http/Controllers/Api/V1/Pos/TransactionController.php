<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request, Store $store): JsonResponse
    {
        $query = Transaction::query()
            ->where(function ($q) use ($store) {
                $q->whereHas('order', fn($o) => $o->where('store_id', $store->id))
                  ->orWhereHas('invoice', fn($i) => $i->where('store_id', $store->id));
            })
            ->with(['order.customer', 'paymentMethod'])
            ->latest();

        if ($request->filled('status') && in_array($request->status, ['confirmed', 'refunded', 'refund_pending'])) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $transactions = $query->paginate(30)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => [
                'transactions' => $transactions->map(fn($tx) => [
                    'id' => $tx->id,
                    'reference' => $tx->reference,
                    'amount' => (float) $tx->amount,
                    'status' => $tx->status->value,
                    'status_label' => $tx->status->label(),
                    'payment_method' => $tx->paymentMethod?->name,
                    'customer_name' => $tx->order?->customer?->full_name ?? $tx->invoice?->recipient_name,
                    'order_number' => $tx->order?->order_number,
                    'created_at' => $tx->created_at->toISOString(),
                ]),
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'total' => $transactions->total(),
                ],
            ],
        ]);
    }

    public function show(Store $store, Transaction $transaction): JsonResponse
    {
        $transaction->load(['order.customer', 'order.items', 'order.store', 'invoice.store', 'paymentMethod']);

        return response()->json([
            'success' => true,
            'data' => [
                'transaction' => [
                    'id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'amount' => (float) $transaction->amount,
                    'status' => $transaction->status->value,
                    'status_label' => $transaction->status->label(),
                    'payment_method' => $transaction->paymentMethod?->name,
                    'currency' => $transaction->currency,
                    'paid_at' => $transaction->paid_at?->toISOString(),
                    'gateway_reference' => $transaction->gateway_reference,
                    'created_at' => $transaction->created_at->toISOString(),
                    'balance_before' => $transaction->store_balance_before,
                    'balance_after' => $transaction->store_balance_after,
                    'customer_name' => $transaction->order?->customer?->full_name ?? $transaction->invoice?->recipient_name,
                    'order' => $transaction->order ? [
                        'order_number' => $transaction->order->order_number,
                        'total' => (float) $transaction->order->total,
                        'items_count' => $transaction->order->items->count(),
                        'items' => $transaction->order->items->map(fn($i) => [
                            'name' => $i->product_name,
                            'qty' => $i->quantity,
                            'price' => (float) $i->unit_price,
                        ]),
                    ] : null,
                ],
            ],
        ]);
    }
}
