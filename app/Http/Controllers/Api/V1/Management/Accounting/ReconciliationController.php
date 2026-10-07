<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Accounting\CompleteBankReconciliationRequest;
use App\Http\Requests\Management\Accounting\MatchBankStatementLineRequest;
use App\Http\Requests\Management\Accounting\StoreBankStatementImportRequest;
use App\Http\Resources\Management\Accounting\BankReconciliationResource;
use App\Http\Resources\Management\Accounting\BankStatementImportResource;
use App\Http\Resources\Management\Accounting\BankStatementLineResource;
use App\Http\Resources\Management\Accounting\JournalLineResource;
use App\Http\Resources\Management\Accounting\LedgerAccountOptionResource;
use App\Http\Resources\Management\Accounting\StoreBankOptionResource;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\StoreBank;
use App\Repositories\Management\Accounting\BankReconciliationRepository;
use App\Services\Access\TenantGuard;
use App\Services\Accounting\BankReconciliationService;
use App\Services\Accounting\BankStatementCsvParser;
use App\Services\Accounting\LedgerSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * WS-37 — Bank reconciliation.
 *
 * Legacy capability rebuilt end to end: the import list with its completed
 * reconciliations panel, the lenient CSV/TXT statement parser (comma/₦
 * tolerant, signed amounts), the reconcile screen with per-line
 * match/unmatch/ignore, auto-match (±7 days, exact amount, one-to-one), and
 * the completion snapshot (statement closing vs cleared ledger balance).
 * This is the module the mgmt-finance audit calls rule 4.1 and the
 * mgmt-accounting audit calls 7.1/7.2 — one controller, both audits.
 *
 * Improvements on legacy, each also noted where it lives in the code:
 *  - statement dates are parsed day-first for dd/mm/yyyy input (legacy's
 *    Carbon::parse read "03/12/2025" as March 12, silently mis-dating every
 *    Nigerian statement written day-first);
 *  - debit/credit statement columns are understood, not just a single
 *    signed amount column;
 *  - a journal line can only be claimed once per business and only by a
 *    statement line on its own ledger account (legacy's manual match
 *    accepted a line from any account, and let two statement lines point at
 *    the same journal line);
 *  - a reconciled import is frozen — line actions and re-completion are
 *    refused instead of silently editing a finished snapshot.
 *
 * Money on the wire is integer kobo (`amount_kobo`, `*_balance_kobo`).
 *
 * Extracted for size: parsing to BankStatementCsvParser, workflows to
 * BankReconciliationService, queries/persists to BankReconciliationRepository,
 * payloads to the Accounting resources. This class keeps the HTTP shape.
 */
class ReconciliationController extends ApiController
{
    use ResolvesManagementContext;

    /** Account subtypes legacy let a business reconcile against. */
    private const BANK_SUBTYPES = ['bank', 'cash', 'gateway_clearing'];

    /** How many unmatched ledger lines the match picker is offered. */
    private const CANDIDATE_LIMIT = 100;

    public function __construct(
        private readonly LedgerSetupService $setup,
        private readonly BankStatementCsvParser $parser,
        private readonly BankReconciliationRepository $repository,
        private readonly BankReconciliationService $service,
        private readonly TenantGuard $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = (int) $this->user($request)->business_id;

        // Legacy only bootstrapped the books on the dashboard/settings pages,
        // so a business that opened reconciliation first found an empty
        // account picker. Make the screen self-sufficient — the bootstrap is
        // idempotent, and the existence check keeps it off the hot path.
        if (! $this->repository->hasBankAccounts($businessId, self::BANK_SUBTYPES)) {
            $this->setup->ensureForBusiness($businessId);
        }

        $imports = $this->repository->paginateImports($businessId)->withQueryString();
        $reconciliations = $this->repository->recentReconciliations($businessId);

        return $this->ok([
            'imports' => $imports->getCollection()
                ->map(fn (BankStatementImport $import) => BankStatementImportResource::make($import)->resolve())
                ->all(),
            'reconciliations' => $reconciliations
                ->map(fn (BankReconciliation $row) => BankReconciliationResource::make($row)->resolve())
                ->all(),
            // Everything the import modal needs, so the form loads in one trip.
            'bank_accounts' => $this->repository->bankAccountOptions($businessId, self::BANK_SUBTYPES)
                ->map(fn (LedgerAccount $account) => LedgerAccountOptionResource::make($account)->resolve())
                ->all(),
            'store_banks' => $this->repository->storeBankOptions($businessId)
                ->map(fn (StoreBank $bank) => StoreBankOptionResource::make($bank)->resolve())
                ->all(),
        ], null, 200, $this->paginationMeta($imports));
    }

    public function store(StoreBankStatementImportRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = (int) $user->business_id;

        $validated = $request->validated();

        $account = $this->repository->findAccountOrFail($businessId, (int) $validated['ledger_account_id']);

        if (! in_array($account->subtype, self::BANK_SUBTYPES, true)) {
            throw ValidationException::withMessages([
                'ledger_account_id' => 'Choose a bank, cash or gateway clearing account to reconcile.',
            ]);
        }

        $rows = $this->parser->parse($request->file('file')->getRealPath());

        if (empty($rows)) {
            throw ValidationException::withMessages([
                'file' => 'No valid rows found. Expected columns: date, description, reference, amount (or debit/credit).',
            ]);
        }

        $path = $request->file('file')->store('bank-statements', 'public');

        $import = $this->service->importStatement([
            'business_id' => $businessId,
            'store_bank_id' => $validated['store_bank_id'] ?? null,
            'ledger_account_id' => $account->id,
            'file_path' => $path,
            'statement_date' => $validated['statement_date'] ?? null,
            'opening_balance_kobo' => $validated['opening_balance_kobo'] ?? null,
            'closing_balance_kobo' => $validated['closing_balance_kobo'] ?? null,
            'status' => 'imported',
            'imported_by' => $user->id,
        ], $rows);

        Log::info('api.management.bank_statement_imported', [
            'user_id' => $user->id,
            'import_id' => $import->id,
            'lines' => count($rows),
        ]);

        $import = $this->repository->loadImportSummary($import);

        return $this->ok([
            'import' => BankStatementImportResource::make($import)->resolve(),
        ], count($rows).' statement line(s) imported.', 201);
    }

    public function show(Request $request, BankStatementImport $import): JsonResponse
    {
        $this->authorizeImport($request, $import);

        $import = $this->repository->loadImportDetail($import);

        $asOf = $import->statement_date?->toDateString();
        $ledgerClosing = $this->repository->ledgerBalanceKobo($import->business_id, (int) $import->ledger_account_id, $asOf);
        $statementClosing = $import->closing_balance_kobo !== null ? (int) $import->closing_balance_kobo : null;

        $lines = $import->lines;
        $claimed = $this->repository->claimedJournalLineIds((int) $import->business_id);

        $candidates = $this->repository->candidateLines(
            (int) $import->business_id,
            (int) $import->ledger_account_id,
            $claimed,
            self::CANDIDATE_LIMIT,
        )->map(fn (JournalLine $line) => JournalLineResource::make($line)->resolve())->all();

        return $this->ok([
            'import' => BankStatementImportResource::make($import)->resolve(),
            'summary' => [
                'statement_closing_kobo' => $statementClosing,
                'ledger_closing_kobo' => $ledgerClosing,
                'difference_kobo' => $statementClosing === null ? null : $statementClosing - $ledgerClosing,
                'lines_count' => $lines->count(),
                'unmatched_count' => $lines->where('status', 'unmatched')->count(),
                'matched_count' => $lines->where('status', 'matched')->count(),
                'ignored_count' => $lines->where('status', 'ignored')->count(),
                'matched_total_kobo' => (int) $lines->where('status', 'matched')->sum('amount_kobo'),
            ],
            'lines' => $lines->map(fn (BankStatementLine $line) => BankStatementLineResource::make($line)->resolve())->values()->all(),
            'candidates' => $candidates,
        ]);
    }

    public function autoMatch(Request $request, BankStatementImport $import): JsonResponse
    {
        $this->authorizeImport($request, $import);
        $this->refuseWhenReconciled($import);

        $matched = $this->service->autoMatch($import);

        Log::info('api.management.bank_statement_auto_matched', [
            'user_id' => $this->user($request)->id,
            'import_id' => $import->id,
            'matched' => $matched,
        ]);

        return $this->ok(['matched' => $matched], "{$matched} line(s) auto-matched.");
    }

    public function match(MatchBankStatementLineRequest $request, BankStatementLine $line): JsonResponse
    {
        $this->authorizeLine($request, $line);
        $this->refuseWhenReconciled($line->import);

        $validated = $request->validated();

        $journalLine = $this->repository->findPostedJournalLine((int) $line->business_id, (int) $validated['journal_line_id']);

        if (! $journalLine) {
            return $this->error('That ledger entry does not belong to this business.');
        }

        // Legacy matched across accounts; a reconciliation line can only
        // legitimately clear against its own account's ledger lines.
        if ((int) $journalLine->ledger_account_id !== (int) $line->import->ledger_account_id) {
            return $this->error('That ledger entry is on a different account from this statement.');
        }

        $claimedByAnother = $this->repository->journalLineClaimedByAnother(
            (int) $line->business_id,
            (int) $journalLine->id,
            (int) $line->getKey(),
        );

        if ($claimedByAnother) {
            return $this->error('That ledger entry is already matched to another statement line.');
        }

        $this->repository->markLineMatched($line, (int) $journalLine->id);

        return $this->ok(['line' => BankStatementLineResource::make($line->fresh())->resolve()], 'Line matched.');
    }

    public function unmatch(Request $request, BankStatementLine $line): JsonResponse
    {
        $this->authorizeLine($request, $line);
        $this->refuseWhenReconciled($line->import);

        $this->repository->markLineUnmatched($line);

        return $this->ok(['line' => BankStatementLineResource::make($line->fresh())->resolve()], 'Match removed.');
    }

    public function ignore(Request $request, BankStatementLine $line): JsonResponse
    {
        $this->authorizeLine($request, $line);
        $this->refuseWhenReconciled($line->import);

        $this->repository->markLineIgnored($line);

        return $this->ok(['line' => BankStatementLineResource::make($line->fresh())->resolve()], 'Line ignored.');
    }

    public function complete(CompleteBankReconciliationRequest $request, BankStatementImport $import): JsonResponse
    {
        $this->authorizeImport($request, $import);

        if ($import->status === 'reconciled') {
            return $this->error('This statement has already been reconciled.');
        }

        $validated = $request->validated();

        $ledgerClosing = $this->repository->ledgerBalanceKobo(
            $import->business_id,
            (int) $import->ledger_account_id,
            $validated['end_date'],
        );

        // The snapshot columns are unsigned; a negative cleared balance would
        // be a raw database error in legacy. Explain it instead.
        if ($ledgerClosing < 0) {
            return $this->error('The ledger balance for this account is negative as of the period end; reconciliation snapshots only record positive cleared balances.');
        }

        $statementClosing = (int) $validated['statement_closing_balance_kobo'];

        $reconciliation = $this->service->complete(
            $import,
            $validated,
            $statementClosing,
            $ledgerClosing,
            (int) $this->user($request)->id,
        );

        Log::info('api.management.bank_statement_reconciled', [
            'user_id' => $this->user($request)->id,
            'import_id' => $import->id,
            'reconciliation_id' => $reconciliation->id,
            'difference_kobo' => $reconciliation->difference_kobo,
        ]);

        return $this->ok([
            'reconciliation' => BankReconciliationResource::make($this->repository->loadReconciliationRelations($reconciliation))->resolve(),
            'import' => BankStatementImportResource::make($this->repository->refreshImportWithLineCount($import))->resolve(),
        ], 'Reconciliation saved.', 201);
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    private function refuseWhenReconciled(?BankStatementImport $import): void
    {
        if ($import && $import->status === 'reconciled') {
            abort(422, 'This statement has already been reconciled.');
        }
    }

    private function authorizeImport(Request $request, BankStatementImport $import): void
    {
        $this->tenant->authorizeBusiness($import, $this->user($request), 'You do not have access to this statement import.');
    }

    private function authorizeLine(Request $request, BankStatementLine $line): void
    {
        $this->tenant->authorizeBusiness($line, $this->user($request), 'You do not have access to this statement line.');
    }
}
