<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
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
 *
 * The overview the SPA now renders adds a month-by-month series, the period
 * before it for comparison, and the report panels (aging, expense breakdown,
 * integrity) — all keyed additively, so the legacy contract above is untouched.
 *
 * Every money figure here is integer kobo, including the ones sourced from
 * documents: `arAging()` converts invoices to kobo and `apAging()` reads the
 * `*_kobo` columns directly. The SPA divides by 100 at the edge and nowhere
 * else.
 */
class AccountingDashboardController extends ApiController
{
    use ResolvesManagementContext;

    /** The range the dashboard opens on. */
    private const DEFAULT_MONTHS = 6;

    /** Two comparison windows' worth, and the widest series the chart will draw. */
    private const MAX_MONTHS = 24;

    public function __construct(private readonly LedgerReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = $user->business_id;

        // Legacy ensured the books existed before rendering the dashboard, so
        // a fresh business lands on a populated cash/bank panel — and so the
        // fiscal rows read below are guaranteed to exist.
        app(LedgerSetupService::class)->ensureForBusiness($businessId);

        $months = max(1, min(self::MAX_MONTHS, $request->integer('months', self::DEFAULT_MONTHS)));

        // The legacy six stay sourced from the reports they always came from —
        // balance sheet to date, P&L year to date — rather than being folded
        // into the series below. The two must agree wherever they overlap, and
        // the dashboard test asserts exactly that, which is the check that
        // keeps the incremental series honest.
        $balanceSheet = $this->reports->balanceSheet($businessId, now()->toDateString());
        $profitAndLoss = $this->reports->profitAndLoss(
            $businessId,
            now()->startOfYear()->toDateString(),
            now()->toDateString(),
        );

        $series = $this->reports->monthlyLedgerSeries($businessId, $months);

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

        $payload = [
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

            'period' => [
                'months' => $months,
                // How many complete months the totals and deltas cover — equal
                // to `months` unless the month in progress is still running.
                'comparison_months' => $series['comparison_months'],
                'partial' => $series['partial'],
                'from' => $series['from'],
                'to' => $series['to'],
            ],
            'totals' => $series['totals'],
            'previous' => $series['previous'],
            'series' => $series['series'],
            'fiscal' => $this->fiscal($businessId),
        ];

        return $this->ok(array_merge($payload, $this->reportBlocks($request, $businessId, $series['from'], $series['to'])));
    }

    /**
     * The panels the reports permission unlocks: aging both ways, the expense
     * breakdown, and the integrity check. Each is omitted rather than emptied
     * when the user may not see it — the same per-block gate
     * DashboardWidgetsController uses, and the SPA renders whichever slices are
     * present. The series and totals are not gated here because "accounting
     * view" already exposes the ledger sums they are built from.
     *
     * @return array<string, mixed>
     */
    private function reportBlocks(Request $request, int $businessId, string $from, string $to): array
    {
        if (! $this->user($request)->can('accounting reports')) {
            return [];
        }

        $today = now()->toDateString();

        return [
            'receivables' => $this->reports->arAging($businessId, $today),
            'payables' => $this->reports->apAging($businessId, $today),
            'expense_categories' => $this->reports->expenseSummary($businessId, $from, $to),
            'health' => $this->reports->integrity($businessId),
        ];
    }

    /**
     * Which book the business is currently in, so the header can say whether
     * the period is open or closed. ensureForBusiness() above guarantees the
     * rows exist; a business with none yet simply gets nulls.
     *
     * @return array<string, mixed>
     */
    private function fiscal(int $businessId): array
    {
        $year = FiscalYear::query()
            ->where('business_id', $businessId)
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->first();

        $period = FiscalPeriod::query()
            ->where('business_id', $businessId)
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->first();

        return [
            'year' => $year ? ['name' => $year->name, 'is_open' => $year->isOpen()] : null,
            'period' => $period ? [
                'name' => $period->name,
                'status' => $period->status,
                'is_open' => $period->isOpen(),
            ] : null,
        ];
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
