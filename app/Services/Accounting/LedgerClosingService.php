<?php

namespace App\Services\Accounting;

use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;

class LedgerClosingService
{
    public function __construct(
        private readonly LedgerPostingService $posting,
        private readonly LedgerSetupService $setup,
    ) {}

    /**
     * Close a fiscal year: zero all income/expense accounts and transfer the
     * net result to retained earnings. The closing entry is dated the first
     * day of the following year so the closed year's P&L stays intact.
     */
    public function closeYear(?int $businessId, int $year, ?int $userId = null): ?JournalEntry
    {
        $fiscalYear = FiscalYear::query()
            ->where('business_id', $businessId)
            ->where('name', (string) $year)
            ->first();

        if (! $fiscalYear) {
            throw new \RuntimeException("Fiscal year {$year} does not exist for these books.");
        }

        if (! $fiscalYear->isOpen()) {
            throw new \RuntimeException("Fiscal year {$year} is already closed.");
        }

        $from = $fiscalYear->start_date->toDateString();
        $to = $fiscalYear->end_date->toDateString();

        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->whereIn('ledger_accounts.type', [LedgerAccount::TYPE_INCOME, LedgerAccount::TYPE_EXPENSE])
            ->whereDate('journal_entries.entry_date', '>=', $from)
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->groupBy('journal_lines.ledger_account_id', 'ledger_accounts.type', 'ledger_accounts.name')
            ->selectRaw('journal_lines.ledger_account_id as account_id, ledger_accounts.type as type, ledger_accounts.name as name, SUM(journal_lines.debit_kobo) as debit, SUM(journal_lines.credit_kobo) as credit')
            ->get();

        $lines = [];
        $netIncome = 0;

        foreach ($rows as $row) {
            $debit = (int) $row->debit;
            $credit = (int) $row->credit;

            if ($row->type === LedgerAccount::TYPE_INCOME) {
                $balance = $credit - $debit;

                if ($balance > 0) {
                    $lines[] = [
                        'account_id' => (int) $row->account_id,
                        'debit' => $balance,
                        'description' => 'Year-end close '.$year,
                    ];
                } elseif ($balance < 0) {
                    $lines[] = [
                        'account_id' => (int) $row->account_id,
                        'credit' => abs($balance),
                        'description' => 'Year-end close '.$year,
                    ];
                }

                $netIncome += $balance;
            } else {
                $balance = $debit - $credit;

                if ($balance > 0) {
                    $lines[] = [
                        'account_id' => (int) $row->account_id,
                        'credit' => $balance,
                        'description' => 'Year-end close '.$year,
                    ];
                } elseif ($balance < 0) {
                    $lines[] = [
                        'account_id' => (int) $row->account_id,
                        'debit' => abs($balance),
                        'description' => 'Year-end close '.$year,
                    ];
                }

                $netIncome -= $balance;
            }
        }

        $retainedId = $this->setup->accountId($businessId, 'retained_earnings');

        if ($netIncome > 0) {
            $lines[] = ['account_id' => $retainedId, 'credit' => $netIncome, 'description' => 'Retained earnings '.$year];
        } elseif ($netIncome < 0) {
            $lines[] = ['account_id' => $retainedId, 'debit' => abs($netIncome), 'description' => 'Retained earnings '.$year];
        }

        if (empty($lines)) {
            // Nothing to close — just lock the year.
            $this->lockYear($fiscalYear, $userId);

            return null;
        }

        $closingDate = $fiscalYear->end_date->copy()->addDay()->toDateString();

        $entry = $this->posting->post($businessId, $lines, [
            'memo' => "Year-end close {$year} — net ".($netIncome >= 0 ? 'profit' : 'loss').' ₦'.number_format(abs($netIncome) / 100, 2),
            'idempotency_key' => 'year_close:'.$year,
            'user_id' => $userId,
            'date' => $closingDate,
        ]);

        $this->lockYear($fiscalYear, $userId);

        return $entry;
    }

    private function lockYear(FiscalYear $fiscalYear, ?int $userId): void
    {
        $fiscalYear->update(['status' => 'closed']);

        FiscalPeriod::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $userId,
            ]);
    }
}
