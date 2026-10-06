<?php

namespace App\Http\Resources\Management;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * WS-18 — full detail read model: order context, customer block, balance
 * impact, payment slip and the structured rejection/refund reasons.
 */
class TransactionDetailResource extends TransactionResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Transaction $transaction */
        $transaction = $this->resource;
        $store = $this->store();
        $meta = $transaction->metadata ?? [];

        return [
            ...parent::toArray($request),
            'gateway_reference' => $transaction->gateway_reference,
            'store_balance_before' => $transaction->store_balance_before !== null ? (int) $transaction->store_balance_before : null,
            'store_balance_after' => $transaction->store_balance_after !== null ? (int) $transaction->store_balance_after : null,
            'store_balance_current' => $store ? (int) $store->balance : null,
            'balance_updated_at' => $transaction->balance_updated_at?->toISOString(),
            'bank' => $transaction->storeBank ? [
                'bank_name' => $transaction->storeBank->bank_name,
                'account_number' => $transaction->storeBank->account_number,
                'account_name' => $transaction->storeBank->account_name,
                'is_verified' => (bool) $transaction->storeBank->is_verified,
            ] : null,
            'payment_slip' => $this->slip($transaction),
            // Structured replacement for the legacy raw metadata panel, which
            // the SPA used to dump as JSON.
            'rejection' => array_key_exists('rejection_reason', $meta) ? [
                'reason' => $meta['rejection_reason'],
                'at' => $meta['rejected_at'] ?? null,
                'by' => $meta['rejected_by'] ?? null,
                'by_name' => $this->actorName($meta['rejected_by'] ?? null),
            ] : null,
            'refund' => array_key_exists('refund_reason', $meta) ? [
                'reason' => $meta['refund_reason'],
                'at' => $meta['refunded_at'] ?? null,
                'by' => $meta['refunded_by'] ?? null,
                'by_name' => $this->actorName($meta['refunded_by'] ?? null),
                'balance_before' => $meta['refund_balance_before'] ?? null,
                'balance_after' => $meta['refund_balance_after'] ?? null,
            ] : null,
            'order_context' => $transaction->order ? $this->orderContext($transaction->order) : null,
            // Legacy rendered no invoice block at all; a compact one answers
            // "which invoice is this money against" without a second screen.
            'invoice_context' => $transaction->invoice ? $this->invoiceContext($transaction->invoice) : null,
            'customer_context' => $this->customerContext($transaction->order),
            'metadata' => $meta,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function slip(Transaction $transaction): ?array
    {
        if (! $transaction->payment_slip) {
            return null;
        }

        $extension = strtolower(pathinfo($transaction->payment_slip, PATHINFO_EXTENSION));

        return [
            'path' => $transaction->payment_slip,
            'url' => Storage::disk('public')->url($transaction->payment_slip),
            'is_image' => in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true),
            'uploaded_at' => $transaction->paid_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderContext(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'status_label' => $order->status_label,
            'items_count' => $order->items->count(),
            'subtotal' => (float) $order->subtotal,
            'shipping_fee' => (float) $order->shipping_fee,
            'tax' => (float) $order->tax,
            'service_charge' => $this->serviceCharge($order),
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'remaining' => $order->remainingBalance(),
            'source' => $order->source,
            'is_pos' => $order->isPos(),
            'staff' => $order->staff ? [
                'id' => $order->staff->id,
                'name' => $order->staff->name,
                'email' => $order->staff->email,
            ] : null,
            'store' => $order->store ? [
                'id' => $order->store->id,
                'name' => $order->store->name,
                'store_id' => $order->store->store_id,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceContext(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status instanceof InvoiceStatus ? $invoice->status->value : $invoice->status,
            'status_label' => $invoice->status instanceof InvoiceStatus ? $invoice->status->label() : ucfirst((string) $invoice->status),
            'total' => (float) $invoice->total,
            'amount_paid' => (float) $invoice->amount_paid,
            'recipient_name' => $invoice->recipient_name,
            'recipient_email' => $invoice->recipient_email,
            'store' => $invoice->store ? [
                'id' => $invoice->store->id,
                'name' => $invoice->store->name,
                'store_id' => $invoice->store->store_id,
            ] : null,
        ];
    }

    /**
     * Legacy fell back to the stored columns and then to whatever the total
     * left over after subtotal/shipping/tax.
     *
     * @return array{name: string|null, amount: float}
     */
    private function serviceCharge(Order $order): array
    {
        $meta = $order->meta ?? [];

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

    private function actorName($actorId): ?string
    {
        if (! $actorId) {
            return null;
        }

        return User::query()->whereKey($actorId)->value('name');
    }
}
