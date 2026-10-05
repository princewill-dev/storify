<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Bill;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Supplier;
use App\Services\Accounting\LedgerReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(private readonly LedgerReportService $reports) {}

    public function dashboard(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        $balanceSheet = $this->reports->balanceSheet($businessId, now()->toDateString());
        $profitAndLoss = $this->reports->profitAndLoss($businessId, now()->startOfYear()->toDateString(), now()->toDateString());

        return $this->ok([
            'assets' => $balanceSheet['total_assets'],
            'liabilities' => $balanceSheet['total_liabilities'],
            'equity' => $balanceSheet['total_equity'],
            'income_ytd' => $profitAndLoss['total_income'],
            'expenses_ytd' => $profitAndLoss['total_expenses'],
            'net_profit_ytd' => $profitAndLoss['net_profit'],
        ]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $accounts = LedgerAccount::query()
            ->where('business_id', $this->user($request)->business_id)
            ->orderBy('code')
            ->get()
            ->map(fn (LedgerAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'subtype' => $account->subtype,
                'is_system' => (bool) $account->is_system,
                'is_active' => (bool) $account->is_active,
            ])->values()->all();

        return $this->ok(['accounts' => $accounts]);
    }

    public function journal(Request $request): JsonResponse
    {
        $entries = JournalEntry::query()
            ->where('business_id', $this->user($request)->business_id)
            ->withCount('lines')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('entry_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('entry_date', '<=', $request->date('to')))
            ->when($request->filled('q'), fn ($q) => $q->where('entry_number', 'like', '%'.$request->string('q').'%'))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $entries->getCollection()->map(fn (JournalEntry $entry) => [
                'id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date?->toDateString(),
                'memo' => $entry->memo,
                'reference' => $entry->reference,
                'status' => $entry->status,
                'lines_count' => $entry->lines_count,
                'total_debits' => $entry->totalDebits(),
            ])->values()->all(),
            null,
            200,
            $this->paginationMeta($entries)
        );
    }

    public function journalShow(Request $request, JournalEntry $entry): JsonResponse
    {
        if ((int) $entry->business_id !== (int) $this->user($request)->business_id) {
            abort(403);
        }

        $entry->load(['lines.account']);

        return $this->ok([
            'entry' => [
                'id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date?->toDateString(),
                'memo' => $entry->memo,
                'status' => $entry->status,
                'lines' => $entry->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'account' => $line->account?->name,
                    'account_code' => $line->account?->code,
                    'description' => $line->description,
                    'debit' => $line->debit_kobo,
                    'credit' => $line->credit_kobo,
                ])->values()->all(),
            ],
        ]);
    }

    public function expenses(Request $request): JsonResponse
    {
        $expenses = Expense::query()
            ->where('business_id', $this->user($request)->business_id)
            ->with(['category:id,name', 'supplier:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('expense_date')
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $expenses->getCollection()->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'expense_date' => $expense->expense_date?->toDateString(),
                'category' => $expense->category?->name,
                'supplier' => $expense->supplier?->name,
                'amount_kobo' => $expense->amount_kobo,
                'total_kobo' => $expense->total_kobo,
                'status' => $expense->status,
                'description' => $expense->description,
            ])->values()->all(),
            null,
            200,
            $this->paginationMeta($expenses)
        );
    }

    public function suppliers(Request $request): JsonResponse
    {
        $suppliers = Supplier::query()
            ->where('business_id', $this->user($request)->business_id)
            ->withCount('bills')
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
                'bills_count' => $supplier->bills_count,
            ])->values()->all();

        return $this->ok(['suppliers' => $suppliers]);
    }

    public function bills(Request $request): JsonResponse
    {
        $bills = Bill::query()
            ->where('business_id', $this->user($request)->business_id)
            ->with('supplier:id,name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('issue_date')
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $bills->getCollection()->map(fn (Bill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'supplier' => $bill->supplier?->name,
                'issue_date' => $bill->issue_date?->toDateString(),
                'due_date' => $bill->due_date?->toDateString(),
                'total_kobo' => $bill->total_kobo,
                'paid_kobo' => $bill->amount_paid_kobo,
                'balance_kobo' => $bill->remainingBalanceKobo(),
                'status' => $bill->status,
            ])->values()->all(),
            null,
            200,
            $this->paginationMeta($bills)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reportParams(Request $request): array
    {
        return [
            $this->user($request)->business_id,
            $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString(),
            $request->date('to')?->toDateString() ?? now()->toDateString(),
        ];
    }

    public function profitAndLoss(Request $request): JsonResponse
    {
        [$businessId, $from, $to] = $this->reportParams($request);

        return $this->ok($this->reports->profitAndLoss($businessId, $from, $to));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();

        return $this->ok($this->reports->balanceSheet($businessId, $asOf));
    }

    public function trialBalance(Request $request): JsonResponse
    {
        [$businessId, $from, $to] = $this->reportParams($request);
        $report = $this->reports->trialBalance($businessId, $from, $to);

        return $this->ok([
            'lines' => collect($report['lines'])->map(fn ($line) => [
                'code' => $line['account']->code,
                'name' => $line['account']->name,
                'debit' => $line['debit'],
                'credit' => $line['credit'],
            ])->values()->all(),
            'total_debit' => $report['total_debit'],
            'total_credit' => $report['total_credit'],
        ]);
    }

    public function generalLedger(Request $request, LedgerAccount $account): JsonResponse
    {
        if ((int) $account->business_id !== (int) $this->user($request)->business_id) {
            abort(403);
        }

        [$businessId, $from, $to] = $this->reportParams($request);

        $report = $this->reports->generalLedger($businessId, $account->id, $from, $to);

        return $this->ok([
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'opening' => $report['opening'],
            'rows' => collect($report['rows'])->map(fn ($row) => [
                'date' => $row['date'] instanceof \DateTimeInterface ? $row['date']->format('Y-m-d') : $row['date'],
                'entry_number' => $row['entry_number'],
                'memo' => $row['memo'],
                'debit' => $row['debit'],
                'credit' => $row['credit'],
                'balance' => $row['balance'],
            ])->values()->all(),
            'closing' => $report['closing'],
        ]);
    }

    public function arAging(Request $request): JsonResponse
    {
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();

        return $this->ok($this->reports->arAging($this->user($request)->business_id, $asOf));
    }

    public function apAging(Request $request): JsonResponse
    {
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();

        return $this->ok($this->reports->apAging($this->user($request)->business_id, $asOf));
    }

    public function vatSummary(Request $request): JsonResponse
    {
        [$businessId, $from, $to] = $this->reportParams($request);

        return $this->ok($this->reports->vatSummary($businessId, $from, $to));
    }

    public function expenseSummary(Request $request): JsonResponse
    {
        [$businessId, $from, $to] = $this->reportParams($request);

        return $this->ok($this->reports->expenseSummary($businessId, $from, $to));
    }

    public function integrity(Request $request): JsonResponse
    {
        return $this->ok($this->reports->integrity($this->user($request)->business_id));
    }
}
