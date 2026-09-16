<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\LedgerMapping;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\LedgerSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountingController extends Controller
{
    public function __construct(
        private readonly LedgerReportService $reports,
        private readonly LedgerSetupService $setup,
    ) {}

    public function index(): View
    {
        $this->setup->ensureForBusiness(null);

        $byType = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->whereNull('journal_entries.business_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->groupBy('ledger_accounts.type')
            ->selectRaw('ledger_accounts.type as type, SUM(journal_lines.debit_kobo) as debit, SUM(journal_lines.credit_kobo) as credit')
            ->get()
            ->keyBy('type');

        $asset = $byType->get('asset');
        $liability = $byType->get('liability');
        $income = $byType->get('income');
        $expense = $byType->get('expense');

        $totals = [
            'assets' => (int) (($asset->debit ?? 0) - ($asset->credit ?? 0)),
            'liabilities' => (int) (($liability->credit ?? 0) - ($liability->debit ?? 0)),
            'income' => (int) (($income->credit ?? 0) - ($income->debit ?? 0)),
            'expenses' => (int) (($expense->debit ?? 0) - ($expense->credit ?? 0)),
        ];
        $totals['net_profit'] = $totals['income'] - $totals['expenses'];

        $recentEntries = JournalEntry::query()
            ->whereNull('business_id')
            ->withCount('lines')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $cashAccounts = LedgerAccount::query()
            ->whereNull('business_id')
            ->whereIn('subtype', ['cash', 'bank', 'gateway_clearing'])
            ->orderBy('code')
            ->get();

        $cashBalances = $cashAccounts->mapWithKeys(function (LedgerAccount $account) {
            $row = JournalLine::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->whereNull('journal_entries.business_id')
                ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
                ->where('journal_lines.ledger_account_id', $account->id)
                ->selectRaw('SUM(journal_lines.debit_kobo) as debit, SUM(journal_lines.credit_kobo) as credit')
                ->first();

            return [$account->id => (int) (($row->debit ?? 0) - ($row->credit ?? 0))];
        });

        return view('admin.accounting.index', compact('totals', 'recentEntries', 'cashAccounts', 'cashBalances'));
    }

    public function accounts(): View
    {
        $this->setup->ensureForBusiness(null);

        $accounts = LedgerAccount::query()
            ->whereNull('business_id')
            ->orderBy('code')
            ->get()
            ->groupBy('type');

        return view('admin.accounting.accounts', compact('accounts'));
    }

    public function journal(Request $request): View
    {
        $entries = JournalEntry::query()
            ->whereNull('business_id')
            ->withCount('lines')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->input('q').'%';
                $q->where(fn ($inner) => $inner->where('entry_number', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhere('memo', 'like', $term));
            })
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.accounting.journal', compact('entries'));
    }

    public function journalShow(JournalEntry $entry): View
    {
        if ($entry->business_id !== null) {
            abort(404);
        }

        $entry->load(['lines.account', 'postedBy', 'fiscalPeriod']);

        return view('admin.accounting.journal-show', compact('entry'));
    }

    public function reports(Request $request): View
    {
        $report = $request->input('report', 'profit-and-loss');
        $from = $request->date('from')?->toDateString() ?? now()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();

        $data = match ($report) {
            'balance-sheet' => ['balanceSheet' => $this->reports->balanceSheet(null, $asOf)],
            'trial-balance' => ['trialBalance' => $this->reports->trialBalance(null, $from, $to)],
            default => ['profitAndLoss' => $this->reports->profitAndLoss(null, $from, $to)],
        };

        return view('admin.accounting.reports', array_merge($data, compact('report', 'from', 'to', 'asOf')));
    }

    public function settings(): View
    {
        $this->setup->ensureForBusiness(null);

        $accounts = LedgerAccount::whereNull('business_id')->active()->orderBy('code')->get();
        $mappings = LedgerMapping::whereNull('business_id')->with('account')->get()->keyBy('key');
        $periods = FiscalPeriod::whereNull('business_id')->orderByDesc('name')->limit(24)->get();
        $fiscalYears = \App\Models\FiscalYear::whereNull('business_id')->orderByDesc('name')->limit(6)->get();

        return view('admin.accounting.settings', compact('accounts', 'mappings', 'periods', 'fiscalYears'));
    }

    public function closeYear(Request $request, int $year): RedirectResponse
    {
        try {
            $entry = app(\App\Services\Accounting\LedgerClosingService::class)
                ->closeYear(null, $year, $request->user()->id);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $entry
            ? "Fiscal year {$year} closed — net result transferred to retained earnings."
            : "Fiscal year {$year} closed.");
    }

    public function updateMappings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.*' => ['required', Rule::exists('ledger_accounts', 'id')->whereNull('business_id')],
        ]);

        foreach ($validated['mappings'] as $key => $accountId) {
            LedgerMapping::updateOrCreate(
                ['business_id' => null, 'key' => $key],
                ['ledger_account_id' => $accountId]
            );
        }

        return back()->with('success', 'Platform account mappings updated.');
    }

    public function closePeriod(Request $request, FiscalPeriod $period): RedirectResponse
    {
        if ($period->business_id !== null) {
            abort(404);
        }

        $period->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $request->user()->id]);

        return back()->with('success', "Period {$period->name} closed.");
    }

    public function reopenPeriod(Request $request, FiscalPeriod $period): RedirectResponse
    {
        if ($period->business_id !== null) {
            abort(404);
        }

        $period->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);

        return back()->with('success', "Period {$period->name} reopened.");
    }
}
