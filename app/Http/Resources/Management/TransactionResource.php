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
        'Amount', 'Processing Fee', 'Net', 'Currency', 'Status', 'Date',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Transaction $transaction */
        $transaction = $this->resource;
        $store = $this->store();
        $processingFee = $transaction->processingFeeKobo();
        $net = $transaction->netKobo();

        return [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'amount' => (float) $transaction->amount,
            // Everything taken out before the money landed, as one figure: the
            // provider's charge plus our commission. Named for what the business
            // experiences, not for its two parts — `fee_kobo` and
            // `platform_fee_kobo` still hold those separately on the row, and
            // Storify's own books still show them apart.
            'processing_fee' => $processingFee !== null ? Naira::floatFromKobo($processingFee) : null,
            // Deprecated alias, byte-for-byte the same figure. Both SPAs read
            // `fee` today and this change removes what it used to mean — the
            // provider's fee alone — so dropping the key in the same release as
            // the value changes would blank a live column for whichever of the
            // two deploys (SPAs on push, API by hand) landed second. Remove once
            // both SPAs read `processing_fee`.
            'fee' => $processingFee !== null ? Naira::floatFromKobo($processingFee) : null,
            // Derived, not read from the column: rows settled before this change
            // carry a `net_kobo` that subtracted only the provider's fee.
            'net' => $net !== null ? Naira::floatFromKobo($net) : null,
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

        // The same two figures the list row renders, from the same two methods,
        // so the CSV cannot disagree with what was on screen when it was asked
        // for. Blank rather than zero where a fee is not yet known.
        $processingFee = $transaction->processingFeeKobo();
        $net = $transaction->netKobo();

        return [
            $transaction->reference,
            $transaction->order?->order_number ?? $transaction->invoice?->invoice_number ?? '',
            $this->store()?->name ?? '',
            $this->customerName() ?? '',
            $this->paymentMethodLabel() ?? '',
            number_format((float) $transaction->amount, 2, '.', ''),
            $processingFee !== null ? number_format(Naira::floatFromKobo($processingFee), 2, '.', '') : '',
            $net !== null ? number_format(Naira::floatFromKobo($net), 2, '.', '') : '',
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
