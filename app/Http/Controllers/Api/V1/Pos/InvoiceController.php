<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\InvoiceStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
    public function index(Request $request, Store $store): JsonResponse
    {
        $query = Invoice::where('store_id', $store->id)
            ->with(['customer', 'items'])
            ->latest();

        if ($request->filled('status') && in_array($request->status, array_column(InvoiceStatus::cases(), 'value'))) {
            $query->where('status', $request->status);
        }

        if ($request->filled('q')) {
            $q = trim($request->q);
            $query->where(function ($x) use ($q) {
                $x->where('invoice_number', 'like', "%{$q}%")
                    ->orWhere('recipient_name', 'like', "%{$q}%");
            });
        }

        $invoices = $query->paginate(20)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => [
                'invoices' => $invoices->map(fn($inv) => [
                    'id' => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'recipient_name' => $inv->recipient_name ?? $inv->customer?->full_name,
                    'total' => (float) $inv->total,
                    'amount_paid' => (float) $inv->amount_paid,
                    'status' => $inv->status->value,
                    'status_label' => $inv->status->label(),
                    'due_date' => $inv->due_date->toISOString(),
                    'created_at' => $inv->created_at->toISOString(),
                ]),
                'pagination' => [
                    'current_page' => $invoices->currentPage(),
                    'last_page' => $invoices->lastPage(),
                    'total' => $invoices->total(),
                ],
            ],
        ]);
    }

    public function show(Store $store, $invoiceId): JsonResponse
    {
        $invoice = Invoice::where('store_id', $store->id)
            ->where('id', $invoiceId)
            ->with(['items', 'customer', 'transactions.paymentMethod'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'invoice' => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'recipient_name' => $invoice->recipient_name ?? $invoice->customer?->full_name,
                    'recipient_email' => $invoice->recipient_email,
                    'recipient_phone' => $invoice->recipient_phone,
                    'status' => $invoice->status->value,
                    'status_label' => $invoice->status->label(),
                    'issue_date' => $invoice->issue_date->toISOString(),
                    'due_date' => $invoice->due_date->toISOString(),
                    'subtotal' => (float) $invoice->subtotal,
                    'tax_rate' => (float) $invoice->tax_rate,
                    'tax_amount' => (float) $invoice->tax_amount,
                    'discount_value' => (float) $invoice->discount_value,
                    'total' => (float) $invoice->total,
                    'amount_paid' => (float) $invoice->amount_paid,
                    'remaining' => $invoice->remainingBalance(),
                    'notes' => $invoice->notes,
                    'created_at' => $invoice->created_at->toISOString(),
                    'items' => $invoice->items->map(fn($i) => [
                        'description' => $i->description,
                        'quantity' => $i->quantity,
                        'unit_price' => (float) $i->unit_price,
                        'amount' => (float) $i->amount,
                    ]),
                    'transactions' => $invoice->transactions->where('status', '!=', 'pending')->map(fn($tx) => [
                        'reference' => $tx->reference,
                        'amount' => (float) $tx->amount,
                        'status' => $tx->status->value,
                        'status_label' => $tx->status->label(),
                        'created_at' => $tx->created_at->toISOString(),
                    ]),
                ],
            ],
        ]);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'recipient_name' => 'required|string|max:255',
            'recipient_email' => 'nullable|email|max:255',
            'recipient_phone' => 'nullable|string|max:50',
            'customer_id' => 'nullable|exists:customers,id',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'subtotal' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_value' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'send_now' => 'nullable|boolean',
            'service_charge_id' => 'nullable|exists:service_charges,id',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $invoice = DB::transaction(function () use ($validated, $store, $user) {
            $total = $validated['total'];

            if ($validated['service_charge_id'] ?? null) {
                $charge = \App\Models\ServiceCharge::where('store_id', $store->id)->where('is_active', true)->find($validated['service_charge_id']);
                if ($charge) $total += (float) $charge->amount;
            }

            $invoice = Invoice::create([
                'business_id' => $store->business_id,
                'user_id' => $user->id,
                'store_id' => $store->id,
                'customer_id' => $validated['customer_id'] ?? null,
                'recipient_name' => $validated['recipient_name'],
                'recipient_email' => $validated['recipient_email'] ?? null,
                'recipient_phone' => $validated['recipient_phone'] ?? null,
                'status' => InvoiceStatus::DRAFT,
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'subtotal' => $validated['subtotal'],
                'tax_rate' => $validated['tax_rate'] ?? 0,
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'discount_value' => $validated['discount_value'] ?? 0,
                'total' => $total,
                'notes' => $validated['notes'] ?? null,
                'payment_token' => Str::random(32),
            ]);

            foreach ($validated['items'] as $i => $item) {
                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => (float) $item['unit_price'],
                    'amount' => (int) $item['quantity'] * (float) $item['unit_price'],
                    'sort_order' => $i,
                ]);
            }

            if ($validated['send_now'] ?? false) {
                $this->doSendInvoice($invoice);
            }

            return $invoice->load('items');
        });

        return response()->json([
            'success' => true,
            'data' => $this->formatInvoice($invoice),
        ], 201);
    }

    public function sendInvoice(Request $request, Store $store, $invoiceId): JsonResponse
    {
        $invoice = Invoice::where('store_id', $store->id)->findOrFail($invoiceId);

        if ($invoice->status === InvoiceStatus::PAID || $invoice->status === InvoiceStatus::VOID) {
            return response()->json(['success' => false, 'message' => 'Cannot send a paid or voided invoice.'], 400);
        }

        $this->doSendInvoice($invoice);

        return response()->json(['success' => true, 'message' => 'Invoice sent.']);
    }

    public function recordPayment(Request $request, Store $store, $invoiceId): JsonResponse
    {
        $user = $request->user();
        $invoice = Invoice::where('store_id', $store->id)->findOrFail($invoiceId);

        if (in_array($invoice->status, [InvoiceStatus::PAID, InvoiceStatus::VOID])) {
            return response()->json(['success' => false, 'message' => 'Cannot record payment on this invoice.'], 400);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:' . $invoice->remainingBalance()],
            'payment_method' => ['required', 'in:cash,bank_transfer,cheque'],
            'pin' => $user->pos_pin ? ['required', 'string', 'size:6'] : ['nullable'],
        ]);

        if ($user->pos_pin && !Hash::check($validated['pin'], $user->pos_pin)) {
            return response()->json(['success' => false, 'message' => 'Invalid PIN.'], 422);
        }

        DB::transaction(function () use ($invoice, $user, $validated) {
            $methodLabels = ['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque'];

            $transaction = \App\Models\Transaction::create([
                'reference' => 'PMT-' . strtoupper(Str::random(12)),
                'invoice_id' => $invoice->id,
                'business_id' => $invoice->business_id,
                'amount' => $validated['amount'],
                'currency' => 'NGN',
                'status' => TransactionStatus::CONFIRMED,
                'paid_at' => now(),
                'metadata' => [
                    'method' => 'manual',
                    'source' => $validated['payment_method'],
                    'recorded_by' => $user->id,
                ],
            ]);

            $invoice->amount_paid = (float) $invoice->amount_paid + (float) $validated['amount'];

            if ($invoice->isFullyPaid()) {
                $invoice->status = InvoiceStatus::PAID;
                $invoice->paid_at = now();
            } elseif ($invoice->amount_paid > 0) {
                $invoice->status = InvoiceStatus::PARTIAL;
            }

            $invoice->save();

            if ($invoice->store) {
                $invoice->store->creditBalance((int) ($validated['amount'] * 100));
            }
        });

        $invoice->refresh()->load('items', 'transactions');

        return response()->json([
            'success' => true,
            'data' => $this->formatInvoice($invoice),
        ]);
    }

    private function formatInvoice($invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'recipient_name' => $invoice->recipient_name ?? $invoice->customer?->full_name,
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'total' => (float) $invoice->total,
            'amount_paid' => (float) $invoice->amount_paid,
            'remaining' => $invoice->remainingBalance(),
            'created_at' => $invoice->created_at->toISOString(),
            'items' => $invoice->items->map(fn($i) => [
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'amount' => (float) $i->amount,
            ]),
            'transactions' => $invoice->transactions->where('status', '!=', 'pending')->map(fn($tx) => [
                'reference' => $tx->reference,
                'amount' => (float) $tx->amount,
                'status' => $tx->status->value,
                'status_label' => $tx->status->label(),
                'created_at' => $tx->created_at->toISOString(),
            ]),
        ];
    }

    private function doSendInvoice(Invoice $invoice): void
    {
        $to = $invoice->recipient_email ?: $invoice->customer?->email;
        if (!$to || str_contains($to, '@walkin.local')) return;

        try {
            $invoice->load(['items', 'store']);
            if (!$invoice->payment_token) {
                $invoice->payment_token = Str::random(32);
                $invoice->save();
            }
            $paymentUrl = route('invoice.pay.show', ['token' => $invoice->payment_token]);
            \Mail::to($to)->queue(new \App\Mail\InvoiceMail($invoice, $paymentUrl));

            if ($invoice->isDraft()) {
                $invoice->update(['status' => InvoiceStatus::SENT, 'sent_at' => now()]);
            }
        } catch (\Throwable $e) {
            \Log::error('pos_invoice_send_failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
        }
    }
}
