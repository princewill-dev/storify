<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('business_id', $this->user($request)->business_id)
            ->whereIn('store_id', $this->accessibleStoreIds($request))
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', $request->integer('store_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
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

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $order->load(['items', 'customer', 'store', 'transactions.paymentMethod', 'deliveryAddress']);

        return $this->ok(['order' => $this->payload($order, detailed: true)]);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_column(OrderStatus::cases(), 'value'))],
        ]);

        $order->update(['status' => $data['status']]);

        return $this->ok(['order' => $this->payload($order->fresh())], 'Order status updated.');
    }

    public function updatePaymentStatus(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $data = $request->validate([
            'payment_status' => ['required', Rule::in(['pending', 'paid', 'refunded', 'failed'])],
        ]);

        $map = [
            'pending' => TransactionStatus::PENDING->value,
            'paid' => TransactionStatus::CONFIRMED->value,
            'refunded' => TransactionStatus::REFUNDED->value,
            'failed' => TransactionStatus::CANCELED->value,
        ];

        $status = $map[$data['payment_status']];

        $transaction = $order->transactions()->latest()->first();

        if ($transaction) {
            $transaction->update(['status' => $status]);
        } else {
            Transaction::create([
                'reference' => 'MAN-'.strtoupper(\Illuminate\Support\Str::random(10)),
                'order_id' => $order->id,
                'business_id' => $order->business_id,
                'amount' => $order->total,
                'status' => $status,
                'paid_at' => $status === TransactionStatus::CONFIRMED->value ? now() : null,
            ]);
        }

        return $this->ok(['order' => $this->payload($order->fresh())], 'Payment status updated.');
    }

    public function destroy(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $order->delete();

        return $this->ok([], 'Order deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Order $order, bool $detailed = false): array
    {
        $data = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer' => $order->customer?->full_name,
            'customer_id' => $order->customer_id,
            'store' => $order->store?->name,
            'store_id' => $order->store_id,
            'source' => $order->source,
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'remaining' => (float) $order->remainingBalance(),
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'payment_status' => $order->payment_status?->value,
            'created_at' => $order->created_at?->toISOString(),
        ];

        if ($detailed) {
            $data['subtotal'] = (float) $order->subtotal;
            $data['shipping_fee'] = (float) $order->shipping_fee;
            $data['tax'] = (float) $order->tax;
            $data['service_charge'] = (float) ($order->service_charge_amount ?? 0);
            $data['notes'] = $order->notes;
            $data['items'] = $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'unit_price' => (float) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'subtotal' => (float) $item->subtotal,
                'is_digital' => (bool) $item->is_digital,
            ])->values()->all();
            $data['transactions'] = $order->transactions->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'amount' => (float) $transaction->amount,
                'status' => $transaction->status instanceof TransactionStatus ? $transaction->status->value : $transaction->status,
                'payment_method' => $transaction->paymentMethod?->name,
                'paid_at' => $transaction->paid_at?->toISOString(),
            ])->values()->all();
            $data['delivery_address'] = $order->deliveryAddress ? [
                'name' => $order->deliveryAddress->recipient_name,
                'phone' => $order->deliveryAddress->recipient_phone,
                'street' => $order->deliveryAddress->street_address,
                'city' => $order->deliveryAddress->city,
                'state' => $order->deliveryAddress->state,
                'country' => $order->deliveryAddress->country,
            ] : null;
        }

        return $data;
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $user = $this->user($request);

        if ((int) $order->business_id !== (int) $user->business_id
            || ! $user->accessibleStores()->whereKey($order->store_id)->exists()) {
            abort(403, 'You do not have access to this order.');
        }
    }
}
