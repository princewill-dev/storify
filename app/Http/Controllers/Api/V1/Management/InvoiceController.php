<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\InvoiceStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Mail\InvoiceMail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * WS-21 — Invoices (legacy `Management\InvoiceController`).
 *
 * The legacy module is reproduced end-to-end: list with stats/tabs/search,
 * draft-only create/edit, the printable DomPDF artefact, send/remind with a
 * public payment token, mark-paid and partial record-payment (both crediting
 * the store balance and posting to the ledger), void, and draft-only delete.
 *
 * Money columns on `invoices` are naira decimals (the schema legacy shipped),
 * so every computation happens in integer kobo here and only converts at the
 * persistence boundary — no float arithmetic ever decides a stored amount.
 *
 * Fixes over legacy, noted at their sites: `store_id`/`customer_id` are
 * checked against the business instead of a bare `exists:` rule (the legacy
 * rule let a user attach another business's store); `sent_at` is stamped for
 * "Save & Send" (legacy dropped it); the created customer is linked to the
 * invoice; and a paid invoice can no longer be voided.
 */
class InvoiceController extends ApiController
{
    use ResolvesManagementContext;

    /**
     * GET /management/invoices — stats row, status tabs, search, list.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_column(InvoiceStatus::cases(), 'value'))],
            'store_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = $this->baseQuery($request);

        $counts = (clone $base)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $stats = ['all' => (int) $counts->sum()];

        foreach (InvoiceStatus::cases() as $status) {
            $stats[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        // The legacy card row was All / Draft / Sent / Overdue / Revenue — no
        // Paid count — and counted only fully paid invoices as revenue.
        $stats['revenue'] = (float) (clone $base)->where('status', InvoiceStatus::PAID->value)->sum('total');

        $invoices = (clone $base)
            ->with(['customer:id,first_name,last_name,email,phone', 'store:id,name,store_id'])
            ->withCount('items')
            ->when($filters['q'] ?? null, fn ($query, $term) => $this->applySearch($query, $term))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['store_id'] ?? null, fn ($query, $storeId) => $query->where('store_id', $storeId))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok([
            'invoices' => $invoices->getCollection()->map(fn (Invoice $invoice) => $this->summary($invoice))->all(),
            'stats' => $stats,
            'statuses' => $this->statusOptions(),
            'currency' => $this->currency($request),
        ], null, 200, $this->paginationMeta($invoices));
    }

    /**
     * GET /management/invoices/form-options — the create/edit form pickers.
     *
     * Served from this module rather than the customers/stores endpoints: the
     * cashier role holds `invoices view` without `stores view`, so the form
     * must carry its own tenant-scoped options to stay usable.
     */
    public function formOptions(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $customers = Customer::where('business_id', $user->business_id)
            ->orderBy('first_name')
            ->limit(500)
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->full_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ])->all();

        // Inactive stores stay in the list (with a flag) so editing a draft
        // that points at one never loses its selection.
        $stores = $user->accessibleStores()
            ->orderBy('name')
            ->get()
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'store_id' => $store->store_id,
                'status' => $store->status,
            ])->all();

        return $this->ok([
            'customers' => $customers,
            'stores' => $stores,
            'defaults' => [
                // Legacy preselected the first reachable store on create and
                // defaulted the due date two weeks out.
                'store_id' => $stores[0]['id'] ?? null,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
            ],
            'statuses' => $this->statusOptions(),
            'currency' => $this->currency($request),
        ]);
    }

    /**
     * POST /management/invoices — totals are always recomputed here.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $this->validated($request, $user);
        $totals = $this->computeTotals($data);
        $finalize = $request->boolean('finalize');

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

        // Legacy's "Save & Send" mailed the invoice directly from store() and
        // deliberately skipped the ledger posting — the asymmetry the verify
        // pass asked the port to keep. (The explicit POST .../send does post.)
        if ($finalize) {
            $this->sendInvoiceEmail($invoice);
        }

        Log::info('api.management.invoice_created', [
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
        ]);

        return $this->ok(['invoice' => $this->detail($invoice)], 'Invoice created.', 201);
    }

    /**
     * GET /management/invoices/{invoice} — the document read model.
     */
    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        return $this->ok(['invoice' => $this->detail($invoice)]);
    }

    /**
     * PUT /management/invoices/{invoice} — draft-only (403 parity).
     */
    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        if (! $invoice->isDraft()) {
            return $this->error('Only draft invoices can be edited.', 403);
        }

        $user = $this->user($request);
        $data = $this->validated($request, $user);
        $totals = $this->computeTotals($data);
        $finalize = $request->boolean('finalize');

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

        return $this->ok(['invoice' => $this->detail($invoice->fresh())], 'Invoice updated.');
    }

    /**
     * DELETE /management/invoices/{invoice} — drafts only (403 parity).
     */
    public function destroy(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        if (! $invoice->isDraft()) {
            return $this->error('Only draft invoices can be deleted.', 403);
        }

        $invoice->delete();

        Log::info('api.management.invoice_deleted', [
            'user_id' => $this->user($request)->id,
            'invoice_id' => $invoice->id,
        ]);

        return $this->ok([], 'Invoice deleted.');
    }

    /**
     * GET /management/invoices/{invoice}/pdf — the print artefact.
     */
    public function pdf(Request $request, Invoice $invoice): Response
    {
        $this->authorizeInvoice($request, $invoice);

        $invoice->load(['items', 'store', 'customer']);

        $currency = $invoice->business?->currency ?: 'NGN';

        $pdf = Pdf::loadView('pdf.ws21.invoice', [
            'invoice' => $invoice,
            'discountAmount' => $this->naira($this->discountKobo($invoice)),
            'symbol' => $this->currencySymbol($currency),
        ]);

        return $pdf->download($invoice->invoice_number.'.pdf');
    }

    /**
     * POST /management/invoices/{invoice}/send — "Send Invoice" and "Remind".
     *
     * Issues/reuses the public `payment_token`, queues `InvoiceMail` with the
     * payment URL, moves draft -> sent, and posts the invoice to the ledger
     * (`postInvoice`). Legacy failed softly on mail errors; the honest port
     * surfaces the failure instead of pretending the invoice was sent.
     */
    public function send(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        if (in_array($invoice->status, [InvoiceStatus::PAID, InvoiceStatus::VOID], true)) {
            return $this->error('Cannot send a '.$invoice->status->label().' invoice.', 422);
        }

        $user = $this->user($request);

        try {
            $to = $this->sendInvoiceEmail($invoice);
        } catch (\Throwable $e) {
            Log::error('api.management.invoice_send_failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('The invoice email could not be queued. Please try again.', 500);
        }

        if (! $to) {
            return $this->error('Add a recipient email before sending this invoice.', 422);
        }

        $ledger = app(LedgerPostingService::class);
        $ledger->safe(fn () => $ledger->postInvoice($invoice->fresh(), $user->id));

        return $this->ok(['invoice' => $this->detail($invoice->fresh())], 'Invoice sent to '.$to.'.');
    }

    /**
     * POST /management/invoices/{invoice}/mark-paid — settle the balance.
     */
    public function markPaid(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        $user = $this->user($request);
        $transaction = null;
        $failure = null;

        DB::transaction(function () use ($invoice, $user, &$transaction, &$failure) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

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
                'amount' => $this->naira($remainingKobo),
                'currency' => $this->currencyOf($locked),
                'status' => TransactionStatus::CONFIRMED,
                'paid_at' => now(),
                'metadata' => [
                    'method' => 'manual',
                    'source' => 'mark_paid',
                    'recorded_by' => $user->id,
                ],
            ]);

            $locked->amount_paid = $this->naira($this->paidKobo($locked) + $remainingKobo);
            $locked->status = InvoiceStatus::PAID;
            $locked->paid_at = now();
            $locked->save();

            $this->creditStoreBalance($locked, $transaction, $remainingKobo);

            Log::info('api.management.invoice_marked_paid', [
                'invoice_id' => $locked->id,
                'invoice_number' => $locked->invoice_number,
                'transaction_id' => $transaction->id,
                'amount' => $this->naira($remainingKobo),
                'recorded_by' => $user->id,
            ]);
        });

        if ($failure !== null) {
            return $this->error($failure, 422);
        }

        $ledger = app(LedgerPostingService::class);
        $ledger->safe(function () use ($ledger, $invoice, $transaction, $user) {
            $ledger->postInvoice($invoice->fresh(), $user->id);

            if ($transaction) {
                $ledger->postPaymentReceived($transaction, $user->id);
            }
        });

        return $this->ok(['invoice' => $this->detail($invoice->fresh())], 'Invoice marked as paid.');
    }

    /**
     * POST /management/invoices/{invoice}/record-payment — manual (partial)
     * payment, confirmed with the operator's current password.
     *
     * The password gate is a deliberate legacy security feature (the audit
     * flagged it as such) — kept, not cloned away.
     */
    public function recordPayment(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        if (in_array($invoice->status, [InvoiceStatus::PAID, InvoiceStatus::VOID], true)) {
            return $this->error('Cannot record payment on a '.$invoice->status->label().' invoice.', 422);
        }

        $user = $this->user($request);
        $remainingKobo = $this->remainingKobo($invoice);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', function (string $attribute, mixed $value, Closure $fail) use ($remainingKobo) {
                if ($this->kobo((float) $value) > $remainingKobo) {
                    $fail('Amount cannot exceed the remaining balance of ₦'.number_format($this->naira($remainingKobo), 2).'.');
                }
            }],
            'payment_method' => ['required', Rule::in(['gateway', 'bank_transfer', 'check'])],
            'password' => ['required', function (string $attribute, mixed $value, Closure $fail) use ($user) {
                if (! Hash::check($value, $user->password)) {
                    $fail('The password is incorrect.');
                }
            }],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $amountKobo = $this->kobo((float) $data['amount']);

        $transaction = DB::transaction(function () use ($invoice, $user, $data, $amountKobo) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            // Re-check against the locked row: a concurrent payment may have
            // moved the remaining balance since validation ran.
            $lockedRemaining = $this->remainingKobo($locked);

            if ($amountKobo > $lockedRemaining) {
                throw ValidationException::withMessages([
                    'amount' => 'Amount cannot exceed the remaining balance of ₦'.number_format($this->naira($lockedRemaining), 2).'.',
                ]);
            }

            $transaction = Transaction::create([
                'reference' => 'PMT-'.strtoupper(Str::random(12)),
                'invoice_id' => $locked->id,
                'business_id' => $locked->business_id,
                'amount' => $this->naira($amountKobo),
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

            $locked->amount_paid = $this->naira($this->paidKobo($locked) + $amountKobo);

            if ($this->paidKobo($locked) >= $this->kobo($locked->total)) {
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
                'amount' => $this->naira($amountKobo),
                'method' => $data['payment_method'],
                'recorded_by' => $user->id,
                'store_balance_before' => $transaction->store_balance_before,
                'store_balance_after' => $transaction->store_balance_after,
            ]);

            return $transaction;
        });

        $ledger = app(LedgerPostingService::class);
        $ledger->safe(function () use ($ledger, $invoice, $transaction, $user) {
            $ledger->postInvoice($invoice->fresh(), $user->id);
            $ledger->postPaymentReceived($transaction, $user->id);
        });

        $method = ucfirst(str_replace('_', ' ', $data['payment_method']));

        return $this->ok(
            ['invoice' => $this->detail($invoice->fresh())],
            'Payment of ₦'.number_format($this->naira($amountKobo), 2).' via '.$method.' recorded successfully.',
        );
    }

    /**
     * POST /management/invoices/{invoice}/void — legacy also stamped
     * `voided_at`; refusing a paid invoice is the one guard legacy lacked.
     */
    public function void(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        if ($invoice->status === InvoiceStatus::PAID) {
            return $this->error('A paid invoice cannot be voided.', 422);
        }

        if ($invoice->status === InvoiceStatus::VOID) {
            return $this->error('This invoice is already void.', 422);
        }

        DB::transaction(function () use ($invoice) {
            $invoice->update([
                'status' => InvoiceStatus::VOID,
                'voided_at' => now(),
            ]);
        });

        Log::info('api.management.invoice_voided', [
            'user_id' => $this->user($request)->id,
            'invoice_id' => $invoice->id,
        ]);

        return $this->ok(['invoice' => $this->detail($invoice->fresh())], 'Invoice voided.');
    }

    /**
     * Invoices are business-level, so a restricted (store-assigned) staff
     * member only sees the invoices raised against their stores.
     */
    private function baseQuery(Request $request)
    {
        $user = $this->user($request);

        $query = Invoice::query()->where('business_id', $user->business_id);

        if ($user->isRestrictedStaff()) {
            $query->whereIn('store_id', $this->accessibleStoreIds($request));
        }

        return $query;
    }

    private function applySearch($query, string $term): void
    {
        $query->where(function ($search) use ($term) {
            $search->where('invoice_number', 'like', "%{$term}%")
                ->orWhere('recipient_name', 'like', "%{$term}%")
                ->orWhere('recipient_email', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($customer) => $customer->whereRaw(
                    "CONCAT(first_name, ' ', last_name) like ?",
                    ["%{$term}%"],
                ));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, User $user): array
    {
        $data = $request->validate([
            'store_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:50'],
            'recipient_address' => ['nullable', 'string', 'max:500'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'save_customer' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        // Legacy validated these with a bare `exists:` rule, which let a user
        // attach another business's store or customer. Scope both to what the
        // authenticated user can actually reach.
        if (! empty($data['store_id']) && ! $user->accessibleStores()->whereKey($data['store_id'])->exists()) {
            throw ValidationException::withMessages([
                'store_id' => 'The selected store is not available to your business.',
            ]);
        }

        if (! empty($data['customer_id']) && ! Customer::where('business_id', $user->business_id)->whereKey($data['customer_id'])->exists()) {
            throw ValidationException::withMessages([
                'customer_id' => 'The selected customer does not belong to your business.',
            ]);
        }

        return $data;
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
            $subtotalKobo += ((int) $item['quantity']) * $this->kobo((float) $item['unit_price']);
        }

        $taxRate = round((float) ($data['tax_rate'] ?? 0), 2);
        $taxKobo = (int) round($subtotalKobo * $taxRate / 100);

        $discountValue = round((float) ($data['discount_value'] ?? 0), 2);
        $discountKobo = $this->discountType($data) === 'percentage'
            ? (int) round($subtotalKobo * $discountValue / 100)
            : $this->kobo($discountValue);

        $discountKobo = min($discountKobo, $subtotalKobo + $taxKobo);
        $totalKobo = max(0, $subtotalKobo + $taxKobo - $discountKobo);

        return [
            'subtotal' => $this->naira($subtotalKobo),
            'tax_rate' => $taxRate,
            'tax_amount' => $this->naira($taxKobo),
            'discount_value' => $discountValue,
            'discount_amount' => $this->naira($discountKobo),
            'total' => $this->naira($totalKobo),
        ];
    }

    /**
     * @param  array<int, array{description: string, quantity: int|string, unit_price: float|int|string}>  $items
     */
    private function replaceItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();

        foreach ($items as $index => $item) {
            $unitPriceKobo = $this->kobo((float) $item['unit_price']);
            $lineKobo = ((int) $item['quantity']) * $unitPriceKobo;

            $invoice->items()->create([
                'description' => $item['description'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => $this->naira($unitPriceKobo),
                'amount' => $this->naira($lineKobo),
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

        $customer = Customer::where('business_id', $invoice->business_id)
            ->where('email', $email)
            ->first();

        if (! $customer) {
            $nameParts = explode(' ', trim($data['recipient_name'] ?? ''), 2);

            $customer = Customer::create([
                'business_id' => $invoice->business_id,
                'first_name' => $nameParts[0] ?: 'Customer',
                'last_name' => $nameParts[1] ?? '',
                'email' => $email,
                'phone' => $data['recipient_phone'] ?? null,
                'status' => Customer::STATUS_ACTIVE,
            ]);
        }

        $invoice->update(['customer_id' => $customer->id]);
    }

    /**
     * Issue/reuse the 32-char public payment token, queue `InvoiceMail` (with
     * the payment URL) and stamp `sent_at` / draft -> sent.
     *
     * @return string|null the address the mail was queued to, null when none is reachable
     */
    private function sendInvoiceEmail(Invoice $invoice): ?string
    {
        $to = $invoice->recipient_email ?: $invoice->customer?->email;

        if (! $to || str_contains($to, '@walkin.local')) {
            $to = config('mail.from.address');
        }

        if (! $to) {
            return null;
        }

        $invoice->loadMissing(['items', 'store', 'customer']);

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

    private function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        $user = $this->user($request);

        $allowed = (int) $invoice->business_id === (int) $user->business_id;

        if ($allowed && $user->isRestrictedStaff()) {
            $allowed = in_array((int) $invoice->store_id, $this->accessibleStoreIds($request)->map(fn ($id) => (int) $id)->all(), true);
        }

        if (! $allowed) {
            abort(403, 'You do not have access to this invoice.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Invoice $invoice): array
    {
        $invoice->loadMissing(['customer', 'store']);

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'recipient_name' => $invoice->recipient_name ?? $invoice->customer?->full_name,
            'recipient_email' => $invoice->recipient_email,
            'recipient_phone' => $invoice->recipient_phone,
            'customer' => $invoice->customer ? [
                'id' => $invoice->customer->id,
                'account_id' => $invoice->customer->account_id,
                'name' => $invoice->customer->full_name,
                'email' => $invoice->customer->email,
                'phone' => $invoice->customer->phone,
            ] : null,
            'store' => $invoice->store ? [
                'id' => $invoice->store->id,
                'name' => $invoice->store->name,
                'store_id' => $invoice->store->store_id,
            ] : null,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'total' => (float) $invoice->total,
            'amount_paid' => (float) $invoice->amount_paid,
            'remaining' => $this->naira($this->remainingKobo($invoice)),
            'items_count' => (int) ($invoice->items_count ?? ($invoice->relationLoaded('items') ? $invoice->items->count() : $invoice->items()->count())),
            'created_at' => $invoice->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Invoice $invoice): array
    {
        $invoice->loadMissing(['items', 'customer', 'store', 'transactions']);

        $discountKobo = $this->discountKobo($invoice);

        return [
            ...$this->summary($invoice),
            'currency' => $this->currencyOf($invoice),
            'recipient_address' => $invoice->recipient_address,
            'subtotal' => (float) $invoice->subtotal,
            'tax_rate' => (float) $invoice->tax_rate,
            'tax_amount' => (float) $invoice->tax_amount,
            'discount_type' => $invoice->discount_type,
            'discount_value' => (float) $invoice->discount_value,
            // The legacy views rendered `discount_value` as an amount even when
            // it was a percentage; the computed amount is what the UI shows now.
            'discount_amount' => $this->naira($discountKobo),
            'notes' => $invoice->notes,
            'terms' => $invoice->terms,
            'sent_at' => $invoice->sent_at?->toISOString(),
            'paid_at' => $invoice->paid_at?->toISOString(),
            'voided_at' => $invoice->voided_at?->toISOString(),
            'payment_url' => $invoice->payment_token
                ? route('invoice.pay.show', ['token' => $invoice->payment_token])
                : null,
            'items' => $invoice->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'amount' => (float) $item->amount,
            ])->all(),
            'transactions' => $invoice->transactions
                ->where('status', '!=', TransactionStatus::PENDING)
                ->sortByDesc('id')
                ->values()
                ->map(fn (Transaction $transaction) => [
                    'id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'amount' => (float) $transaction->amount,
                    'status' => $transaction->status->value,
                    'status_label' => $transaction->status->label(),
                    'method' => $transaction->metadata['source'] ?? null,
                    'note' => $transaction->metadata['note'] ?? null,
                    'paid_at' => $transaction->paid_at?->toISOString(),
                    'created_at' => $transaction->created_at?->toISOString(),
                ])->all(),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_map(
            fn (InvoiceStatus $status) => ['value' => $status->value, 'label' => $status->label()],
            InvoiceStatus::cases(),
        );
    }

    private function currency(Request $request): string
    {
        return $this->user($request)->business?->currency ?: 'NGN';
    }

    private function currencyOf(Invoice $invoice): string
    {
        return $invoice->business?->currency ?: 'NGN';
    }

    private function currencySymbol(string $currency): string
    {
        return [
            'NGN' => '₦',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'GHS' => 'GH₵',
            'KES' => 'KSh',
            'ZAR' => 'R',
        ][strtoupper($currency)] ?? strtoupper($currency).' ';
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

    private function remainingKobo(Invoice $invoice): int
    {
        return max(0, $this->kobo($invoice->total) - $this->paidKobo($invoice));
    }

    private function paidKobo(Invoice $invoice): int
    {
        return $this->kobo($invoice->amount_paid);
    }

    /**
     * The legacy views treated `discount_value` as an amount even for a
     * percentage; this resolves the real deduction in kobo.
     */
    private function discountKobo(Invoice $invoice): int
    {
        $subtotalKobo = $this->kobo($invoice->subtotal);
        $taxKobo = $this->kobo($invoice->tax_amount);
        $valueKobo = $this->kobo($invoice->discount_value);

        $discountKobo = $invoice->discount_type === 'percentage'
            ? (int) round($subtotalKobo * (float) $invoice->discount_value / 100)
            : $valueKobo;

        return min($discountKobo, $subtotalKobo + $taxKobo);
    }

    /**
     * Null is treated as zero: money columns are nullable on legacy rows, and
     * a half-filled invoice should read as ₦0 rather than throw.
     */
    private function kobo(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function naira(int $kobo): float
    {
        return $kobo / 100;
    }
}
