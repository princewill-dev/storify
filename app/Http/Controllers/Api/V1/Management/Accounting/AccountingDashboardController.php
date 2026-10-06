<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Transaction;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\LedgerSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WS-23 — Accounting dashboard.
 *
 * Re-registers `accounting/dashboard` (feature modules load after the shared
 * route file, so this registration wins). The six totals the shared
 * AccountingController returned are kept unchanged — the existing
 * AccountingDashboardView still reads them — and the payload is extended with
 * the blocks legacy showed: cash & bank balances, the last 8 entries, and the
 * unposted-confirmed-payments warning that tells a business its books are
 * behind.
 */
class AccountingDashboardController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(private readonly LedgerReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        // Legacy ensured the books existed before rendering the dashboard, so
        // a fresh business lands on a populated cash/bank panel.
        app(LedgerSetupService::class)->ensureForBusiness($businessId);

        $balanceSheet = $this->reports->balanceSheet($businessId, now()->toDateString());
        $profitAndLoss = $this->reports->profitAndLoss(
            $businessId,
            now()->startOfYear()->toDateString(),
            now()->toDateString(),
        );

        $cashAccounts = LedgerAccount::query()
            ->where('business_id', $businessId)
            ->whereIn('subtype', ['cash', 'bank', 'gateway_clearing'])
            ->orderBy('code')
            ->get();

        $balances = $this->cashBalances($businessId, $cashAccounts->pluck('id')->all());

        $recentEntries = JournalEntry::query()
            ->where('business_id', $businessId)
            ->withCount('lines')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        $unpostedCount = $this->unpostedPaymentCount($businessId);

        return $this->ok([
            'assets' => $balanceSheet['total_assets'],
            'liabilities' => $balanceSheet['total_liabilities'],
            'equity' => $balanceSheet['total_equity'],
            'income_ytd' => $profitAndLoss['total_income'],
            'expenses_ytd' => $profitAndLoss['total_expenses'],
            'net_profit_ytd' => $profitAndLoss['net_profit'],
            'cash_balances' => $cashAccounts->map(fn (LedgerAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'subtype' => $account->subtype,
                'balance_kobo' => $balances[$account->id] ?? 0,
            ])->values()->all(),
            'recent_entries' => $recentEntries->map(fn (JournalEntry $entry) => [
                'id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date?->toDateString(),
                'memo' => $entry->memo,
                'status' => $entry->status,
                'lines_count' => (int) $entry->lines_count,
            ])->all(),
            'unposted_payments' => [
                'count' => $unpostedCount,
                'hint' => 'Run php artisan ledger:reconcile --post to backfill them.',
            ],
        ]);
    }

    /**
     * Cash/bank/clearing balances are debit-normal assets, so debit - credit.
     *
     * @param  array<int, int>  $accountIds
     * @return array<int, int>
     */
    private function cashBalances(int $businessId, array $accountIds): array
    {
        if (empty($accountIds)) {
            return [];
        }

        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->whereIn('journal_lines.ledger_account_id', $accountIds)
            ->groupBy('journal_lines.ledger_account_id')
            ->selectRaw('journal_lines.ledger_account_id, SUM(journal_lines.debit_kobo) as debit, SUM(journal_lines.credit_kobo) as credit')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->ledger_account_id => (int) $row->debit - (int) $row->credit])
            ->all();
    }

    /**
     * Confirmed/paid order payments with no ledger entry either for the
     * payment itself or for the sale that created it.
     */
    private function unpostedPaymentCount(int $businessId): int
    {
        return Transaction::query()
            ->where('business_id', $businessId)
            ->whereIn('status', [TransactionStatus::CONFIRMED->value, TransactionStatus::PAID->value])
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
    }
}
