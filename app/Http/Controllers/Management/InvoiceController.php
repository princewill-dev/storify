<?php

namespace App\Http\Controllers\Management;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $status = $request->query('status');
        $q = trim($request->query('q', ''));

        $query = Invoice::where('business_id', $user->business_id)
            ->with(['customer', 'store', 'items'])
            ->latest();

        if ($status && in_array($status, array_column(InvoiceStatus::cases(), 'value'))) {
            $query->where('status', $status);
        }

        if ($q) {
            $query->where(function ($x) use ($q) {
                $x->where('invoice_number', 'like', "%{$q}%")
                    ->orWhere('recipient_name', 'like', "%{$q}%")
                    ->orWhere('recipient_email', 'like', "%{$q}%")
                    ->orWhereHas('customer', fn($c) => $c->whereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$q}%"]));
            });
        }

        $invoices = $query->paginate(20)->withQueryString();

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Invoices'],
        ];

        return view('management.invoices.index', compact('user', 'invoices', 'status', 'q', 'breadcrumbs'));
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $customers = Customer::where('business_id', $user->business_id)->orderBy('first_name')->get();
        $stores = $user->accessibleStores()->where('status', 'active')->orderBy('name')->get();
        $invoice = new Invoice();

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Invoices', 'url' => route('management.invoices.index')],
            ['label' => 'Create'],
        ];

        return view('management.invoices.create', compact('user', 'customers', 'stores', 'invoice', 'breadcrumbs'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $this->validateInvoice($request);
        $invoice = null;

        DB::transaction(function () use ($user, $validated, $request, &$invoice) {
            $invoice = Invoice::create([
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'store_id' => $validated['store_id'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                'recipient_name' => $validated['recipient_name'] ?? null,
                'recipient_email' => $validated['recipient_email'] ?? null,
                'recipient_phone' => $validated['recipient_phone'] ?? null,
                'recipient_address' => $validated['recipient_address'] ?? null,
                'status' => $request->has('finalize') ? InvoiceStatus::SENT : InvoiceStatus::DRAFT,
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'subtotal' => $validated['subtotal'] ?? 0,
                'tax_rate' => $validated['tax_rate'] ?? 0,
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'discount_type' => $validated['discount_type'] ?? null,
                'discount_value' => $validated['discount_value'] ?? 0,
                'total' => $validated['total'] ?? 0,
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
            ]);

            foreach ($validated['items'] as $i => $item) {
                if (empty($item['description'])) continue;
                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'amount' => (int) ($item['quantity'] ?? 1) * (float) ($item['unit_price'] ?? 0),
                    'sort_order' => $i,
                ]);
            }

            if (!empty($validated['recipient_email']) && empty($validated['customer_id']) && $request->has('save_customer')) {
                $nameParts = explode(' ', trim($validated['recipient_name'] ?? ''), 2);
                Customer::create([
                    'business_id' => $user->business_id,
                    'first_name' => $nameParts[0] ?? 'Customer',
                    'last_name' => $nameParts[1] ?? '',
                    'email' => $validated['recipient_email'],
                    'phone' => $validated['recipient_phone'] ?? null,
                    'status' => 'active',
                ]);
            }

            if ($request->has('finalize')) {
                $this->sendInvoice($invoice);
            }
        });

        return redirect()->route('management.invoices.show', $invoice->invoice_number ?? $invoice)
            ->with('success', 'Invoice created successfully.');
    }

    public function show(Request $request, Invoice $invoice): View
    {
        $user = $request->user();
        if ($invoice->business_id !== $user->business_id) abort(403);

        $invoice->load(['items', 'customer', 'store', 'transactions' => fn($q) => $q->latest()]);

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Invoices', 'url' => route('management.invoices.index')],
            ['label' => $invoice->invoice_number],
        ];

        return view('management.invoices.show', compact('user', 'invoice', 'breadcrumbs'));
    }

    public function edit(Request $request, Invoice $invoice): View
    {
        $user = $request->user();
        if ($invoice->business_id !== $user->business_id) abort(403);
        if (!$invoice->isDraft()) abort(403, 'Only draft invoices can be edited.');

        $invoice->load('items');
        $customers = Customer::where('business_id', $user->business_id)->orderBy('first_name')->get();
        $stores = $user->accessibleStores()->where('status', 'active')->orderBy('name')->get();

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Invoices', 'url' => route('management.invoices.index')],
            ['label' => $invoice->invoice_number, 'url' => route('management.invoices.show', $invoice)],
            ['label' => 'Edit'],
        ];

        return view('management.invoices.create', compact('user', 'invoice', 'customers', 'stores', 'breadcrumbs'));
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->user();
        if ($invoice->business_id !== $user->business_id) abort(403);
        if (!$invoice->isDraft()) abort(403, 'Only draft invoices can be edited.');

        $validated = $this->validateInvoice($request);

        DB::transaction(function () use ($user, $invoice, $validated, $request) {
            $invoice->update([
                'store_id' => $validated['store_id'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                'recipient_name' => $validated['recipient_name'] ?? null,
                'recipient_email' => $validated['recipient_email'] ?? null,
                'recipient_phone' => $validated['recipient_phone'] ?? null,
                'recipient_address' => $validated['recipient_address'] ?? null,
                'status' => $request->has('finalize') ? InvoiceStatus::SENT : InvoiceStatus::DRAFT,
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'subtotal' => $validated['subtotal'] ?? 0,
                'tax_rate' => $validated['tax_rate'] ?? 0,
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'discount_type' => $validated['discount_type'] ?? null,
                'discount_value' => $validated['discount_value'] ?? 0,
                'total' => $validated['total'] ?? 0,
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
            ]);

            $invoice->items()->delete();
            foreach ($validated['items'] as $i => $item) {
                if (empty($item['description'])) continue;
                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'amount' => (int) ($item['quantity'] ?? 1) * (float) ($item['unit_price'] ?? 0),
                    'sort_order' => $i,
                ]);
            }

            if ($request->has('finalize')) {
                $this->sendInvoice($invoice);
            }
        });

        return redirect()->route('management.invoices.show', $invoice)
            ->with('success', 'Invoice updated successfully.');
    }

    public function destroy(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->user();
        if ($invoice->business_id !== $user->business_id) abort(403);
        if (!$invoice->isDraft()) abort(403, 'Only draft invoices can be deleted.');

        $invoice->delete();

        return redirect()->route('management.invoices.index')
            ->with('success', 'Invoice deleted.');
    }

    public function send(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->user();
        if ($invoice->business_id !== $user->business_id) abort(403);

        $this->sendInvoice($invoice);

        return back()->with('success', 'Invoice sent to ' . $invoice->recipient_email);
    }

    public function markPaid(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->user();
        if ($invoice->business_id !== $user->business_id) abort(403);

        $invoice->update([
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
        ]);

        return back()->with('success', 'Invoice marked as paid.');
    }

    public function voidInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->user();
        if ($invoice->business_id !== $user->business_id) abort(403);

        $invoice->update([
            'status' => InvoiceStatus::VOID,
            'voided_at' => now(),
        ]);

        return back()->with('success', 'Invoice voided.');
    }

    public function pdf(Request $request, Invoice $invoice): \Illuminate\Http\Response
    {
        $user = $request->user();
        if ($invoice->business_id !== $user->business_id) abort(403);

        $invoice->load(['items', 'store', 'customer']);

        $pdf = Pdf::loadView('management.invoices.pdf', compact('invoice'));
        return $pdf->download($invoice->invoice_number . '.pdf');
    }

    protected function validateInvoice(Request $request): array
    {
        return $request->validate([
            'store_id' => 'nullable|exists:stores,id',
            'customer_id' => 'nullable|exists:customers,id',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_email' => 'nullable|email|max:255',
            'recipient_phone' => 'nullable|string|max:50',
            'recipient_address' => 'nullable|string|max:500',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'subtotal' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0',
            'total' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);
    }

    protected function sendInvoice(Invoice $invoice): void
    {
        $to = $invoice->recipient_email ?: $invoice->customer?->email;

        if (!$to || str_contains($to, '@walkin.local')) {
            $to = config('mail.from.address');
        }

        if (!$to) return;

        try {
            $invoice->load(['items', 'store']);

            if (!$invoice->payment_token) {
                $invoice->payment_token = \Illuminate\Support\Str::random(32);
                $invoice->save();
            }

            $paymentUrl = route('invoice.pay.show', ['token' => $invoice->payment_token]);

            Mail::to($to)->queue(new InvoiceMail($invoice, $paymentUrl));

            if ($invoice->isDraft()) {
                $invoice->update(['status' => InvoiceStatus::SENT, 'sent_at' => now()]);
            }
        } catch (\Throwable $e) {
            \Log::error('invoice_send_failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
        }
    }
}
