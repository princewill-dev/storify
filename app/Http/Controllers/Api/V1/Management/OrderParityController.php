<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * WS-13 — orders list & detail parity.
 *
 * Lives beside OrderController because that controller's list/show/status
 * endpoints are shared with other workstreams and must not change shape. This
 * one carries what the legacy screens rendered and the base payloads dropped:
 * item counts, payment-method label, split-payment legs, delivery tracking,
 * activity timeline, bank proof-of-payment, and the order edit slice (pricing
 * and notes with a server-side total recompute).
 *
 * The shared route file binds orders/{order} before feature modules load, so
 * these endpoints deliberately sit on their own paths (orders/board/list,
 * orders/{order}/detail, …) rather than shadowing the base ones. The SPA is
 * the only consumer of the base list, so it reads this deeper payload instead.
 */
class OrderParityController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'store_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Legacy's per-store route filtered on the store *code* string while
        // the column holds the numeric id, so the filter silently matched
        // nothing. The id is validated against the stores this user can reach
        // and a foreign id is refused rather than quietly returning an empty
        // page.
        if (isset($filters['store_id'])) {
            $this->authorizeStoreId($request, (int) $filters['store_id']);
        }

        $orders = $this->accessibleQuery($request)
            ->withCount(['items', 'transactions'])
            ->with([
                'customer:id,first_name,last_name,email,phone',
                'store:id,name',
                'transactions.paymentMethod:id,name,code',
            ])
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.$term.'%';
                $q->where(fn ($inner) => $inner->where('order_number', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like)));
            })
            ->latest()
            ->paginate($filters['per_page'] ?? 20);

        return $this->ok(
            ['orders' => $orders->getCollection()->map(fn (Order $order) => $this->summary($order))->all()],
            null,
            200,
            $this->paginationMeta($orders),
        );
    }

    /**
     * The legacy counters — Total/Pending/Dispatched/Delivered were rendered,
     * the other five were computed and discarded. All nine are returned so the
     * list can surface them without a second shape.
     */
    public function stats(Request $request): JsonResponse
    {
        $counts = $this->accessibleQuery($request)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $stats = ['total' => 0];

        foreach (OrderStatus::cases() as $status) {
            $stats[$status->value] = (int) ($counts[$status->value] ?? 0);
            $stats['total'] += $stats[$status->value];
        }

        return $this->ok(['stats' => $stats]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        return $this->ok(['order' => $this->detail($order)]);
    }

    public function edit(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        return $this->ok(['order' => $this->detail($order)]);
    }

    /**
     * Legacy's edit form rendered Status and Payment Status selects, but its
     * update() only validated shipping/tax/notes — the two controls were
     * silently discarded. They are deliberately absent here instead of cloned;
     * only the three fields the form actually owns are accepted.
     */
    public function update(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $data = $request->validate([
            'shipping_fee' => ['required', 'numeric', 'min:0'],
            'tax' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $user = $this->user($request);

        DB::transaction(function () use ($order, $data, $request, $user) {
            $before = [
                'shipping_fee' => (float) $order->shipping_fee,
                'tax' => (float) $order->tax,
                'total' => (float) $order->total,
            ];

            $shippingFee = round((float) $data['shipping_fee'], 2);
            $tax = round((float) $data['tax'], 2);

            // The edit form only owns shipping, tax and notes, so the total is
            // recomputed here from the persisted pieces — never trusted from
            // the client. Service charge is the one component the form must
            // not touch.
            $total = round(
                (float) $order->subtotal + $shippingFee + $tax + (float) ($order->service_charge_amount ?? 0),
                2,
            );

            $order->update([
                'shipping_fee' => $shippingFee,
                'tax' => $tax,
                'notes' => $data['notes'] ?? null,
                'total' => $total,
            ]);

            // Legacy changed prices without leaving a trace; the order
            // timeline now records the edit and both totals.
            ActivityLog::create([
                'user_id' => $user->id,
                'business_id' => $order->business_id,
                'action' => 'updated',
                'subject_type' => Order::class,
                'subject_id' => $order->id,
                'description' => 'Order pricing and notes updated',
                'old_values' => $before,
                'new_values' => [
                    'shipping_fee' => $shippingFee,
                    'tax' => $tax,
                    'total' => $total,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        });

        return $this->ok(['order' => $this->detail($order->fresh())], 'Order updated.');
    }

    private function accessibleQuery(Request $request): Builder
    {
        return Order::query()
            ->where('business_id', $this->user($request)->business_id)
            ->whereIn('store_id', $this->accessibleStoreIds($request));
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $user = $this->user($request);

        if ((int) $order->business_id !== (int) $user->business_id
            || ! $user->accessibleStores()->whereKey($order->store_id)->exists()) {
            abort(403, 'You do not have access to this order.');
        }
    }

    private function authorizeStoreId(Request $request, int $storeId): void
    {
        if (! $this->user($request)->accessibleStores()->whereKey($storeId)->exists()) {
            abort(403, 'You do not have access to this store.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Order $order): array
    {
        // First by id — legacy treated the oldest transaction as the payment
        // gate, and an unordered relation is not guaranteed to give it.
        $firstTransaction = $order->transactions->sortBy('id')->first();
        $legs = (int) ($order->transactions_count ?? $order->transactions->count());

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer' => $this->customer($order),
            'store' => $order->store?->name,
            'store_id' => $order->store_id,
            'source' => $order->source,
            'is_pos' => $order->isPos(),
            'items_count' => (int) ($order->items_count ?? $order->items->count()),
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'remaining' => $order->remainingBalance(),
            'payment_method' => $this->paymentMethodLabel($firstTransaction),
            'payment_method_code' => $firstTransaction?->paymentMethod?->code,
            'payment_legs' => $legs,
            'is_split_payment' => $legs > 1,
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'status_label' => $order->status_label,
            'created_at' => $order->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Order $order): array
    {
        $order->loadMissing([
            'items.product.images',
            'customer',
            'store',
            'staff',
            'posSession',
            'deliveryAddress',
            'deliveryRoute',
            'delivery',
            'transactions.paymentMethod',
            'transactions.storeBank',
        ]);

        return [
            ...$this->summary($order),
            'subtotal' => (float) $order->subtotal,
            'shipping_fee' => (float) $order->shipping_fee,
            'tax' => (float) $order->tax,
            'service_charge' => $this->serviceCharge($order),
            'notes' => $order->notes,
            // Computed from the transactions relation, so it belongs on the
            // detailed payload where that relation is loaded — putting it on
            // every list row would fire a query per order.
            'payment_status' => $order->payment_status?->value,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'product_image_url' => $item->product?->primaryImage()?->path
                    ? asset('storage/'.$item->product->primaryImage()->path)
                    : null,
                'unit_price' => (float) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'subtotal' => (float) $item->subtotal,
                'is_digital' => (bool) $item->is_digital,
            ])->values()->all(),
            'staff' => $order->staff ? [
                'id' => $order->staff->id,
                'name' => $order->staff->name,
                'email' => $order->staff->email,
            ] : null,
            'pos_session' => $order->posSession ? [
                'id' => $order->posSession->id,
                'session_code' => $order->posSession->session_code,
            ] : null,
            'delivery_address' => $order->deliveryAddress ? [
                'name' => $order->deliveryAddress->recipient_name,
                'phone' => $order->deliveryAddress->recipient_phone,
                'street' => $order->deliveryAddress->street_address,
                'city' => $order->deliveryAddress->city,
                'state' => $order->deliveryAddress->state,
                'country' => $order->deliveryAddress->country,
            ] : null,
            // The stored state/area is the legacy fallback when an order has
            // no DeliveryAddress row and no matched route.
            'delivery_state' => $order->delivery_state,
            'delivery_area' => $order->delivery_area,
            'delivery_days' => $order->delivery_days !== null ? (int) $order->delivery_days : null,
            'delivery_route' => $order->deliveryRoute ? [
                'area' => $order->deliveryRoute->area,
                'state' => $order->deliveryRoute->state,
                // Kobo, as every other delivery-route payload returns it.
                // Legacy printed this column as naira, reading the fee 100×
                // too large; the SPA converts instead.
                'fee' => (int) $order->deliveryRoute->fee,
                'delivery_days' => $order->deliveryRoute->delivery_days,
            ] : null,
            'delivery' => $this->delivery($order->delivery),
            'transactions' => $order->transactions->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status?->value,
                'status_label' => $transaction->status_label,
                'payment_method' => $transaction->paymentMethod?->name,
                'payment_method_code' => $transaction->paymentMethod?->code,
                'paid_at' => $transaction->paid_at?->toISOString(),
                'created_at' => $transaction->created_at?->toISOString(),
                'bank' => $transaction->storeBank ? [
                    'bank_name' => $transaction->storeBank->bank_name,
                    'account_number' => $transaction->storeBank->account_number,
                    'account_name' => $transaction->storeBank->account_name,
                    'is_verified' => (bool) $transaction->storeBank->is_verified,
                ] : null,
            ])->values()->all(),
            'activity' => $this->activity($order),
        ];
    }

    /**
     * The customer block both screens render: a real customer for online
     * orders, the order meta for POS walk-ins (legacy hid the placeholder
     * `walkin@pos.local` account and fell back to the captured name/phone).
     *
     * @return array<string, mixed>
     */
    private function customer(Order $order): array
    {
        $customer = $order->customer;
        $meta = $order->meta ?? [];

        $email = $customer?->email;
        $isWalkIn = ! $customer
            || ! $email
            || str_contains($email, 'walkin@pos.local')
            || str_contains($email, '@walkin.local');

        if ($isWalkIn) {
            return [
                'id' => $customer?->id,
                'name' => $meta['customer_name'] ?? ($customer?->first_name ? $customer->full_name : 'Walk-in'),
                'email' => null,
                'phone' => $meta['customer_phone'] ?? $customer?->phone,
                'is_walk_in' => true,
            ];
        }

        return [
            'id' => $customer->id,
            'name' => $customer->full_name,
            'email' => $email,
            'phone' => $customer->phone,
            'is_walk_in' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceCharge(Order $order): array
    {
        $meta = $order->meta ?? [];

        // Legacy fell back to the stored columns and then to whatever the
        // total left over after subtotal/shipping/tax.
        $amount = $order->service_charge_amount
            ?? ($meta['service_charge_amount'] ?? null)
            ?? (($order->total - $order->subtotal - $order->shipping_fee - $order->tax) > 0
                ? $order->total - $order->subtotal - $order->shipping_fee - $order->tax
                : 0);

        return [
            'name' => $meta['service_charge_name'] ?? null,
            'amount' => (float) $amount,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function delivery(?OrderDelivery $delivery): ?array
    {
        if (! $delivery) {
            return null;
        }

        return [
            'status' => $delivery->status,
            'tracking_number' => $delivery->tracking_number,
            'driver_name' => $delivery->driver_name,
            'driver_phone' => $delivery->driver_phone,
            'current_location' => $delivery->current_location,
            'recipient_name' => $delivery->recipient_name,
            'estimated_delivery_at' => $delivery->estimated_delivery_at?->toISOString(),
            'actual_delivery_at' => $delivery->actual_delivery_at?->toISOString(),
            'delivery_notes' => $delivery->delivery_notes,
            'return_reason' => $delivery->return_reason,
            'created_at' => $delivery->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activity(Order $order): array
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

    private function paymentMethodLabel(?Transaction $transaction): ?string
    {
        $method = $transaction?->paymentMethod;

        if (! $method) {
            return null;
        }

        return match ($method->code) {
            'cash' => 'Cash',
            'bank_transfer' => 'Transfer',
            default => $method->name,
        };
    }
}
