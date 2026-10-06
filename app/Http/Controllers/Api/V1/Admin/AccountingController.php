<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\LedgerMapping;
use App\Services\Accounting\LedgerAccountTemplate;
use App\Services\Accounting\LedgerClosingService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\LedgerSetupService;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * WS-13 (admin console) — Storify's own books.
 *
 * The legacy `/office/accounting` module (dashboard, chart, journal, reports,
 * mappings/fiscal settings) had no new-stack equivalent: the management
 * accounting API is `token.audience:management` and business-scoped, so a
 * platform admin could not read a single platform entry. This controller is
 * the platform (`business_id = null`) port. It deliberately reuses the
 * management accounting services — `LedgerSetupService` bootstraps the
 * platform chart, `LedgerReportService` produces every statement and
 * `LedgerClosingService` closes a year — so the two consoles never grow two
 * ledger dialects. No accounting engine lives in this file.
 *
 * Guarded by `permission:admin.accounting` (already seeded) plus the shared
 * platform-role check: business-scoped "Super Admin" roles carry the whole
 * `admin.*` permission bundle, so the permission middleware alone would let a
 * leaked admin-audience token read the platform books.
 *
 * Money on the wire is integer kobo (`*_kobo` except the statement rows the
 * report service already returns as `amount`), matching the management API.
 *
 * Improvements on legacy, called out where they live in the code:
 *  - a CSV export of the journal (legacy had no export);
 *  - mapping keys are validated against the template's 20 keys, so a typo no
 *    longer creates a junk mapping row;
 *  - the settings picker returns every account with its `is_active` flag, so
 *    an inactive account a mapping already points at stays visible;
 *  - closing an already-closed period/year is a 422, not a silent no-op;
 *  - account balances are computed with one grouped query instead of the
 *    legacy dashboard's query-per-cash-account loop.
 */
class AccountingController extends ApiController
{
    use EnsuresPlatformAdmin;

    /**
     * Legacy totalled *every* posted platform entry with no date window. The
     * report service needs a lower bound, so the platform dashboard asks from
     * the epoch — same numbers, one engine.
     */
    private const ALL_TIME_FROM = '1970-01-01';

    /** Human labels for the 20 mapping keys (the legacy admin screen's set). */
    private const MAPPING_LABELS = [
        'cash' => 'Cash on Hand',
        'bank' => 'Bank',
        'gateway_clearing' => 'Payment Gateway Clearing',
        'accounts_receivable' => 'Accounts Receivable',
        'inventory' => 'Inventory',
        'fixed_assets' => 'Fixed Assets',
        'accounts_payable' => 'Accounts Payable',
        'tax_payable' => 'VAT Payable',
        'accrued_liabilities' => 'Accrued Liabilities',
        'owner_equity' => "Owner's Equity",
        'retained_earnings' => 'Retained Earnings',
        'opening_balance_equity' => 'Opening Balance Equity',
        'sales_income' => 'Subscription Revenue',
        'service_charge_income' => 'Service Charge Income',
        'shipping_income' => 'Shipping Income',
        'other_income' => 'Other Income',
        'sales_discounts' => 'Sales Discounts',
        'cogs' => 'Cost of Goods Sold',
        'gateway_fees' => 'Bank & Gateway Fees',
        'default_expense' => 'Miscellaneous Expense',
    ];

    /** Chart-of-accounts grouping order; mirrors the legacy grouped tables. */
    private const TYPE_LABELS = [
        LedgerAccount::TYPE_ASSET => 'Assets',
        LedgerAccount::TYPE_LIABILITY => 'Liabilities',
        LedgerAccount::TYPE_EQUITY => 'Equity',
        LedgerAccount::TYPE_INCOME => 'Income',
        LedgerAccount::TYPE_EXPENSE => 'Expenses',
    ];

    public function __construct(
        private readonly LedgerReportService $reports,
        private readonly LedgerSetupService $setup,
        private readonly LedgerClosingService $closing,
    ) {}

    /**
     * The platform books dashboard: five totals, cash & clearing balances and
     * the last 10 journal entries (legacy's Recent Journal Entries table).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // Legacy bootstrapped the books on this screen, so a fresh install
        // lands on a populated chart and cash panel rather than five zeroes
        // and "no accounts".
        $this->setup->ensureForBusiness(null);

        $today = now()->toDateString();

        $balanceSheet = $this->reports->balanceSheet(null, $today);
        $profitAndLoss = $this->reports->profitAndLoss(null, self::ALL_TIME_FROM, $today);

        $cashAccounts = LedgerAccount::query()
            ->whereNull('business_id')
            ->whereIn('subtype', ['cash', 'bank', 'gateway_clearing'])
            ->orderBy('code')
            ->get();

        $balances = $this->balancesByAccount($cashAccounts->pluck('id')->all());

        $recentEntries = JournalEntry::query()
            ->whereNull('business_id')
            ->withCount('lines')
            ->withSum('lines as total_debits', 'debit_kobo')
            ->withSum('lines as total_credits', 'credit_kobo')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return $this->ok([
            'totals' => [
                'assets' => $balanceSheet['total_assets'],
                'liabilities' => $balanceSheet['total_liabilities'],
                'equity' => $balanceSheet['total_equity'],
                'revenue' => $profitAndLoss['total_income'],
                'expenses' => $profitAndLoss['total_expenses'],
                'net_profit' => $profitAndLoss['net_profit'],
            ],
            'cash_balances' => $cashAccounts->map(fn (LedgerAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'subtype' => $account->subtype,
                'balance_kobo' => $balances[$account->id] ?? 0,
            ])->values()->all(),
            'recent_entries' => $recentEntries->map(fn (JournalEntry $entry) => $this->entryRow($entry))->all(),
        ]);
    }

    /**
     * The platform chart of accounts, grouped by type with per-group counts
     * (legacy) and computed balances (improvement: one grouped query).
     */
    public function accounts(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->setup->ensureForBusiness(null);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $accounts = LedgerAccount::query()
            ->whereNull('business_id')
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $query->where(function ($inner) use ($term) {
                    $inner->where('code', 'like', '%'.$term.'%')
                        ->orWhere('name', 'like', '%'.$term.'%');
                });
            })
            ->orderBy('code')
            ->get();

        $balances = $this->balancesByAccount($accounts->pluck('id')->all());

        $groups = [];

        foreach (self::TYPE_LABELS as $type => $label) {
            $groupAccounts = $accounts->where('type', $type)->values();

            if ($groupAccounts->isEmpty()) {
                continue;
            }

            $rows = $groupAccounts->map(fn (LedgerAccount $account) => [
                ...$this->accountPayload($account),
                'balance_kobo' => $this->signedBalance($account, $balances[$account->id] ?? 0),
            ])->all();

            $groups[] = [
                'type' => $type,
                'label' => $label,
                'count' => count($rows),
                'balance_kobo' => array_sum(array_column($rows, 'balance_kobo')),
                'accounts' => $rows,
            ];
        }

        return $this->ok([
            'groups' => $groups,
            'total_accounts' => $accounts->count(),
        ]);
    }

    /**
     * The journal list: 20/page, newest first, searchable by entry number,
     * reference or memo (legacy's three-way search).
     */
    public function journal(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $this->validateJournalFilters($request);

        $entries = $this->journalQuery($filters)
            ->withCount('lines')
            ->withSum('lines as total_debits', 'debit_kobo')
            ->withSum('lines as total_credits', 'credit_kobo')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        // Totals for the filtered set (not just the page) so the footer agrees
        // with the search, the same shape the management journal returns.
        $totals = $this->journalQuery($filters)
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->selectRaw('COALESCE(SUM(journal_lines.debit_kobo), 0) as debits, COALESCE(SUM(journal_lines.credit_kobo), 0) as credits')
            ->first();

        return $this->ok(
            ['entries' => $entries->getCollection()->map(fn (JournalEntry $entry) => $this->entryRow($entry))->values()->all()],
            null,
            200,
            [
                ...$this->paginationMeta($entries),
                'totals' => [
                    'debits' => (int) ($totals->debits ?? 0),
                    'credits' => (int) ($totals->credits ?? 0),
                ],
            ]
        );
    }

    /**
     * The journal CSV export (improvement on legacy — no export existed).
     * Same filters as the list; money is written as decimal naira strings,
     * exactly the accountant-friendly convention the management report
     * exports use, so `platform-journal.csv` opens next to them.
     */
    public function journalExport(Request $request): StreamedResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $this->validateJournalFilters($request);

        $entries = $this->journalQuery($filters)
            ->withCount('lines')
            ->withSum('lines as total_debits', 'debit_kobo')
            ->withSum('lines as total_credits', 'credit_kobo')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get();

        Log::info('api.admin.accounting_journal_exported', [
            'user_id' => $request->user()->id,
            'rows' => $entries->count(),
            'filters' => array_filter($filters),
        ]);

        return response()->streamDownload(function () use ($entries) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Entry', 'Date', 'Reference', 'Memo', 'Status', 'Lines', 'Debits (NGN)', 'Credits (NGN)']);

            foreach ($entries as $entry) {
                fputcsv($handle, [
                    $entry->entry_number,
                    $entry->entry_date?->toDateString(),
                    $entry->reference,
                    $entry->memo,
                    $entry->status,
                    (int) $entry->lines_count,
                    $this->naira((int) $entry->total_debits),
                    $this->naira((int) $entry->total_credits),
                ]);
            }

            fclose($handle);
        }, 'platform-journal.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * A single platform entry. Non-platform entries 404 (legacy rule): the
     * admin console must never leak a business's ledger through a guessed id.
     */
    public function journalShow(Request $request, JournalEntry $entry): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($entry->business_id !== null) {
            abort(404);
        }

        $entry->load([
            'lines.account:id,code,name',
            'postedBy:id,name',
            'fiscalPeriod:id,name',
            'reversalOf:id,entry_number',
        ]);

        $reversedBy = JournalEntry::query()
            ->whereNull('business_id')
            ->where('reversal_of_id', $entry->id)
            ->first(['id', 'entry_number']);

        $lines = $entry->lines->map(fn (JournalLine $line) => [
            'id' => $line->id,
            'account_id' => $line->ledger_account_id,
            'account_code' => $line->account?->code,
            'account_name' => $line->account?->name,
            'description' => $line->description,
            'debit_kobo' => (int) $line->debit_kobo,
            'credit_kobo' => (int) $line->credit_kobo,
        ])->values()->all();

        $totalDebits = array_sum(array_column($lines, 'debit_kobo'));
        $totalCredits = array_sum(array_column($lines, 'credit_kobo'));

        return $this->ok([
            'entry' => [
                'id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date?->toDateString(),
                'memo' => $entry->memo,
                'reference' => $entry->reference,
                'status' => $entry->status,
                'posted_at' => $entry->posted_at?->toISOString(),
                'created_at' => $entry->created_at?->toISOString(),
                'fiscal_period' => $entry->fiscalPeriod ? [
                    'id' => $entry->fiscalPeriod->id,
                    'name' => $entry->fiscalPeriod->name,
                ] : null,
                'posted_by' => $entry->postedBy ? [
                    'id' => $entry->postedBy->id,
                    'name' => $entry->postedBy->name,
                ] : null,
                'lines' => $lines,
                'total_debits' => $totalDebits,
                'total_credits' => $totalCredits,
                'balanced' => $totalDebits === $totalCredits,
                'reversal' => [
                    'is_reversal' => $entry->reversal_of_id !== null,
                    'reversal_of' => $entry->reversalOf ? [
                        'id' => $entry->reversalOf->id,
                        'entry_number' => $entry->reversalOf->entry_number,
                    ] : null,
                    'reversed_by' => $reversedBy ? [
                        'id' => $reversedBy->id,
                        'entry_number' => $reversedBy->entry_number,
                    ] : null,
                    'voided_at' => $entry->voided_at?->toISOString(),
                ],
            ],
        ]);
    }

    /**
     * The three platform statements from one screen. Legacy defaults:
     * P&L and Trial Balance run year-to-date, the Balance Sheet as of today.
     *
     * `report` accepts the new snake_case keys and the legacy dashed aliases —
     * bookmarked legacy URLs keep working.
     */
    public function reports(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $rules = [
            'report' => ['nullable', Rule::in([
                'pnl', 'profit-and-loss', 'profit_and_loss',
                'balance_sheet', 'balance-sheet',
                'trial_balance', 'trial-balance',
            ])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'as_of' => ['nullable', 'date'],
        ];

        if ($request->filled('from')) {
            $rules['to'][] = 'after_or_equal:from';
        }

        $validated = $request->validate($rules);

        $report = match ($validated['report'] ?? 'pnl') {
            'balance_sheet', 'balance-sheet' => 'balance_sheet',
            'trial_balance', 'trial-balance' => 'trial_balance',
            default => 'pnl',
        };

        $from = $request->date('from')?->toDateString() ?? now()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();

        $statement = match ($report) {
            'balance_sheet' => $this->balanceSheetStatement($asOf),
            'trial_balance' => $this->trialBalanceStatement($from, $to),
            default => $this->profitAndLossStatement($from, $to),
        };

        return $this->ok([
            'report' => $report,
            'from' => $from,
            'to' => $to,
            'as_of' => $asOf,
            'statement' => $statement,
        ]);
    }

    /**
     * Platform accounting settings: the 20 auto-posting mappings, the 24 most
     * recent fiscal periods and the fiscal years.
     */
    public function settings(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->setup->ensureForBusiness(null);

        // Legacy's picker was active-only, so a mapping pointing at an account
        // someone deactivated rendered as a blank select and a save silently
        // repointed it. Every account travels with `is_active` instead.
        $accounts = LedgerAccount::query()
            ->whereNull('business_id')
            ->orderBy('code')
            ->get();

        $mappings = LedgerMapping::query()
            ->whereNull('business_id')
            ->with('account')
            ->get()
            ->keyBy('key');

        return $this->ok([
            'accounts' => $accounts->map(fn (LedgerAccount $account) => $this->accountPayload($account))->values()->all(),
            'mappings' => $this->mappingsPayload($mappings),
            'periods' => FiscalPeriod::query()
                ->whereNull('business_id')
                ->orderByDesc('name')
                ->limit(24)
                ->get()
                ->map(fn (FiscalPeriod $period) => $this->periodPayload($period))
                ->all(),
            'fiscal_years' => FiscalYear::query()
                ->whereNull('business_id')
                ->orderByDesc('name')
                ->limit(6)
                ->get()
                ->map(fn (FiscalYear $year) => $this->yearPayload($year))
                ->all(),
        ]);
    }

    /**
     * Save the auto-posting mappings. Only platform accounts are acceptable
     * targets — an id from any business's chart is refused, the same way a
     * platform admin has no business id to confuse it with.
     */
    public function updateMappings(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $validated = $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.*' => ['required', 'integer', Rule::exists('ledger_accounts', 'id')->whereNull('business_id')],
        ]);

        // Legacy wrote a row for whatever key arrived; a typo shadowed the
        // real key and left the event posting to the old default.
        $unknown = array_diff(array_keys($validated['mappings']), array_keys(LedgerAccountTemplate::mappings()));

        if (! empty($unknown)) {
            throw ValidationException::withMessages([
                'mappings' => 'Unknown mapping key(s): '.implode(', ', $unknown).'.',
            ]);
        }

        DB::transaction(function () use ($validated) {
            foreach ($validated['mappings'] as $key => $accountId) {
                LedgerMapping::updateOrCreate(
                    ['business_id' => null, 'key' => $key],
                    ['ledger_account_id' => $accountId]
                );
            }
        });

        ActivityLogger::log('accounting_mappings_updated', 'Platform accounting mappings updated.', [
            'keys' => array_keys($validated['mappings']),
        ]);

        Log::info('api.admin.accounting_mappings_updated', [
            'user_id' => $request->user()->id,
            'keys' => array_keys($validated['mappings']),
        ]);

        $mappings = LedgerMapping::query()
            ->whereNull('business_id')
            ->with('account')
            ->get()
            ->keyBy('key');

        return $this->ok(['mappings' => $this->mappingsPayload($mappings)], 'Platform account mappings updated.');
    }

    /**
     * Close a fiscal period. Non-platform periods 404 — a platform admin
     * closing a tenant's books by guessing an id is exactly the cross-tenant
     * hole this guard exists to stop.
     */
    public function closePeriod(Request $request, FiscalPeriod $period): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->authorizePlatformPeriod($period);

        if (! $period->isOpen()) {
            return $this->error('This period is already closed.');
        }

        $period->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $request->user()->id,
        ]);

        ActivityLogger::log('accounting_period_closed', "Platform fiscal period {$period->name} closed.", [
            'period_id' => $period->id,
        ]);

        Log::info('api.admin.accounting_period_closed', [
            'user_id' => $request->user()->id,
            'period_id' => $period->id,
        ]);

        return $this->ok(['period' => $this->periodPayload($period->fresh())], "Period {$period->name} closed.");
    }

    public function reopenPeriod(Request $request, FiscalPeriod $period): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->authorizePlatformPeriod($period);

        if ($period->isOpen()) {
            return $this->error('This period is already open.');
        }

        // Allowed even when the parent year is closed: legacy had no reopen
        // for years, so refusing here would strand a period closed by mistake.
        $period->update([
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
        ]);

        ActivityLogger::log('accounting_period_reopened', "Platform fiscal period {$period->name} reopened.", [
            'period_id' => $period->id,
        ]);

        Log::info('api.admin.accounting_period_reopened', [
            'user_id' => $request->user()->id,
            'period_id' => $period->id,
        ]);

        return $this->ok(['period' => $this->periodPayload($period->fresh())], "Period {$period->name} reopened.");
    }

    /**
     * Close a fiscal year: the closing service zeroes income/expense accounts,
     * posts the net result to retained earnings and locks every period.
     */
    public function closeYear(Request $request, string $year): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if (! ctype_digit($year)) {
            abort(404);
        }

        $year = (int) $year;

        $fiscalYear = FiscalYear::query()
            ->whereNull('business_id')
            ->where('name', (string) $year)
            ->first();

        if (! $fiscalYear) {
            return $this->error("Fiscal year {$year} does not exist for the platform books.", 404);
        }

        try {
            $entry = $this->closing->closeYear(null, $year, $request->user()->id);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        ActivityLogger::log('accounting_year_closed', "Platform fiscal year {$year} closed.", [
            'year' => $year,
            'entry_id' => $entry?->id,
        ]);

        Log::info('api.admin.accounting_year_closed', [
            'user_id' => $request->user()->id,
            'year' => $year,
            'entry_id' => $entry?->id,
        ]);

        return $this->ok([
            'entry' => $entry ? [
                'id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date?->toDateString(),
                'memo' => $entry->memo,
            ] : null,
            'fiscal_year' => $this->yearPayload($fiscalYear->fresh()),
        ], $entry
            ? "Fiscal year {$year} closed — net result transferred to retained earnings."
            : "Fiscal year {$year} closed.");
    }

    /**
     * The shared journal query: platform scope only, newest first, filtered by
     * the validated q/status/from/to. One builder keeps the list, its totals
     * footer and the CSV export on the same rows.
     *
     * @param  array<string, mixed>  $filters
     */
    private function journalQuery(array $filters)
    {
        return JournalEntry::query()
            ->whereNull('business_id')
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $query->where(function ($inner) use ($term) {
                    $inner->where('entry_number', 'like', '%'.$term.'%')
                        ->orWhere('reference', 'like', '%'.$term.'%')
                        ->orWhere('memo', 'like', '%'.$term.'%');
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('entry_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('entry_date', '<=', $to));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateJournalFilters(Request $request): array
    {
        $rules = [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([JournalEntry::STATUS_DRAFT, JournalEntry::STATUS_POSTED, JournalEntry::STATUS_VOID])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];

        if ($request->filled('from')) {
            $rules['to'][] = 'after_or_equal:from';
        }

        return $request->validate($rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function profitAndLossStatement(string $from, string $to): array
    {
        $report = $this->reports->profitAndLoss(null, $from, $to);

        return [
            'income' => $this->statementRows($report['income']),
            'expenses' => $this->statementRows($report['expenses']),
            'total_income' => $report['total_income'],
            'total_expenses' => $report['total_expenses'],
            'net_profit' => $report['net_profit'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceSheetStatement(string $asOf): array
    {
        $report = $this->reports->balanceSheet(null, $asOf);

        return [
            'assets' => $this->statementRows($report['assets']),
            'liabilities' => $this->statementRows($report['liabilities']),
            'equity' => $this->statementRows($report['equity']),
            'total_assets' => $report['total_assets'],
            'total_liabilities' => $report['total_liabilities'],
            'total_equity' => $report['total_equity'],
            'net_profit' => $report['net_profit'],
            'as_of' => $report['as_of'],
            // The accounting equation, surfaced rather than left for the
            // reader to check by hand.
            'balanced' => $report['total_assets'] === $report['total_liabilities'] + $report['total_equity'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trialBalanceStatement(string $from, string $to): array
    {
        $report = $this->reports->trialBalance(null, $from, $to);

        return [
            'lines' => $this->statementRows($report['lines']),
            'total_debit' => $report['total_debit'],
            'total_credit' => $report['total_credit'],
            'balanced' => $report['total_debit'] === $report['total_credit'],
        ];
    }

    /**
     * Report rows carry the account model; serialize it so the JSON shape
     * never depends on model attribute visibility.
     *
     * @param  array<int, array{account: LedgerAccount, amount?: int, debit?: int, credit?: int}>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function statementRows(array $lines): array
    {
        return array_map(function (array $line) {
            /** @var LedgerAccount $account */
            $account = $line['account'];

            $row = [
                'code' => $account->code,
                'name' => $account->name,
                'subtype' => $account->subtype,
            ];

            if (array_key_exists('amount', $line)) {
                $row['amount'] = $line['amount'];
            }

            if (array_key_exists('debit', $line)) {
                $row['debit'] = $line['debit'];
            }

            if (array_key_exists('credit', $line)) {
                $row['credit'] = $line['credit'];
            }

            return $row;
        }, $lines);
    }

    /**
     * Debit-credit totals keyed by account id, one query for any set of
     * accounts (the legacy dashboard ran one per cash account).
     *
     * @param  array<int, int>  $accountIds
     * @return array<int, int>
     */
    private function balancesByAccount(array $accountIds): array
    {
        if (empty($accountIds)) {
            return [];
        }

        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereNull('journal_entries.business_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->whereIn('journal_lines.ledger_account_id', $accountIds)
            ->groupBy('journal_lines.ledger_account_id')
            ->selectRaw('journal_lines.ledger_account_id as account_id, SUM(journal_lines.debit_kobo) as debit, SUM(journal_lines.credit_kobo) as credit')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->account_id => (int) $row->debit - (int) $row->credit])
            ->all();
    }

    /**
     * A raw debit-credit total expressed on the account's normal side.
     */
    private function signedBalance(LedgerAccount $account, int $debitMinusCredit): int
    {
        return in_array($account->type, [LedgerAccount::TYPE_ASSET, LedgerAccount::TYPE_EXPENSE], true)
            ? $debitMinusCredit
            : -$debitMinusCredit;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountPayload(LedgerAccount $account): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'subtype' => $account->subtype,
            'is_active' => (bool) $account->is_active,
            'is_system' => (bool) $account->is_system,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryRow(JournalEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'entry_date' => $entry->entry_date?->toDateString(),
            'memo' => $entry->memo,
            'reference' => $entry->reference,
            'status' => $entry->status,
            'lines_count' => (int) ($entry->lines_count ?? $entry->lines()->count()),
            'total_debits' => (int) ($entry->total_debits ?? $entry->totalDebits()),
            'total_credits' => (int) ($entry->total_credits ?? $entry->totalCredits()),
        ];
    }

    /**
     * @param  Collection<string, LedgerMapping>  $mappings
     * @return array<int, array<string, mixed>>
     */
    private function mappingsPayload($mappings): array
    {
        $rows = [];

        foreach (LedgerAccountTemplate::mappings() as $key => $defaultCode) {
            /** @var LedgerMapping|null $mapping */
            $mapping = $mappings->get($key);

            $rows[] = [
                'key' => $key,
                'label' => self::MAPPING_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                'account_id' => $mapping?->ledger_account_id,
                'account_code' => $mapping?->account?->code,
                'account_name' => $mapping?->account?->name,
                'default_code' => $defaultCode,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function periodPayload(FiscalPeriod $period): array
    {
        return [
            'id' => $period->id,
            'name' => $period->name,
            'start_date' => $period->start_date?->toDateString(),
            'end_date' => $period->end_date?->toDateString(),
            'status' => $period->status,
            'closed_at' => $period->closed_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function yearPayload(FiscalYear $year): array
    {
        return [
            'id' => $year->id,
            'name' => $year->name,
            'start_date' => $year->start_date?->toDateString(),
            'end_date' => $year->end_date?->toDateString(),
            'status' => $year->status,
            'can_close' => $year->isOpen(),
        ];
    }

    private function authorizePlatformPeriod(FiscalPeriod $period): void
    {
        if ($period->business_id !== null) {
            abort(404);
        }
    }

    /**
     * Kobo to the decimal naira string the accountant exports use — integer
     * maths only, so 10099 kobo is always "100.99".
     */
    private function naira(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $kobo = abs($kobo);

        return $sign.intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }
}
