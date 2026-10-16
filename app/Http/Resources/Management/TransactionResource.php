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
            // Everything taken out before the money landed, as one figure. Named
            // for what the business experiences, not for its parts — and what
            // those parts are depends on the sale: both the provider's charge and
            // our commission on a sale charged through the business's own keys,
            // our commission alone on one Storify charged for it, where the
            // provider's charge is our own cost and is not the business's to pay.
            // Either way the row holds them separately (`fee_kobo`,
            // `platform_fee_kobo`) and Storify's own books show them apart.
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

            // Where this sale's money is. Null for a sale the business charged on
            // its own account, which has no wallet leg at all — see
            // {@see Transaction::settlementState()} for the full vocabulary.
            'settlement_state' => $transaction->settlementState(),
            // Named on the list row because it is the answer to the question the
            // business opens the list to ask: which transfer carried this sale. Null
            // until a payout claims it.
            'payout_reference' => $transaction->claimingAllocation()?->payout?->reference,

            // The list inherits the detail payload's block, so the same figures are
            // available without a second request.
            'settlement' => $this->settlementBlock($transaction),
        ];
    }

    /**
     * The settlement block shared by the list and the detail payload.
     *
     * @return array<string, mixed>|null
     */
    protected function settlementBlock(Transaction $transaction): ?array
    {
        if (! $transaction->isPlatformManaged()) {
            return null;
        }

        $allocation = $transaction->claimingAllocation();
        $payout = $allocation?->payout;

        return [
            'state' => $transaction->settlementState(),
            // When the provider released the money to Storify. Null while the sale is
            // still settling, and the reason a wallet shows money it will not pay yet.
            'settled_at' => $transaction->settle_at?->toISOString(),
            'payable_since' => $transaction->promoted_at?->toISOString(),
            // When it was refunded or charged back. A sale can be both paid out and
            // later unwound — the wallet is debited back and this records that it
            // happened, rather than erasing the payout that delivered it.
            'unwound_at' => $transaction->unwound_at?->toISOString(),
            'allocated_kobo' => $allocation !== null ? (int) $allocation->amount_kobo : 0,
            'payout' => $payout ? [
                'id' => (int) $payout->id,
                'reference' => $payout->reference,
                'status' => $payout->status,
                'amount_kobo' => (int) $payout->amount_kobo,
                'paid_at' => $payout->paid_at?->toISOString(),
                'failure_reason' => $payout->failure_reason,
            ] : null,
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
