<?php

namespace App\Http\Controllers\Management\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Supplier;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BillController extends Controller
{
    public function index(Request $request): View
    {
        $businessId = $request->user()->business_id;

        $bills = Bill::query()
            ->where('business_id', $businessId)
            ->with('supplier')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->integer('supplier')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->date('to')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->input('q').'%';
                $q->where(fn ($inner) => $inner->where('bill_number', 'like', $term)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $term)));
            })
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $suppliers = Supplier::where('business_id', $businessId)->orderBy('name')->get();

        $stats = [
            'open' => Bill::where('business_id', $businessId)->whereIn('status', ['open', 'partial'])->sum(DB::raw('total_kobo - amount_paid_kobo')),
            'paid' => Bill::where('business_id', $businessId)->where('status', 'paid')->sum('total_kobo'),
            'overdue' => Bill::where('business_id', $businessId)
                ->whereIn('status', ['open', 'partial'])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now())
                ->count(),
        ];

        return view('management.accounting.bills.index', compact('bills', 'suppliers', 'stats'));
    }

    public function create(Request $request): View
    {
        return view('management.accounting.bills.form', $this->formData($request));
    }

    public function store(Request $request, LedgerPostingService $posting): RedirectResponse
    {
        $businessId = $request->user()->business_id;
        $validated = $this->validateBill($request, $businessId);

        $subtotalKobo = 0;
        $items = [];

        foreach ($validated['items'] as $item) {
            $quantity = (float) $item['quantity'];
            $unitCostKobo = (int) round(((float) $item['unit_cost']) * 100);
            $amountKobo = (int) round($quantity * $unitCostKobo);
            $subtotalKobo += $amountKobo;

            $items[] = [
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_cost_kobo' => $unitCostKobo,
                'amount_kobo' => $amountKobo,
                'expense_account_id' => $item['expense_account_id'] ?? null,
                'product_id' => $item['product_id'] ?? null,
            ];
        }

        $taxKobo = (int) round(((float) ($validated['tax'] ?? 0)) * 100);
        $totalKobo = $subtotalKobo + $taxKobo;

        $bill = DB::transaction(function () use ($request, $validated, $businessId, $subtotalKobo, $taxKobo, $totalKobo, $items) {
            $bill = Bill::create([
                'business_id' => $businessId,
                'supplier_id' => $validated['supplier_id'],
                'bill_number' => ($validated['bill_number'] ?? null) ?: 'BILL-'.strtoupper(Str::random(8)),
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?? null,
                'subtotal_kobo' => $subtotalKobo,
                'tax_kobo' => $taxKobo,
                'total_kobo' => $totalKobo,
                'amount_paid_kobo' => 0,
                'status' => Bill::STATUS_OPEN,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($items as $item) {
                $bill->items()->create($item);
            }

            return $bill;
        });

        // Update weighted-average inventory costs for any product-linked lines
        $costing = app(\App\Services\Accounting\InventoryCostingService::class);
        foreach ($items as $item) {
            if (empty($item['product_id']) || (int) $item['unit_cost_kobo'] <= 0) {
                continue;
            }

            $product = \App\Models\Product::find($item['product_id']);
            if ($product) {
                $costing->recordReceipt($product, (int) $item['quantity'], (int) $item['unit_cost_kobo']);
            }
        }

        try {
            $entry = $posting->postBill($bill, $request->user()->id);
            if ($entry) {
                $bill->update(['journal_entry_id' => $entry->id]);
            }
        } catch (\Throwable $e) {
            return redirect()->route('management.accounting.bills.show', $bill)
                ->with('warning', 'Bill saved but ledger posting failed: '.$e->getMessage());
        }

        return redirect()->route('management.accounting.bills.show', $bill)
            ->with('success', 'Bill recorded.');
    }

    public function show(Request $request, Bill $bill): View
    {
        $this->authorizeBill($request, $bill);

        $bill->load(['supplier', 'items.expenseAccount', 'payments.paymentAccount', 'journalEntry.lines.account']);

        $paymentAccounts = LedgerAccount::where('business_id', $bill->business_id)
            ->active()
            ->whereIn('subtype', ['cash', 'bank', 'gateway_clearing'])
            ->orderBy('code')
            ->get();

        return view('management.accounting.bills.show', compact('bill', 'paymentAccounts'));
    }

    public function storePayment(Request $request, Bill $bill, LedgerPostingService $posting): RedirectResponse
    {
        $this->authorizeBill($request, $bill);

        if (in_array($bill->status, [Bill::STATUS_VOID, Bill::STATUS_PAID], true)) {
            return back()->with('error', 'This bill cannot accept payments.');
        }

        $remaining = $bill->remainingBalanceKobo() / 100;

        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$remaining],
            'payment_account_id' => ['nullable', Rule::exists('ledger_accounts', 'id')->where('business_id', $bill->business_id)],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'cheque', 'other'])],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $amountKobo = (int) round(((float) $validated['amount']) * 100);

        $payment = DB::transaction(function () use ($request, $validated, $bill, $amountKobo) {
            $payment = BillPayment::create([
                'business_id' => $bill->business_id,
                'bill_id' => $bill->id,
                'supplier_id' => $bill->supplier_id,
                'payment_date' => $validated['payment_date'],
                'amount_kobo' => $amountKobo,
                'payment_account_id' => $validated['payment_account_id'] ?? null,
                'method' => $validated['method'],
                'reference' => $validated['reference'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $bill->amount_paid_kobo = (int) $bill->amount_paid_kobo + $amountKobo;
            $bill->status = $bill->isFullyPaid() ? Bill::STATUS_PAID : Bill::STATUS_PARTIAL;
            $bill->save();

            return $payment;
        });

        try {
            $entry = $posting->postBillPayment($payment, $request->user()->id);
            if ($entry) {
                $payment->update(['journal_entry_id' => $entry->id]);
            }
        } catch (\Throwable $e) {
            return back()->with('warning', 'Payment saved but ledger posting failed: '.$e->getMessage());
        }

        return back()->with('success', 'Payment recorded.');
    }

    public function void(Request $request, Bill $bill, LedgerPostingService $posting): RedirectResponse
    {
        $this->authorizeBill($request, $bill);

        if ($bill->status === Bill::STATUS_VOID) {
            return back()->with('error', 'This bill is already void.');
        }

        if ($bill->payments()->exists()) {
            return back()->with('error', 'Bills with payments cannot be voided.');
        }

        if ($bill->journal_entry_id) {
            $entry = JournalEntry::find($bill->journal_entry_id);
            if ($entry && $entry->status === JournalEntry::STATUS_POSTED) {
                try {
                    $posting->reverseEntry($entry, 'Void bill '.$bill->bill_number, $request->user()->id);
                } catch (\Throwable $e) {
                    return back()->with('error', $e->getMessage());
                }
            }
        }

        $bill->update(['status' => Bill::STATUS_VOID]);

        return back()->with('success', 'Bill voided.');
    }

    private function validateBill(Request $request, int $businessId): array
    {
        return $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('business_id', $businessId)],
            'bill_number' => ['nullable', 'string', 'max:40'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.expense_account_id' => ['nullable', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)],
            'items.*.product_id' => ['nullable', Rule::exists('products', 'id')->where('business_id', $businessId)],
        ]);
    }

    private function formData(Request $request): array
    {
        $businessId = $request->user()->business_id;

        return [
            'bill' => new Bill(['issue_date' => now()->toDateString()]),
            'suppliers' => Supplier::where('business_id', $businessId)->where('is_active', true)->orderBy('name')->get(),
            'expenseAccounts' => LedgerAccount::where('business_id', $businessId)
                ->active()
                ->whereIn('type', [LedgerAccount::TYPE_EXPENSE])
                ->orderBy('code')
                ->get(),
            'inventoryAccount' => LedgerAccount::where('business_id', $businessId)->where('subtype', 'inventory')->first(),
            'products' => \App\Models\Product::where('business_id', $businessId)->orderBy('name')->get(['id', 'name', 'product_code']),
        ];
    }

    private function authorizeBill(Request $request, Bill $bill): void
    {
        if ($bill->business_id !== $request->user()->business_id) {
            abort(403);
        }
    }
}
