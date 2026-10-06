<?php

namespace App\Http\Controllers\Api\V1\Management\Pos;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-17 — the Orders list needs to be able to isolate POS takings, which means
 * the legacy `source` filter (Online Store = "checkout" | "pos") plus the POS
 * session a row was rung on.
 *
 * routes/api/v1/management/ws17-pos-oversight.php re-registers `GET orders`
 * against this controller: Laravel keys routes by method+URI, so the later
 * registration replaces the thin OrderController@index declared earlier in the
 * shared file without either file being edited. The index query and payload
 * below are a faithful superset of that version — same filters, same columns,
 * same ordering — so existing consumers see an unchanged shape; only the
 * `source` filter, `pos_session_id` and `is_pos` are new.
 *
 * WS-13's richer board list (`GET orders/board/list`) remains the SPA orders
 * screen; this endpoint keeps working for every other consumer.
 */
class OrderSourceController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        $orders = Order::query()
            ->where('business_id', $this->user($request)->business_id)
            ->whereIn('store_id', $this->accessibleStoreIds($request))
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', $request->integer('store_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            // Legacy filtered Source as an exact match with two options; the
            // POS badge reads the same column.
            ->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner->where('order_number', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('email', 'like', $term)));
            })
            ->with(['customer:id,first_name,last_name,email', 'store:id,name'])
            ->latest()
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $orders->getCollection()->map(fn (Order $order) => $this->payload($order))->values()->all(),
            null,
            200,
            $this->paginationMeta($orders)
        );
    }

    /**
     * Mirrors OrderController@payload (list variant) plus the POS linkage.
     *
     * @return array<string, mixed>
     */
    private function payload(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer' => $order->customer?->full_name,
            'customer_id' => $order->customer_id,
            'store' => $order->store?->name,
            'store_id' => $order->store_id,
            'source' => $order->source,
            'is_pos' => $order->isPos(),
            'pos_session_id' => $order->pos_session_id,
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'remaining' => (float) $order->remainingBalance(),
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'payment_status' => $order->payment_status?->value,
            'created_at' => $order->created_at?->toISOString(),
        ];
    }
}
