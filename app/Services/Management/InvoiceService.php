<?php

namespace App\Services\Management;

use App\Enums\InvoiceStatus;
use App\Enums\TransactionStatus;
use App\Mail\InvoiceMail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Management\InvoiceRepository;
use App\Services\Accounting\LedgerPostingService;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * WS-21 — the invoice workflows: create/update, send/remind, mark-paid,
 * partial record-payment, void and draft delete.
 *
 * Money columns on `invoices` are naira decimals (the schema legacy shipped),
 * so every computation happens in integer kobo and only converts at the
 * persistence boundary — no float arithmetic ever decides a stored amount.
 * The converters are the pre-refactor controller's pair, named for their
 * contracts in App\Support\Money\Naira: `koboFromRounded` in, `floatFromKobo`
 * out (the output is persisted as a decimal, so it must stay a float).
 *
 * The controller keeps the HTTP shape (status codes, refusal messages, the
 * envelope); this service owns the multi-table workflows, their
 * DB::transaction boundaries, the payment-token/mail step, the ledger
 * posting (which runs after the transaction commits — the same point in the
 * sequence as before the layering) and the per-row money maths the response
 * layer renders.
 */
final class InvoiceService
{
    public function __construct(
        private readonly InvoiceRepository $repository,
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * Create a draft/sent invoice with its line items, optionally saving the
     * recipient as a reusable customer.
     *
     * Legacy's "Save & Send" mailed the invoice directly from store() and
     * deliberately skipped the ledger posting — the asymmetry the verify pass
     * asked the port to keep. (The explicit POST .../send does post.)
     */
    public function createInvoice(User $user, array $data, bool $finalize): Invoice
    {
        $totals = $this->computeTotals($data);

        $invoice = DB::transaction(function () use ($user, $data, $totals, $finalize) {
            $invoice = Invoice::create([
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'store_id' => $data['store_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'recipient_name' => $data['recipient_name'] ?? null,
                'recipient_email' => $data['recipient_email'] ?? null,
                'recipient_phone' => $data['recipient_phone'] ?? null,
                'recipient_address' => $data['recipient_address'] ?? null,
                'status' => $finalize ? InvoiceStatus::SENT : InvoiceStatus::DRAFT,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'subtotal' => $totals['subtotal'],
                'tax_rate' => $totals['tax_rate'],
                'tax_amount' => $totals['tax_amount'],
                'discount_type' => $this->discountType($data),
                'discount_value' => $totals['discount_value'],
                'total' => $totals['total'],
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
            ]);

            $this->replaceItems($invoice, $data['items']);
            $this->maybeSaveCustomer($invoice, $data);

            return $invoice;
        });

        if ($finalize) {
            $this->sendInvoiceEmail($invoice);
        }

        Log::info('api.management.invoice_created', [
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
        ]);

        return $invoice;
    }

    /**
     * Replace a draft invoice's contents; "Save & Send" mails (without ledger
     * posting) exactly like create.
     */
    public function updateInvoice(Invoice $invoice, array $data, bool $finalize): void
    {
        $totals = $this->computeTotals($data);

        DB::transaction(function () use ($invoice, $data, $totals, $finalize) {
            $invoice->update([
                'store_id' => $data['store_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'recipient_name' => $data['recipient_name'] ?? null,
                'recipient_email' => $data['recipient_email'] ?? null,
                'recipient_phone' => $data['recipient_phone'] ?? null,
                'recipient_address' => $data['recipient_address'] ?? null,
                'status' => $finalize ? InvoiceStatus::SENT : InvoiceStatus::DRAFT,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'subtotal' => $totals['subtotal'],
                'tax_rate' => $totals['tax_rate'],
                'tax_amount' => $totals['tax_amount'],
                'discount_type' => $this->discountType($data),
                'discount_value' => $totals['discount_value'],
                'total' => $totals['total'],
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
            ]);

            $this->replaceItems($invoice, $data['items']);
            $this->maybeSaveCustomer($invoice, $data);
        });

        if ($finalize) {
            $this->sendInvoiceEmail($invoice);
        }
    }

    /**
     * Settle the invoice in full: one confirmed manual transaction, the paid
     * columns updated and the store balance credited, all in one transaction.
     *
     * Returns the created transaction, or a failure message when the locked
     * row refuses payment — the controller turns that into its legacy 422.
     *
     * @return array{transaction: ?Transaction, failure: ?string}
     */
    public function markPaid(Invoice $invoice, User $user): array
    {
        $transaction = null;
        $failure = null;

        DB::transaction(function () use ($invoice, $user, &$transaction, &$failure) {
            $locked = $this->repository->findForUpdate($invoice->id);

            if (in_array($locked->status, [InvoiceStatus::PAID, InvoiceStatus::VOID], true)) {
                $failure = 'This invoice is already '.$locked->status->label().'.';

                return;
            }

            $remainingKobo = $this->remainingKobo($locked);

            if ($remainingKobo <= 0) {
                $failure = 'This invoice has no remaining balance.';

                return;
            }

            $transaction = Transaction::create([
                'reference' => 'PMT-'.strtoupper(Str::random(12)),
                'invoice_id' => $locked->id,
                'business_id' => $locked->business_id,
                'amount' => Naira::floatFromKobo($remainingKobo),
                'currency' => $this->currencyOf($locked),
                'status' => TransactionStatus::CONFIRMED,
                'paid_at' => now(),
                'metadata' => [
                    'method' => 'manual',
                    'source' => 'mark_paid',
                    'recorded_by' => $user->id,
                ],
            ]);

            $locked->amount_paid = Naira::floatFromKobo($this->paidKobo($locked) + $remainingKobo);
            $locked->status = InvoiceStatus::PAID;
            $locked->paid_at = now();
            $locked->save();

            $this->creditStoreBalance($locked, $transaction, $remainingKobo);

            Log::info('api.management.invoice_marked_paid', [
                'invoice_id' => $locked->id,
                'invoice_number' => $locked->invoice_number,
                'transaction_id' => $transaction->id,
                'amount' => Naira::floatFromKobo($remainingKobo),
                'recorded_by' => $user->id,
            ]);
        });

        if ($failure === null) {
            $this->ledger->safe(function () use ($invoice, $user, $transaction) {
                $this->ledger->postInvoice($invoice->fresh(), $user->id);

                if ($transaction) {
                    $this->ledger->postPaymentReceived($transaction, $user->id);
                }
            });
        }

        return ['transaction' => $transaction, 'failure' => $failure];
    }

    /**
     * A manual (partial) payment: one confirmed transaction, the paid columns
     * advanced (paid when it settles the balance, otherwise partial) and the
     * store balance credited, all in one transaction.
     *
     * The amount is re-checked against the locked row — a concurrent payment
     * may have moved the remaining balance since validation ran — and refused
     * with the same 422 message either way.
     */
    public function recordPayment(Invoice $invoice, User $user, array $data, int $amountKobo): Transaction
    {
        $transaction = DB::transaction(function () use ($invoice, $user, $data, $amountKobo) {
            $locked = $this->repository->findForUpdate($invoice->id);

            $lockedRemaining = $this->remainingKobo($locked);

            if ($amountKobo > $lockedRemaining) {
                throw ValidationException::withMessages([
                    'amount' => 'Amount cannot exceed the remaining balance of ₦'.number_format(Naira::floatFromKobo($lockedRemaining), 2).'.',
                ]);
            }

            $transaction = Transaction::create([
                'reference' => 'PMT-'.strtoupper(Str::random(12)),
                'invoice_id' => $locked->id,
                'business_id' => $locked->business_id,
                'amount' => Naira::floatFromKobo($amountKobo),
                'currency' => $this->currencyOf($locked),
                'status' => TransactionStatus::CONFIRMED,
                'paid_at' => now(),
                'metadata' => [
                    'method' => 'manual',
                    'source' => $data['payment_method'],
                    'recorded_by' => $user->id,
                    'note' => $data['note'] ?? null,
                ],
            ]);

            $locked->amount_paid = Naira::floatFromKobo($this->paidKobo($locked) + $amountKobo);

            if ($this->paidKobo($locked) >= Naira::koboFromRounded($locked->total)) {
                $locked->status = InvoiceStatus::PAID;
                $locked->paid_at = now();
            } elseif ($this->paidKobo($locked) > 0) {
                $locked->status = InvoiceStatus::PARTIAL;
            }

            $locked->save();

            $this->creditStoreBalance($locked, $transaction, $amountKobo);

            Log::info('api.management.invoice_payment_recorded', [
                'invoice_id' => $locked->id,
                'invoice_number' => $locked->invoice_number,
                'transaction_id' => $transaction->id,
                'amount' => Naira::floatFromKobo($amountKobo),
                'method' => $data['payment_method'],
                'recorded_by' => $user->id,
                'store_balance_before' => $transaction->store_balance_before,
                'store_balance_after' => $transaction->store_balance_after,
            ]);

            return $transaction;
        });

        // Post-commit, exactly where the controller used to call it.
        $this->ledger->safe(function () use ($invoice, $user, $transaction) {
            $this->ledger->postInvoice($invoice->fresh(), $user->id);
            $this->ledger->postPaymentReceived($transaction, $user->id);
        });

        return $transaction;
    }

    /**
     * "Send Invoice" / "Remind": issue/reuse the token and queue the mail,
     * then post the invoice to the ledger once a recipient was reachable.
     *
     * Returns the address the mail was queued to, or null when none is
     * reachable (the controller answers its legacy 422). Mail failures
     * propagate — the controller maps them to its 500; and when no recipient
     * is reachable the ledger is deliberately not posted, as before.
     */
    public function send(Invoice $invoice, User $user): ?string
    {
        $to = $this->sendInvoiceEmail($invoice);

        if (! $to) {
            return null;
        }

        $this->ledger->safe(fn () => $this->ledger->postInvoice($invoice->fresh(), $user->id));

        return $to;
    }

    /**
     * Legacy also stamped `voided_at`; refusing a paid invoice is the one
     * guard legacy lacked and it stays on the controller.
     */
    public function void(Invoice $invoice, User $user): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice->update([
                'status' => InvoiceStatus::VOID,
                'voided_at' => now(),
            ]);
        });

        Log::info('api.management.invoice_voided', [
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
        ]);
    }

    /**
     * Draft-only delete (the controller enforces the draft guard).
     */
    public function delete(Invoice $invoice, User $user): void
    {
        $invoice->delete();

        Log::info('api.management.invoice_deleted', [
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
        ]);
    }

    /**
     * Issue/reuse the 32-char public payment token, queue `InvoiceMail` (with
     * the payment URL) and stamp `sent_at` / draft -> sent.
     *
     * @return string|null the address the mail was queued to, null when none is reachable
     */
    public function sendInvoiceEmail(Invoice $invoice): ?string
    {
        $to = $invoice->recipient_email ?: $invoice->customer?->email;

        if (! $to || str_contains($to, '@walkin.local')) {
            $to = config('mail.from.address');
        }

        if (! $to) {
            return null;
        }

        $this->repository->loadForMail($invoice);

        if (! $invoice->payment_token) {
            $invoice->payment_token = Str::random(32);
            $invoice->save();
        }

        Mail::to($to)->queue(new InvoiceMail(
            $invoice,
            route('invoice.pay.show', ['token' => $invoice->payment_token]),
        ));

        if ($invoice->isDraft()) {
            $invoice->status = InvoiceStatus::SENT;
        }

        // Legacy only stamped `sent_at` when moving out of draft, so invoices
        // born on "Save & Send" never showed when they were sent.
        if (! $invoice->sent_at) {
            $invoice->sent_at = now();
        }

        $invoice->save();

        return $to;
    }

    /**
     * Remaining balance in kobo; null-tolerant like the legacy helper — money
     * columns are nullable on legacy rows and a half-filled invoice reads as
     * ₦0 rather than throwing.
     */
    public function remainingKobo(Invoice $invoice): int
    {
        return max(0, Naira::koboFromRounded($invoice->total) - $this->paidKobo($invoice));
    }

    /**
     * The real deduction in kobo. The legacy views treated `discount_value`
     * as an amount even for a percentage; this resolves the true value.
     */
    public function discountKobo(Invoice $invoice): int
    {
        $subtotalKobo = Naira::koboFromRounded($invoice->subtotal);
        $taxKobo = Naira::koboFromRounded($invoice->tax_amount);
        $valueKobo = Naira::koboFromRounded($invoice->discount_value);

        $discountKobo = $invoice->discount_type === 'percentage'
            ? (int) round($subtotalKobo * (float) $invoice->discount_value / 100)
            : $valueKobo;

        return min($discountKobo, $subtotalKobo + $taxKobo);
    }

    private function paidKobo(Invoice $invoice): int
    {
        return Naira::koboFromRounded($invoice->amount_paid);
    }

    /**
     * Server-side totals in integer kobo. Client-supplied subtotal/total are
     * ignored and the discount is clamped so the total can never go negative
     * (legacy `computeInvoiceTotals` semantics).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, float>
     */
    private function computeTotals(array $data): array
    {
        $subtotalKobo = 0;

        foreach ($data['items'] as $item) {
            $subtotalKobo += ((int) $item['quantity']) * Naira::koboFromRounded($item['unit_price']);
        }

        $taxRate = round((float) ($data['tax_rate'] ?? 0), 2);
        $taxKobo = (int) round($subtotalKobo * $taxRate / 100);

        $discountValue = round((float) ($data['discount_value'] ?? 0), 2);
        $discountKobo = $this->discountType($data) === 'percentage'
            ? (int) round($subtotalKobo * $discountValue / 100)
            : Naira::koboFromRounded($discountValue);

        $discountKobo = min($discountKobo, $subtotalKobo + $taxKobo);
        $totalKobo = max(0, $subtotalKobo + $taxKobo - $discountKobo);

        return [
            'subtotal' => Naira::floatFromKobo($subtotalKobo),
            'tax_rate' => $taxRate,
            'tax_amount' => Naira::floatFromKobo($taxKobo),
            'discount_value' => $discountValue,
            'discount_amount' => Naira::floatFromKobo($discountKobo),
            'total' => Naira::floatFromKobo($totalKobo),
        ];
    }

    /**
     * @param  array<int, array{description: string, quantity: int|string, unit_price: float|int|string}>  $items
     */
    private function replaceItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();

        foreach ($items as $index => $item) {
            $unitPriceKobo = Naira::koboFromRounded($item['unit_price']);
            $lineKobo = ((int) $item['quantity']) * $unitPriceKobo;

            $invoice->items()->create([
                'description' => $item['description'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => Naira::floatFromKobo($unitPriceKobo),
                'amount' => Naira::floatFromKobo($lineKobo),
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * "Save as customer for future invoices". Unlike legacy — which created a
     * fresh duplicate for every invoice — an existing customer with the same
     * email is reused, and the customer is linked to the invoice so its
     * contact card is populated.
     *
     * @param  array<string, mixed>  $data
     */
    private function maybeSaveCustomer(Invoice $invoice, array $data): void
    {
        if (! ($data['save_customer'] ?? false)) {
            return;
        }

        if (empty($data['recipient_email']) || ! empty($data['customer_id'])) {
            return;
        }

        $email = $data['recipient_email'];

        $customer = $this->repository->findCustomerByEmail($invoice->business_id, $email);

        if (! $customer) {
            $nameParts = explode(' ', trim($data['recipient_name'] ?? ''), 2);

            $customer = Customer::create([
                'business_id' => $invoice->business_id,
                'first_name' => $nameParts[0] ?: 'Customer',
                'last_name' => $nameParts[1] ?? '',
                'email' => $email,
                // `phone` and `password` are NOT NULL on `customers`. An
                // invoicing contact is not meant to sign in, so the password is
                // an unguessable hash rather than a usable credential.
                'phone' => $data['recipient_phone'] ?? '',
                'password' => Hash::make(Str::random(40)),
                'status' => Customer::STATUS_ACTIVE,
            ]);
        }

        $invoice->update(['customer_id' => $customer->id]);
    }

    /**
     * Mirrors the house idiom in `TransactionController::confirm` (lock +
     * `Store::creditBalance()` + before/after audit fields).
     */
    private function creditStoreBalance(Invoice $invoice, Transaction $transaction, int $amountKobo): void
    {
        $store = $invoice->store;

        if (! $store) {
            return;
        }

        $store->lockForUpdate();
        $balanceBefore = (int) $store->balance;
        $store->creditBalance($amountKobo);

        $transaction->update([
            'balance_updated_at' => now(),
            'store_balance_before' => $balanceBefore,
            'store_balance_after' => (int) $store->fresh()->balance,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function discountType(array $data): ?string
    {
        return in_array($data['discount_type'] ?? null, ['fixed', 'percentage'], true)
            ? $data['discount_type']
            : null;
    }

    private function currencyOf(Invoice $invoice): string
    {
        return $invoice->business?->currency ?: 'NGN';
    }
}
