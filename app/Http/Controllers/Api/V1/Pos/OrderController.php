<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class OrderController extends Controller
{
    public function history(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();
        $query = Order::query()
            ->where('store_id', $store->id)
            ->where('source', 'pos')
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = $request->string('q')->toString();
                $query->where(function ($nested) use ($search) {
                    $nested->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('items', fn ($items) => $items->where('product_name', 'like', "%{$search}%"));
                });
            })
            ->with(['items', 'transactions.paymentMethod']);

        $session = PosSession::query()
            ->where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->latest()
            ->first();

        $session
            ? $query->where('pos_session_id', $session->id)
            : $query->where('staff_id', $user->id);

        $orders = $query->latest()->paginate(20)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => [
                'orders' => $orders->map(fn (Order $order) => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'total' => (float) $order->total,
                    'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
                    'created_at' => $order->created_at->toISOString(),
                    'items_count' => $order->items->count(),
                    'items' => $order->items->take(3)->map(fn ($item) => $item->product_name),
                    'more_items' => max(0, $order->items->count() - 3),
                    'has_refund' => $order->transactions->whereIn('status', ['refunded', 'refund_pending'])->isNotEmpty(),
                    'refund_status' => $order->transactions->whereIn('status', ['refunded', 'refund_pending'])->first()?->status?->value,
                ]),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                ],
            ],
        ]);
    }

    public function receipt(Store $store, int $orderId): JsonResponse
    {
        $order = Order::query()
            ->where('store_id', $store->id)
            ->with(['items', 'transactions.paymentMethod', 'customer'])
            ->findOrFail($orderId);

        $transaction = $order->transactions->first();
        $meta = $order->meta ?? [];
        $amountTendered = (int) ($meta['amount_tendered'] ?? 0);

        return response()->json([
            'success' => true,
            'data' => [
                'order' => [
                    'order_number' => $order->order_number,
                    'total' => (float) $order->total,
                    'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
                    'date' => $order->created_at->toISOString(),
                    'created_at' => $order->created_at->toISOString(),
                    'store_name' => $store->name,
                    'store_address' => $store->address,
                    'payment_method' => $transaction?->paymentMethod?->name ?? 'Cash',
                    'reference' => $transaction?->reference,
                    'customer_name' => $meta['customer_name'] ?? $order->customer?->full_name,
                    'customer_phone' => $meta['customer_phone'] ?? $order->customer?->phone,
                    'amount_tendered' => $amountTendered,
                    'change' => $amountTendered > 0 ? max(0, $amountTendered - (int) $order->total) : 0,
                    'service_charge_name' => $meta['service_charge_name'] ?? null,
                    'service_charge_amount' => (float) ($order->service_charge_amount ?? 0),
                    'items' => $order->items->map(fn ($item) => [
                        'name' => $item->product_name,
                        'qty' => $item->quantity,
                        'price' => (float) $item->unit_price,
                        'subtotal' => (float) $item->subtotal,
                    ]),
                ],
            ],
        ]);
    }

    public function refund(Request $request, Store $store, int $orderId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $user = $request->user();

        $result = DB::transaction(function () use ($orderId, $store, $user, $data): string {
            $order = Order::query()
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->findOrFail($orderId);

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
                    'refund_reason' => $data['reason'],
                    'refund_requested_by' => $user->id,
                    'refund_requested_at' => now()->toDateTimeString(),
                    'original_transaction_id' => $confirmedTransaction->id,
                ],
            ]);

            return 'created';
        });

        if ($result === 'not_confirmed') {
            return response()->json(['success' => false, 'message' => 'Only confirmed orders can be refunded.'], 400);
        }

        if ($result === 'duplicate') {
            return response()->json(['success' => false, 'message' => 'A refund has already been requested for this order.'], 400);
        }

        Log::info('pos.refund_requested', [
            'order_id' => $orderId,
            'staff_id' => $user->id,
            'store_id' => $store->id,
            'reason' => $data['reason'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Refund requested. Awaiting admin approval.',
        ], 201);
    }
}
