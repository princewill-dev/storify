<?php

namespace App\Http\Resources\Pos;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The receipt payload for one POS order.
 *
 * Field names, types and order are the exact contract the controller emitted
 * inline — the receipt screen and its tests assert them — so every relation
 * read, fallback and cast stays on the same field.
 *
 * The change owed to the customer is computed in kobo with
 * Naira::koboFromRounded(), the float multiply-and-round contract the inline
 * `(int) round((float) $order->total * 100)` implemented; the surrounding
 * amounts are decimal naira columns cast to float for display, not kobo.
 *
 * @property-read Order $resource
 */
final class OrderReceiptResource extends JsonResource
{
    public function __construct(Order $order, private readonly Store $store)
    {
        parent::__construct($order);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        $transaction = $order->transactions->first();
        $meta = $order->meta ?? [];
        $amountTendered = (int) ($meta['amount_tendered'] ?? 0);

        return [
            'order_number' => $order->order_number,
            'total' => (float) $order->total,
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'date' => $order->created_at->toISOString(),
            'created_at' => $order->created_at->toISOString(),
            'store_name' => $this->store->name,
            'store_address' => $this->store->address,
            'payment_method' => $transaction?->paymentMethod?->name ?? 'Cash',
            'reference' => $transaction?->reference,
            'customer_name' => $meta['customer_name'] ?? $order->customer?->full_name,
            'customer_phone' => $meta['customer_phone'] ?? $order->customer?->phone,
            'amount_tendered' => $amountTendered,
            'change' => $amountTendered > 0 ? max(0, $amountTendered - Naira::koboFromRounded($order->total)) : 0,
            'tax' => (float) $order->tax,
            'service_charge_name' => $meta['service_charge_name'] ?? null,
            'service_charge_amount' => (float) ($order->service_charge_amount ?? 0),
            'payments' => $order->transactions->map(fn (Transaction $transaction) => [
                'method' => $transaction->metadata['leg_method'] ?? ($transaction->paymentMethod?->code ?? 'cash'),
                'method_label' => $transaction->paymentMethod?->name ?? 'Cash',
                'amount' => (float) $transaction->amount,
            ])->values(),
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'qty' => $item->quantity,
                'price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
            ]),
        ];
    }
}
