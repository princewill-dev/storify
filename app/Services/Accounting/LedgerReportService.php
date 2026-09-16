<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Illuminate\Support\Collection;

class LedgerReportService
{
    /**
     * Trial balance: per-account debit/credit totals for a date range.
     */
    public function trialBalance(?int $businessId, string $from, string $to): array
    {
        $rows = $this->accountTotals($businessId, $from, $to);

        $accounts = LedgerAccount::query()
            ->where('business_id', $businessId)
            ->orderBy('code')
            ->get();

        $lines = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($accounts as $account) {
            $row = $rows->get($account->id);

            if (! $row) {
                continue;
            }

            $debit = (int) $row->debit;
            $credit = (int) $row->credit;

            if ($debit === 0 && $credit === 0) {
                continue;
            }

            $totalDebit += $debit;
            $totalCredit += $credit;

            $lines[] = [
                'account' => $account,
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        return [
            'lines' => $lines,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
        ];
    }

    /**
     * Profit & loss for a date range.
     */
    public function profitAndLoss(?int $businessId, string $from, string $to): array
    {
        $accounts = LedgerAccount::query()
            ->where('business_id', $businessId)
            ->whereIn('type', [LedgerAccount::TYPE_INCOME, LedgerAccount::TYPE_EXPENSE])
            ->orderBy('code')
            ->get();

        $rows = $this->accountTotals($businessId, $from, $to);

        $income = [];
        $expenses = [];
        $totalIncome = 0;
        $totalExpenses = 0;

        foreach ($accounts as $account) {
            $row = $rows->get($account->id);

            if (! $row) {
                continue;
            }

            $debit = (int) $row->debit;
            $credit = (int) $row->credit;

            if ($account->type === LedgerAccount::TYPE_INCOME) {
                $balance = $credit - $debit;
                if ($balance !== 0) {
                    $income[] = ['account' => $account, 'amount' => $balance];
                    $totalIncome += $balance;
                }
            } else {
                $balance = $debit - $credit;
                if ($balance !== 0) {
                    $expenses[] = ['account' => $account, 'amount' => $balance];
                    $totalExpenses += $balance;
                }
            }
        }

        return [
            'income' => $income,
            'expenses' => $expenses,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net_profit' => $totalIncome - $totalExpenses,
        ];
    }

    /**
     * Balance sheet as of a date (cumulative to that date).
     */
    public function balanceSheet(?int $businessId, string $asOf): array
    {
        $rows = $this->accountTotals($businessId, null, $asOf);

        $accounts = LedgerAccount::query()
            ->where('business_id', $businessId)
            ->orderBy('code')
            ->get();

        $assets = [];
        $liabilities = [];
        $equity = [];
        $totalAssets = 0;
        $totalLiabilities = 0;
        $totalEquityAccounts = 0;
        $totalIncome = 0;
        $totalExpenses = 0;

        foreach ($accounts as $account) {
            $row = $rows->get($account->id);

            if (! $row) {
                continue;
            }

            $debit = (int) $row->debit;
            $credit = (int) $row->credit;

            switch ($account->type) {
                case LedgerAccount::TYPE_ASSET:
                    $balance = $debit - $credit;
                    if ($balance !== 0) {
                        $assets[] = ['account' => $account, 'amount' => $balance];
                        $totalAssets += $balance;
                    }
                    break;
                case LedgerAccount::TYPE_LIABILITY:
                    $balance = $credit - $debit;
                    if ($balance !== 0) {
                        $liabilities[] = ['account' => $account, 'amount' => $balance];
                        $totalLiabilities += $balance;
                    }
                    break;
                case LedgerAccount::TYPE_EQUITY:
                    $balance = $credit - $debit;
                    if ($balance !== 0) {
                        $equity[] = ['account' => $account, 'amount' => $balance];
                        $totalEquityAccounts += $balance;
                    }
                    break;
                case LedgerAccount::TYPE_INCOME:
                    $totalIncome += $credit - $debit;
                    break;
                case LedgerAccount::TYPE_EXPENSE:
                    $totalExpenses += $debit - $credit;
                    break;
            }
        }

        $netProfit = $totalIncome - $totalExpenses;

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquityAccounts + $netProfit,
            'net_profit' => $netProfit,
            'as_of' => $asOf,
        ];
    }

    /**
     * Account balances for an optional date range, keyed by account id.
     */
    private function accountTotals(?int $businessId, ?string $from, string $to): Collection
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->when($from, fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $from))
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->groupBy('journal_lines.ledger_account_id')
            ->selectRaw('journal_lines.ledger_account_id as account_id, SUM(journal_lines.debit_kobo) as debit, SUM(journal_lines.credit_kobo) as credit')
            ->get()
            ->keyBy('account_id');
    }

    /**
     * General ledger for a single account with running balance.
     */
    public function generalLedger(?int $businessId, int $accountId, string $from, string $to): array
    {
        $account = LedgerAccount::query()
            ->where('business_id', $businessId)
            ->findOrFail($accountId);

        $opening = (int) JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.ledger_account_id', $accountId)
            ->whereDate('journal_entries.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(debit_kobo), 0) - COALESCE(SUM(credit_kobo), 0) as balance')
            ->value('balance');

        $lines = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.ledger_account_id', $accountId)
            ->whereDate('journal_entries.entry_date', '>=', $from)
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_lines.id')
            ->get([
                'journal_lines.*',
                'journal_entries.entry_number',
                'journal_entries.entry_date',
                'journal_entries.memo',
            ]);

        $running = $opening;
        $rows = [];

        foreach ($lines as $line) {
            $running += (int) $line->debit_kobo - (int) $line->credit_kobo;

            $rows[] = [
                'date' => $line->entry_date,
                'entry_number' => $line->entry_number,
                'memo' => $line->memo ?? $line->description,
                'debit' => (int) $line->debit_kobo,
                'credit' => (int) $line->credit_kobo,
                'balance' => $running,
            ];
        }

        return [
            'account' => $account,
            'opening' => $opening,
            'rows' => $rows,
            'closing' => $running,
        ];
    }

    /**
     * Accounts receivable aging as of a date.
     */
    public function arAging(?int $businessId, string $asOf): array
    {
        $invoices = \App\Models\Invoice::query()
            ->where('business_id', $businessId)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereColumn('amount_paid', '<', 'total')
            ->with('customer')
            ->orderBy('due_date')
            ->get();

        return $this->ageBuckets($invoices, $asOf, fn ($invoice) => [
            'reference' => $invoice->invoice_number,
            'contact' => $invoice->customer?->full_name ?? $invoice->recipient_name ?? '—',
            'total' => (int) round((float) $invoice->total * 100),
            'paid' => (int) round((float) $invoice->amount_paid * 100),
            'due_date' => $invoice->due_date,
        ]);
    }

    /**
     * Accounts payable aging as of a date.
     */
    public function apAging(?int $businessId, string $asOf): array
    {
        $bills = \App\Models\Bill::query()
            ->where('business_id', $businessId)
            ->whereIn('status', ['open', 'partial'])
            ->whereColumn('amount_paid_kobo', '<', 'total_kobo')
            ->with('supplier')
            ->orderBy('due_date')
            ->get();

        return $this->ageBuckets($bills, $asOf, fn ($bill) => [
            'reference' => $bill->bill_number,
            'contact' => $bill->supplier?->name ?? '—',
            'total' => (int) $bill->total_kobo,
            'paid' => (int) $bill->amount_paid_kobo,
            'due_date' => $bill->due_date,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $records
     */
    private function ageBuckets(Collection $records, string $asOf, callable $map): array
    {
        $buckets = [
            'current' => ['label' => 'Current', 'total' => 0, 'rows' => []],
            '1_30' => ['label' => '1–30 days', 'total' => 0, 'rows' => []],
            '31_60' => ['label' => '31–60 days', 'total' => 0, 'rows' => []],
            '61_90' => ['label' => '61–90 days', 'total' => 0, 'rows' => []],
            'over_90' => ['label' => '90+ days', 'total' => 0, 'rows' => []],
        ];

        $asOfDate = \Illuminate\Support\Carbon::parse($asOf);
        $grandTotal = 0;

        foreach ($records as $record) {
            $mapped = $map($record);
            $outstanding = max(0, $mapped['total'] - $mapped['paid']);

            if ($outstanding <= 0) {
                continue;
            }

            $dueDate = $mapped['due_date'] ? \Illuminate\Support\Carbon::parse($mapped['due_date']) : $asOfDate;
            $daysPastDue = $dueDate->greaterThan($asOfDate) ? 0 : $dueDate->diffInDays($asOfDate);

            $key = match (true) {
                $daysPastDue <= 0 => 'current',
                $daysPastDue <= 30 => '1_30',
                $daysPastDue <= 60 => '31_60',
                $daysPastDue <= 90 => '61_90',
                default => 'over_90',
            };

            $mapped['outstanding'] = $outstanding;
            $mapped['days_past_due'] = $daysPastDue;

            $buckets[$key]['rows'][] = $mapped;
            $buckets[$key]['total'] += $outstanding;
            $grandTotal += $outstanding;
        }

        return ['buckets' => $buckets, 'grand_total' => $grandTotal, 'as_of' => $asOf];
    }

    /**
     * VAT summary for a date range: output tax vs input tax.
     */
    public function vatSummary(?int $businessId, string $from, string $to): array
    {
        $row = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.tax_kobo', '>', 0)
            ->whereDate('journal_entries.entry_date', '>=', $from)
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->selectRaw('SUM(CASE WHEN journal_lines.credit_kobo > 0 THEN journal_lines.tax_kobo ELSE 0 END) as output_tax')
            ->selectRaw('SUM(CASE WHEN journal_lines.debit_kobo > 0 THEN journal_lines.tax_kobo ELSE 0 END) as input_tax')
            ->first();

        $outputTax = (int) ($row->output_tax ?? 0);
        $inputTax = (int) ($row->input_tax ?? 0);

        return [
            'output_tax' => $outputTax,
            'input_tax' => $inputTax,
            'net_payable' => $outputTax - $inputTax,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Expenses grouped by category for a date range.
     */
    public function expenseSummary(?int $businessId, string $from, string $to): array
    {
        $expenses = \App\Models\Expense::query()
            ->where('business_id', $businessId)
            ->where('status', '!=', 'void')
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->with(['category', 'ledgerAccount'])
            ->get();

        $byCategory = $expenses->groupBy(fn ($expense) => $expense->category?->name ?? $expense->ledgerAccount?->name ?? 'Uncategorised')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'total' => (int) $group->sum('total_kobo'),
            ])
            ->sortByDesc('total');

        return [
            'categories' => $byCategory,
            'grand_total' => (int) $expenses->sum('total_kobo'),
            'count' => $expenses->count(),
        ];
    }

    /**
     * Integrity checks: trial balance, wallet vs ledger, unposted events, AR.
     */
    public function integrity(?int $businessId): array
    {
        $totals = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_kobo), 0) as debit, COALESCE(SUM(journal_lines.credit_kobo), 0) as credit')
            ->first();

        $totalDebit = (int) ($totals->debit ?? 0);
        $totalCredit = (int) ($totals->credit ?? 0);

        // Wallet vs ledger per store (cash, bank, gateway clearing)
        $walletRows = [];
        $stores = \App\Models\Store::query()
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->get();

        foreach ($stores as $store) {
            $ledger = (int) JournalLine::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
                ->where('journal_entries.business_id', $businessId)
                ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
                ->where('journal_lines.store_id', $store->id)
                ->whereIn('ledger_accounts.subtype', ['cash', 'bank', 'gateway_clearing'])
                ->selectRaw('COALESCE(SUM(journal_lines.debit_kobo), 0) - COALESCE(SUM(journal_lines.credit_kobo), 0) as balance')
                ->value('balance');

            $walletRows[] = [
                'store' => $store,
                'wallet' => (int) $store->balance,
                'ledger' => $ledger,
                'difference' => (int) $store->balance - $ledger,
            ];
        }

        // Unposted confirmed transactions
        $unpostedTransactions = \App\Models\Transaction::query()
            ->where('business_id', $businessId)
            ->whereIn('status', [\App\Enums\TransactionStatus::CONFIRMED, \App\Enums\TransactionStatus::PAID])
            ->whereNotNull('order_id')
            ->whereNotExists(function ($q) {
                $q->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('journal_entries')
                    ->whereColumn('journal_entries.business_id', 'transactions.business_id')
                    ->where(function ($inner) {
                        $inner->whereColumn('journal_entries.idempotency_key', \Illuminate\Support\Facades\DB::raw("CONCAT('payment:txn:', transactions.id)"))
                            ->orWhereColumn('journal_entries.idempotency_key', \Illuminate\Support\Facades\DB::raw("CONCAT('sale:order:', transactions.order_id)"));
                    });
            })
            ->count();

        // AR: ledger vs open documents
        $ledgerAr = (int) JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('ledger_accounts.subtype', 'accounts_receivable')
            ->selectRaw('COALESCE(SUM(journal_lines.debit_kobo), 0) - COALESCE(SUM(journal_lines.credit_kobo), 0) as balance')
            ->value('balance');

        $orderAr = (int) round(((float) \App\Models\Order::query()
            ->where('business_id', $businessId)
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->whereColumn('amount_paid', '<', 'total')
            ->selectRaw('COALESCE(SUM(total - amount_paid), 0) as balance')
            ->value('balance')) * 100);

        $invoiceAr = (int) round(((float) \App\Models\Invoice::query()
            ->where('business_id', $businessId)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereColumn('amount_paid', '<', 'total')
            ->selectRaw('COALESCE(SUM(total - amount_paid), 0) as balance')
            ->value('balance')) * 100);

        return [
            'trial_debit' => $totalDebit,
            'trial_credit' => $totalCredit,
            'trial_balanced' => $totalDebit === $totalCredit,
            'wallet_rows' => $walletRows,
            'wallet_total' => array_sum(array_column($walletRows, 'wallet')),
            'ledger_cash_total' => array_sum(array_column($walletRows, 'ledger')),
            'unposted_transactions' => $unpostedTransactions,
            'ledger_ar' => $ledgerAr,
            'documents_ar' => $orderAr + $invoiceAr,
            'ar_difference' => $ledgerAr - ($orderAr + $invoiceAr),
        ];
    }
}
