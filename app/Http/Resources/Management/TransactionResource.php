<?php

namespace App\Http\Resources\Management;

use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-18 — list representation of a transaction.
 *
 * Also carries the shared per-row values the CSV export renders (customer
 * name, payment-method label, store) so the list and the export cannot drift.
 */
class TransactionResource extends JsonResource
{
    /**
     * @var array<int, string>
     */
    public const CSV_HEADERS = [
        'Reference', 'Order / Invoice', 'Store', 'Customer', 'Payment method',
        'Amount', 'Fee', 'Net', 'Currency', 'Status', 'Date',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Transaction $transaction */
        $transaction = $this->resource;
        $store = $this->store();

        return [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'amount' => (float) $transaction->amount,
            'fee' => $transaction->fee_kobo !== null ? Naira::floatFromKobo($transaction->fee_kobo) : null,
            'net' => $transaction->net_kobo !== null ? Naira::floatFromKobo($transaction->net_kobo) : null,
            'currency' => $transaction->currency,
            'status' => $transaction->status instanceof TransactionStatus ? $transaction->status->value : $transaction->status,
            'status_label' => $transaction->status_label,
            'order' => $transaction->order?->order_number,
            'invoice' => $transaction->invoice?->invoice_number,
            'store' => $store?->name,
            'store_id' => $store?->id,
            'customer' => $this->customerName(),
            'payment_method' => $this->paymentMethodLabel(),
            'payment_method_code' => $transaction->paymentMethod?->code,
            'has_payment_slip' => (bool) $transaction->payment_slip,
            'paid_at' => $transaction->paid_at?->toISOString(),
            'created_at' => $transaction->created_at?->toISOString(),
        ];
    }

    /**
     * The same columns the legacy CSV carried, in the same order.
     *
     * @return array<int, mixed>
     */
    public function toCsvRow(): array
    {
        /** @var Transaction $transaction */
        $transaction = $this->resource;

        return [
            $transaction->reference,
            $transaction->order?->order_number ?? $transaction->invoice?->invoice_number ?? '',
            $this->store()?->name ?? '',
            $this->customerName() ?? '',
            $this->paymentMethodLabel() ?? '',
            number_format((float) $transaction->amount, 2, '.', ''),
            $transaction->fee_kobo !== null ? number_format(Naira::floatFromKobo($transaction->fee_kobo), 2, '.', '') : '',
            $transaction->net_kobo !== null ? number_format(Naira::floatFromKobo($transaction->net_kobo), 2, '.', '') : '',
            $transaction->currency ?? '',
            $transaction->status_label,
            $transaction->created_at?->toDateTimeString() ?? '',
        ];
    }

    protected function store(): ?Store
    {
        /** @var Transaction $transaction */
        $transaction = $this->resource;

        return $transaction->order?->store ?? $transaction->invoice?->store;
    }

    protected function customerName(): ?string
    {
        /** @var Transaction $transaction */
        $transaction = $this->resource;

        $context = $this->customerContext($transaction->order);

        return $context['name'] ?? $transaction->invoice?->recipient_name;
    }

    protected function paymentMethodLabel(): ?string
    {
        /** @var Transaction $transaction */
        $transaction = $this->resource;

        $method = $transaction->paymentMethod;

        if (! $method) {
            return null;
        }

        return match ($method->code) {
            'cash' => 'Cash',
            'bank_transfer' => 'Bank Transfer',
            'paystack' => 'Paystack (Card)',
            default => $method->name,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function customerContext(?Order $order): ?array
    {
        if (! $order) {
            return null;
        }

        $customer = $order->customer;
        $meta = $order->meta ?? [];
        $email = (string) $customer?->email;
        $isWalkIn = ! $customer
            || str_contains($email, 'walkin@pos.local')
            || str_contains($email, '@walkin.local');

        if (! $isWalkIn) {
            return [
                'name' => $customer->full_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'is_walk_in' => false,
            ];
        }

        // Walk-ins carry a placeholder account (`walkin@pos.local`,
        // `pos-…@walkin.local`). Legacy never rendered that address — it fell
        // back to the name/phone captured on the order.
        if (($meta['customer_name'] ?? null) !== null) {
            return [
                'name' => $meta['customer_name'],
                'email' => null,
                'phone' => $meta['customer_phone'] ?? null,
                'is_walk_in' => true,
            ];
        }

        if ($customer) {
            return [
                'name' => $customer->full_name,
                'email' => null,
                'phone' => $customer->phone,
                'is_walk_in' => true,
            ];
        }

        return null;
    }
}
