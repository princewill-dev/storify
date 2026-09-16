<?php

namespace App\Http\Controllers\Management\Accounting;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Transaction;
use App\Services\Accounting\LedgerSetupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AccountingDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $businessId = $request->user()->business_id;

        app(LedgerSetupService::class)->ensureForBusiness($businessId);

        $byType = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->groupBy('ledger_accounts.type')
            ->selectRaw('ledger_accounts.type as type, SUM(journal_lines.debit_kobo) as debit, SUM(journal_lines.credit_kobo) as credit')
            ->get()
            ->keyBy('type');

        $asset = $byType->get('asset');
        $liability = $byType->get('liability');
        $equity = $byType->get('equity');

        $totals = [
            'assets' => (int) (($asset->debit ?? 0) - ($asset->credit ?? 0)),
            'liabilities' => (int) (($liability->credit ?? 0) - ($liability->debit ?? 0)),
            'equity' => (int) (($equity->credit ?? 0) - ($equity->debit ?? 0)),
        ];

        $yearIncome = $this->typeTotal($businessId, 'income', now()->startOfYear()->toDateString());
        $yearExpenses = $this->typeTotal($businessId, 'expense', now()->startOfYear()->toDateString());

        $totals['income_ytd'] = $yearIncome;
        $totals['expenses_ytd'] = $yearExpenses;
        $totals['net_profit_ytd'] = $yearIncome - $yearExpenses;

        $cashAccounts = LedgerAccount::query()
            ->where('business_id', $businessId)
            ->whereIn('subtype', ['cash', 'bank', 'gateway_clearing'])
            ->orderBy('code')
            ->get();

        $cashBalances = $cashAccounts->mapWithKeys(function (LedgerAccount $account) use ($businessId) {
            $row = JournalLine::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.business_id', $businessId)
                ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
                ->where('journal_lines.ledger_account_id', $account->id)
                ->selectRaw('SUM(journal_lines.debit_kobo) as debit, SUM(journal_lines.credit_kobo) as credit')
                ->first();

            return [$account->id => (int) (($row->debit ?? 0) - ($row->credit ?? 0))];
        });

        $recentEntries = JournalEntry::query()
            ->where('business_id', $businessId)
            ->withCount('lines')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        $unpostedCount = Transaction::query()
            ->where('business_id', $businessId)
            ->whereIn('status', [TransactionStatus::CONFIRMED, TransactionStatus::PAID])
            ->whereNotNull('order_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('journal_entries')
                    ->whereColumn('journal_entries.business_id', 'transactions.business_id')
                    ->where(function ($inner) {
                        $inner->whereColumn('journal_entries.idempotency_key', DB::raw("CONCAT('payment:txn:', transactions.id)"))
                            ->orWhereColumn('journal_entries.idempotency_key', DB::raw("CONCAT('sale:order:', transactions.order_id)"));
                    });
            })
            ->count();

        return view('management.accounting.dashboard', compact(
            'totals',
            'cashAccounts',
            'cashBalances',
            'recentEntries',
            'unpostedCount'
        ));
    }

    private function typeTotal(int $businessId, string $type, string $fromDate): int
    {
        $row = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('ledger_accounts.type', $type)
            ->whereDate('journal_entries.entry_date', '>=', $fromDate)
            ->selectRaw('SUM(journal_lines.debit_kobo) as debit, SUM(journal_lines.credit_kobo) as credit')
            ->first();

        $debit = (int) ($row->debit ?? 0);
        $credit = (int) ($row->credit ?? 0);

        return $type === 'income' ? $credit - $debit : $debit - $credit;
    }
}
