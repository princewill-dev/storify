<?php

namespace App\Http\Resources\Storefront;

use App\Enums\TransactionStatus;
use App\Models\Invoice;
use App\Models\Store;
use App\Models\Transaction;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The invoice document a customer receives by email, shaped for the
 * storefront's standalone invoice page.
 *
 * The page renders outside `StorefrontLayout` — an invoice must still be
 * payable when the owner has switched the shop off, and a payer should not get
 * a cart and a nav around somebody's bill — so every piece of branding the page
 * shows travels here. The store block is the storefront host's store, which is
 * the one the customer followed the link to; the invoice's own `store_id` may
 * be null for a business-level invoice.
 *
 * Only non-pending payments are listed. A pending row is an initialization that
 * may never be paid, and showing it in a document titled "Payment history"
 * would claim a payment happened.
 *
 * @property Invoice $resource
 */
final class StorefrontInvoiceResource extends JsonResource
{
    public function __construct(Invoice $invoice, private readonly Store $store)
    {
        parent::__construct($invoice);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->resource;

        // The business's currency, as everywhere the invoice is read. A
        // business-level invoice has no store to ask.
        $currency = strtoupper((string) ($invoice->business?->currency ?: 'NGN'));

        return [
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency' => $currency,
            'recipient_name' => $invoice->recipient_name ?: $invoice->customer?->full_name,
            'recipient_address' => $invoice->recipient_address,
            'subtotal' => (float) $invoice->subtotal,
            'tax_rate' => (float) $invoice->tax_rate,
            'tax_amount' => (float) $invoice->tax_amount,
            'discount_type' => $invoice->discount_type,
            'discount_value' => (float) $invoice->discount_value,
            'discount_amount' => Naira::floatFromKobo($this->discountKobo($invoice)),
            'total' => (float) $invoice->total,
            'amount_paid' => (float) $invoice->amount_paid,
            'remaining' => $invoice->remainingBalance(),
            'notes' => $invoice->notes,
            'terms' => $invoice->terms,
            'paid_at' => $invoice->paid_at?->toISOString(),
            'items' => $invoice->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'amount' => (float) $item->amount,
            ])->values()->all(),
            'transactions' => $invoice->transactions
                ->where('status', '!=', TransactionStatus::PENDING)
                ->sortByDesc('id')
                ->values()
                ->map(fn (Transaction $transaction) => [
                    'reference' => $transaction->reference,
                    'amount' => (float) $transaction->amount,
                    'status' => $transaction->status instanceof TransactionStatus
                        ? $transaction->status->value
                        : $transaction->status,
                    'status_label' => $transaction->status instanceof TransactionStatus
                        ? $transaction->status->label()
                        : ucfirst((string) $transaction->status),
                    'paid_at' => $transaction->paid_at?->toISOString(),
                    'created_at' => $transaction->created_at?->toISOString(),
                ])->all(),
            'store' => [
                'name' => $this->store->name,
                'slug' => $this->store->slug,
                'logo_url' => $this->store->logo_path
                    ? asset('storage/'.$this->store->logo_path)
                    : null,
                'support_email' => $this->store->support_email,
                'support_phone' => $this->store->support_phone,
            ],
            'business_name' => $invoice->business?->name ?? $this->store->name,
        ];
    }

    /**
     * The real deduction in kobo — the same percentage/amount rule as
     * App\Services\Management\InvoiceService::discountKobo(), clamped so the
     * document can never show more discount than there is subtotal and tax.
     */
    private function discountKobo(Invoice $invoice): int
    {
        $subtotalKobo = Naira::koboFromRounded($invoice->subtotal);
        $taxKobo = Naira::koboFromRounded($invoice->tax_amount);
        $valueKobo = Naira::koboFromRounded($invoice->discount_value);

        $discountKobo = $invoice->discount_type === 'percentage'
            ? (int) round($subtotalKobo * (float) $invoice->discount_value / 100)
            : $valueKobo;

        return min($discountKobo, $subtotalKobo + $taxKobo);
    }
}
