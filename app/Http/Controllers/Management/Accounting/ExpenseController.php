<?php

namespace App\Http\Controllers\Management\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Supplier;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        $businessId = $request->user()->business_id;

        $expenses = Expense::query()
            ->where('business_id', $businessId)
            ->with(['category', 'supplier', 'ledgerAccount'])
            ->when($request->filled('from'), fn ($q) => $q->whereDate('expense_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('expense_date', '<=', $request->date('to')))
            ->when($request->filled('category'), fn ($q) => $q->where('expense_category_id', $request->integer('category')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $categories = ExpenseCategory::where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $totals = [
            'month' => Expense::where('business_id', $businessId)
                ->where('status', '!=', Expense::STATUS_VOID)
                ->whereMonth('expense_date', now()->month)
                ->whereYear('expense_date', now()->year)
                ->sum('total_kobo'),
            'year' => Expense::where('business_id', $businessId)
                ->where('status', '!=', Expense::STATUS_VOID)
                ->whereYear('expense_date', now()->year)
                ->sum('total_kobo'),
        ];

        return view('management.accounting.expenses.index', compact('expenses', 'categories', 'totals'));
    }

    public function create(Request $request): View
    {
        return view('management.accounting.expenses.form', $this->formData($request));
    }

    public function store(Request $request, LedgerPostingService $posting): RedirectResponse
    {
        $businessId = $request->user()->business_id;
        $validated = $this->validateExpense($request, $businessId);

        $amountKobo = (int) round(((float) $validated['amount']) * 100);
        $taxKobo = (int) round(((float) ($validated['tax'] ?? 0)) * 100);

        $receiptPath = $request->hasFile('receipt')
            ? $request->file('receipt')->store('expense-receipts', 'public')
            : null;

        $expense = DB::transaction(function () use ($request, $validated, $businessId, $amountKobo, $taxKobo, $receiptPath) {
            return Expense::create([
                'business_id' => $businessId,
                'expense_category_id' => $validated['expense_category_id'] ?? null,
                'ledger_account_id' => $validated['ledger_account_id'],
                'supplier_id' => $validated['supplier_id'] ?? null,
                'expense_date' => $validated['expense_date'],
                'amount_kobo' => $amountKobo,
                'tax_kobo' => $taxKobo,
                'total_kobo' => $amountKobo + $taxKobo,
                'currency' => 'NGN',
                'payment_account_id' => $validated['payment_account_id'] ?? null,
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'description' => $validated['description'] ?? null,
                'receipt_path' => $receiptPath,
                'status' => Expense::STATUS_PAID,
                'created_by' => $request->user()->id,
            ]);
        });

        try {
            $entry = $posting->postExpense($expense, $request->user()->id);
            if ($entry) {
                $expense->update(['journal_entry_id' => $entry->id]);
            }
        } catch (\Throwable $e) {
            return redirect()->route('management.accounting.expenses.show', $expense)
                ->with('warning', 'Expense saved but ledger posting failed: '.$e->getMessage());
        }

        return redirect()->route('management.accounting.expenses.index')
            ->with('success', 'Expense recorded.');
    }

    public function show(Request $request, Expense $expense): View
    {
        $this->authorizeExpense($request, $expense);

        $expense->load(['category', 'supplier', 'ledgerAccount', 'paymentAccount', 'journalEntry.lines.account']);

        return view('management.accounting.expenses.show', compact('expense'));
    }

    public function void(Request $request, Expense $expense, LedgerPostingService $posting): RedirectResponse
    {
        $this->authorizeExpense($request, $expense);

        if ($expense->status === Expense::STATUS_VOID) {
            return back()->with('error', 'This expense is already void.');
        }

        if ($expense->journal_entry_id) {
            $entry = JournalEntry::find($expense->journal_entry_id);
            if ($entry && $entry->status === JournalEntry::STATUS_POSTED) {
                try {
                    $posting->reverseEntry($entry, 'Void expense #'.$expense->id, $request->user()->id);
                } catch (\Throwable $e) {
                    return back()->with('error', $e->getMessage());
                }
            }
        }

        $expense->update(['status' => Expense::STATUS_VOID]);

        return back()->with('success', 'Expense voided.');
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeExpense($request, $expense);

        if ($expense->journal_entry_id) {
            return back()->with('error', 'Posted expenses cannot be deleted. Void it instead.');
        }

        $expense->delete();

        return redirect()->route('management.accounting.expenses.index')
            ->with('success', 'Expense deleted.');
    }

    private function validateExpense(Request $request, int $businessId): array
    {
        return $request->validate([
            'expense_date' => ['required', 'date'],
            'expense_category_id' => ['nullable', Rule::exists('expense_categories', 'id')->where('business_id', $businessId)],
            'ledger_account_id' => ['required', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('business_id', $businessId)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'cheque', 'other'])],
            'payment_account_id' => ['nullable', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'receipt' => ['nullable', 'file', 'mimes:jpeg,png,jpg,pdf', 'max:5120'],
        ]);
    }

    private function formData(Request $request): array
    {
        $businessId = $request->user()->business_id;

        return [
            'expense' => new Expense(['expense_date' => now()->toDateString()]),
            'categories' => ExpenseCategory::where('business_id', $businessId)->where('is_active', true)->orderBy('name')->get(),
            'expenseAccounts' => LedgerAccount::where('business_id', $businessId)->where('type', LedgerAccount::TYPE_EXPENSE)->active()->orderBy('code')->get(),
            'paymentAccounts' => LedgerAccount::where('business_id', $businessId)->active()->whereIn('subtype', ['cash', 'bank', 'gateway_clearing'])->orderBy('code')->get(),
            'suppliers' => Supplier::where('business_id', $businessId)->where('is_active', true)->orderBy('name')->get(),
        ];
    }

    private function authorizeExpense(Request $request, Expense $expense): void
    {
        if ($expense->business_id !== $request->user()->business_id) {
            abort(403);
        }
    }
}
