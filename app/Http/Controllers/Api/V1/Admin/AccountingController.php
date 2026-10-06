<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Accounting\AccountingReportRequest;
use App\Http\Requests\Admin\Accounting\ListAccountsRequest;
use App\Http\Requests\Admin\Accounting\ListJournalRequest;
use App\Http\Requests\Admin\Accounting\UpdateMappingsRequest;
use App\Http\Resources\Admin\Accounting\CashBalanceResource;
use App\Http\Resources\Admin\Accounting\FiscalPeriodResource;
use App\Http\Resources\Admin\Accounting\FiscalYearResource;
use App\Http\Resources\Admin\Accounting\JournalEntryDetailResource;
use App\Http\Resources\Admin\Accounting\JournalEntrySummaryResource;
use App\Http\Resources\Admin\Accounting\JournalExportResource;
use App\Http\Resources\Admin\Accounting\LedgerAccountResource;
use App\Http\Resources\Admin\Accounting\MappingResource;
use App\Http\Resources\Admin\Accounting\StatementResource;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Repositories\Admin\Accounting\PlatformLedgerRepository;
use App\Services\Accounting\LedgerClosingService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\LedgerSetupService;
use App\Services\ActivityLogger;
use App\Services\Admin\PlatformAccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
 * The CSV export's decimal formatting is `Naira::decimalFromKobo`, the named
 * home of this controller's former `naira()`.
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
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin\Accounting
 * FormRequests, queries/persists in `PlatformLedgerRepository`, the mapping
 * write's transaction in `PlatformAccountingService`, response shaping in the
 * Admin\Accounting resources; the platform-admin guard deliberately stays here
 * so its order relative to route binding and the platform-scope 404s is
 * unchanged.
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

    public function __construct(
        private readonly LedgerReportService $reports,
        private readonly LedgerSetupService $setup,
        private readonly LedgerClosingService $closing,
        private readonly PlatformLedgerRepository $ledger,
        private readonly PlatformAccountingService $accounting,
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

        $cashAccounts = $this->ledger->cashAccounts();
        $balances = $this->ledger->balancesByAccount($cashAccounts->pluck('id')->all());

        return $this->ok([
            'totals' => StatementResource::dashboardTotals($balanceSheet, $profitAndLoss),
            'cash_balances' => CashBalanceResource::rows($cashAccounts, $balances),
            'recent_entries' => JournalEntrySummaryResource::collection($this->ledger->recentEntries(10))->resolve(),
        ]);
    }

    /**
     * The platform chart of accounts, grouped by type with per-group counts
     * (legacy) and computed balances (improvement: one grouped query).
     */
    public function accounts(ListAccountsRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->setup->ensureForBusiness(null);

        $accounts = $this->ledger->accounts($request->validated()['q'] ?? null);
        $balances = $this->ledger->balancesByAccount($accounts->pluck('id')->all());

        return $this->ok([
            'groups' => LedgerAccountResource::groups($accounts, $balances),
            'total_accounts' => $accounts->count(),
        ]);
    }

    /**
     * The journal list: 20/page, newest first, searchable by entry number,
     * reference or memo (legacy's three-way search).
     */
    public function journal(ListJournalRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validated();

        $entries = $this->ledger->paginateJournal($filters, $filters['per_page'] ?? 20);

        return $this->ok(
            ['entries' => JournalEntrySummaryResource::collection($entries->getCollection())->resolve()],
            null,
            200,
            $this->paginationMeta($entries) + ['totals' => $this->ledger->journalTotals($filters)],
        );
    }

    /**
     * The journal CSV export (improvement on legacy — no export existed).
     * Same filters as the list; the rows are shaped by JournalExportResource.
     */
    public function journalExport(ListJournalRequest $request): StreamedResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validated();

        $entries = $this->ledger->journalForExport($filters);

        Log::info('api.admin.accounting_journal_exported', [
            'user_id' => $request->user()->id,
            'rows' => $entries->count(),
            'filters' => array_filter($filters),
        ]);

        return JournalExportResource::stream($entries);
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

        $entry = $this->ledger->loadEntryDetail($entry);

        return $this->ok([
            'entry' => JournalEntryDetailResource::make($entry)
                ->withReversedBy($this->ledger->reversedBy($entry))
                ->resolve(),
        ]);
    }

    /**
     * The three platform statements from one screen. Legacy defaults:
     * P&L and Trial Balance run year-to-date, the Balance Sheet as of today.
     */
    public function reports(AccountingReportRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $report = $request->reportKey();

        $from = $request->date('from')?->toDateString() ?? now()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();

        $statement = match ($report) {
            'balance_sheet' => StatementResource::balanceSheet($this->reports->balanceSheet(null, $asOf)),
            'trial_balance' => StatementResource::trialBalance($this->reports->trialBalance(null, $from, $to)),
            default => StatementResource::profitAndLoss($this->reports->profitAndLoss(null, $from, $to)),
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
        $accounts = $this->ledger->allAccounts();
        $mappings = $this->ledger->mappings();
        $periods = $this->ledger->recentPeriods(24);
        $years = $this->ledger->fiscalYears(6);

        return $this->ok([
            'accounts' => LedgerAccountResource::collection($accounts)->resolve(),
            'mappings' => MappingResource::rows($mappings),
            'periods' => FiscalPeriodResource::collection($periods)->resolve(),
            'fiscal_years' => FiscalYearResource::collection($years)->resolve(),
        ]);
    }

    /**
     * Save the auto-posting mappings. Only platform accounts are acceptable
     * targets — an id from any business's chart is refused, the same way a
     * platform admin has no business id to confuse it with.
     */
    public function updateMappings(UpdateMappingsRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $mappings = $request->validated()['mappings'];

        $this->accounting->saveMappings($mappings, $request->user()->id);

        return $this->ok(
            ['mappings' => MappingResource::rows($this->ledger->mappings())],
            'Platform account mappings updated.'
        );
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

        $this->accounting->closePeriod($period, $request->user()->id);

        return $this->ok(['period' => FiscalPeriodResource::make($period->fresh())->resolve()], "Period {$period->name} closed.");
    }

    public function reopenPeriod(Request $request, FiscalPeriod $period): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->authorizePlatformPeriod($period);

        if ($period->isOpen()) {
            return $this->error('This period is already open.');
        }

        $this->accounting->reopenPeriod($period, $request->user()->id);

        return $this->ok(['period' => FiscalPeriodResource::make($period->fresh())->resolve()], "Period {$period->name} reopened.");
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

        $fiscalYear = $this->ledger->findFiscalYear((string) $year);

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
            'entry' => JournalEntrySummaryResource::brief($entry),
            'fiscal_year' => FiscalYearResource::make($fiscalYear->fresh())->resolve(),
        ], $entry
            ? "Fiscal year {$year} closed — net result transferred to retained earnings."
            : "Fiscal year {$year} closed.");
    }

    /**
     * Platform scope for a bound row: a tenant's period 404s, never 403 —
     * the console must not confirm that a guessed id exists elsewhere.
     */
    private function authorizePlatformPeriod(FiscalPeriod $period): void
    {
        if ($period->business_id !== null) {
            abort(404);
        }
    }
}
