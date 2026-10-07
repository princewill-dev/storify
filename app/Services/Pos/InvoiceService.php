<?php

namespace App\Services\Pos;

use App\Enums\InvoiceStatus;
use App\Enums\TransactionStatus;
use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\ServiceCharge;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The POS invoice workflows (legacy `Pos\InvoiceController`): create with
 * line items, the optional send, the manual payment and the send action
 * itself.
 *
 * Money handling is kept verbatim from the pre-refactor controller, floats
 * and all: POS invoice and item columns are naira decimals, the create path
 * rounds each computed naira figure with `round(..., 2)` and the payment
 * path truncates the operator's naira amount to kobo with
 * `(int) ($amount * 100)` before crediting the store. That truncation is NOT
 * any of the contracts in App\Support\Money\Naira (they round or parse
 * exactly — 0.29 naira is 28 kobo here, 29 there), so the expression stays
 * as it was rather than being silently upgraded to a different amount.
 *
 * The service owns the DB::transaction boundaries. Note where the send step
 * sits: `send()` is called from INSIDE the create transaction, exactly the
 * point the controller used it, and it keeps its swallow-and-log contract
 * (a mail failure leaves the invoice created, as before).
 */
final class InvoiceService
{
    /**
     * Create the invoice with its line items, applying the store's active
     * service charge when one is selected.
     *
     * The client-supplied `total` is validated but never trusted: the server
     * recomputes subtotal and total from the line items, the tax amount, the
     * discount and the service charge, exactly as the pre-refactor controller
     * did (the "service charge applied exactly once" regression test pins
     * this).
     *
     * @param  array<string, mixed>  $data
     */
    public function createInvoice(Store $store, User $user, array $data): Invoice
    {
        return DB::transaction(function () use ($store, $user, $data) {
            $subtotal = round(collect($data['items'])->sum(fn (array $item) => (int) $item['quantity'] * (float) $item['unit_price']), 2);

            $serviceChargeAmount = 0.0;

            if ($data['service_charge_id'] ?? null) {
                $charge = ServiceCharge::where('store_id', $store->id)->where('is_active', true)->find($data['service_charge_id']);
                $serviceChargeAmount = (float) ($charge?->amount ?? 0);
            }

            $total = round(
                $subtotal
                + (float) ($data['tax_amount'] ?? 0)
                - (float) ($data['discount_value'] ?? 0)
                + $serviceChargeAmount,
                2
            );

            $invoice = Invoice::create([
                'business_id' => $store->business_id,
                'user_id' => $user->id,
                'store_id' => $store->id,
                'customer_id' => $data['customer_id'] ?? null,
                'recipient_name' => $data['recipient_name'],
                'recipient_email' => $data['recipient_email'] ?? null,
                'recipient_phone' => $data['recipient_phone'] ?? null,
                'status' => InvoiceStatus::DRAFT,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'subtotal' => $subtotal,
                'tax_rate' => $data['tax_rate'] ?? 0,
                'tax_amount' => $data['tax_amount'] ?? 0,
                'discount_value' => $data['discount_value'] ?? 0,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'payment_token' => Str::random(32),
            ]);

            foreach ($data['items'] as $i => $item) {
                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => (float) $item['unit_price'],
                    'amount' => (int) $item['quantity'] * (float) $item['unit_price'],
                    'sort_order' => $i,
                ]);
            }

            if ($data['send_now'] ?? false) {
                $this->send($invoice);
            }

            return $invoice->load('items');
        });
    }

    /**
     * Record one confirmed manual payment: the transaction row, the
     * amount_paid/status advance and the store balance credit, all in one
     * transaction. A concurrent request may have moved the remaining balance
     * since the request was validated — the legacy code did not re-check and
     * neither does this.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordPayment(Invoice $invoice, User $user, array $data): void
    {
        DB::transaction(function () use ($invoice, $user, $data) {
            Transaction::create([
                'reference' => 'PMT-'.strtoupper(Str::random(12)),
                'invoice_id' => $invoice->id,
                'business_id' => $invoice->business_id,
                'amount' => $data['amount'],
                'currency' => 'NGN',
                'status' => TransactionStatus::CONFIRMED,
                'paid_at' => now(),
                'metadata' => [
                    'method' => 'manual',
                    'source' => $data['payment_method'],
                    'recorded_by' => $user->id,
                ],
            ]);

            $invoice->amount_paid = (float) $invoice->amount_paid + (float) $data['amount'];

            if ($invoice->isFullyPaid()) {
                $invoice->status = InvoiceStatus::PAID;
                $invoice->paid_at = now();
            } elseif ($invoice->amount_paid > 0) {
                $invoice->status = InvoiceStatus::PARTIAL;
            }

            $invoice->save();

            if ($invoice->store) {
                // Legacy truncation, kept deliberately: this is not
                // Naira::koboFromRounded (which rounds), and no Naira contract
                // truncates, so the expression is not swapped for one.
                $invoice->store->creditBalance((int) ($data['amount'] * 100));
            }
        });
    }

    /**
     * Send the invoice mail: issue/reuse the public payment token, queue
     * `InvoiceMail` with the payment URL and move a draft to sent/stamped.
     *
     * The walk-in placeholder address is skipped, and — as before — when no
     * recipient is reachable the method returns quietly and the caller still
     * answers "Invoice sent.". Failures are logged, not surfaced: this is the
     * soft-send contract the POS endpoint shipped with (unlike the management
     * module, which reports the failure).
     */
    public function send(Invoice $invoice): void
    {
        $to = $invoice->recipient_email ?: $invoice->customer?->email;

        if (! $to || str_contains($to, '@walkin.local')) {
            return;
        }

        try {
            $invoice->load(['items', 'store']);

            if (! $invoice->payment_token) {
                $invoice->payment_token = Str::random(32);
                $invoice->save();
            }

            $paymentUrl = route('invoice.pay.show', ['token' => $invoice->payment_token]);

            Mail::to($to)->queue(new InvoiceMail($invoice, $paymentUrl));

            if ($invoice->isDraft()) {
                $invoice->update(['status' => InvoiceStatus::SENT, 'sent_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::error('pos_invoice_send_failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
        }
    }
}
