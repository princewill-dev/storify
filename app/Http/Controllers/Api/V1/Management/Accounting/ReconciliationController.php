<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\StoreBank;
use App\Services\Accounting\LedgerSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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
 */
class ReconciliationController extends ApiController
{
    use ResolvesManagementContext;

    /** Account subtypes legacy let a business reconcile against. */
    private const BANK_SUBTYPES = ['bank', 'cash', 'gateway_clearing'];

    /** How many unmatched ledger lines the match picker is offered. */
    private const CANDIDATE_LIMIT = 100;

    public function __construct(private readonly LedgerSetupService $setup) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        // Legacy only bootstrapped the books on the dashboard/settings pages,
        // so a business that opened reconciliation first found an empty
        // account picker. Make the screen self-sufficient — the bootstrap is
        // idempotent, and the existence check keeps it off the hot path.
        if (! LedgerAccount::query()->where('business_id', $businessId)->whereIn('subtype', self::BANK_SUBTYPES)->exists()) {
            $this->setup->ensureForBusiness($businessId);
        }

        $imports = BankStatementImport::query()
            ->where('business_id', $businessId)
            ->with(['storeBank', 'ledgerAccount'])
            ->withCount([
                'lines',
                'lines as unmatched_count' => fn ($q) => $q->where('status', 'unmatched'),
                'lines as matched_count' => fn ($q) => $q->where('status', 'matched'),
            ])
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $reconciliations = BankReconciliation::query()
            ->where('business_id', $businessId)
            ->with(['storeBank', 'ledgerAccount'])
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return $this->ok([
            'imports' => $imports->getCollection()->map(fn (BankStatementImport $import) => $this->importPayload($import))->all(),
            'reconciliations' => $reconciliations->map(fn (BankReconciliation $row) => $this->reconciliationPayload($row))->all(),
            // Everything the import modal needs, so the form loads in one trip.
            'bank_accounts' => $this->bankAccountOptions($businessId),
            'store_banks' => $this->storeBankOptions($businessId),
        ], null, 200, $this->paginationMeta($imports));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = $user->business_id;

        $validated = $request->validate([
            'ledger_account_id' => ['required', 'integer', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)],
            'store_bank_id' => ['nullable', 'integer', Rule::exists('store_banks', 'id')->where('business_id', $businessId)],
            'statement_date' => ['nullable', 'date'],
            'opening_balance_kobo' => ['nullable', 'integer', 'min:0'],
            // The snapshot columns are unsigned kobo, so an overdrawn closing
            // balance is refused up front rather than hitting the database.
            'closing_balance_kobo' => ['nullable', 'integer', 'min:0'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $account = LedgerAccount::query()
            ->where('business_id', $businessId)
            ->findOrFail($validated['ledger_account_id']);

        if (! in_array($account->subtype, self::BANK_SUBTYPES, true)) {
            throw ValidationException::withMessages([
                'ledger_account_id' => 'Choose a bank, cash or gateway clearing account to reconcile.',
            ]);
        }

        $rows = $this->parseStatement($request->file('file')->getRealPath());

        if (empty($rows)) {
            throw ValidationException::withMessages([
                'file' => 'No valid rows found. Expected columns: date, description, reference, amount (or debit/credit).',
            ]);
        }

        $path = $request->file('file')->store('bank-statements', 'public');

        $import = DB::transaction(function () use ($validated, $businessId, $user, $account, $path, $rows) {
            $import = BankStatementImport::create([
                'business_id' => $businessId,
                'store_bank_id' => $validated['store_bank_id'] ?? null,
                'ledger_account_id' => $account->id,
                'file_path' => $path,
                'statement_date' => $validated['statement_date'] ?? null,
                'opening_balance_kobo' => $validated['opening_balance_kobo'] ?? null,
                'closing_balance_kobo' => $validated['closing_balance_kobo'] ?? null,
                'status' => 'imported',
                'imported_by' => $user->id,
            ]);

            foreach ($rows as $row) {
                BankStatementLine::create([
                    'bank_statement_import_id' => $import->id,
                    'business_id' => $businessId,
                    'transaction_date' => $row['date'],
                    'description' => $row['description'],
                    'reference' => $row['reference'],
                    'amount_kobo' => $row['amount_kobo'],
                    'status' => 'unmatched',
                ]);
            }

            return $import;
        });

        Log::info('api.management.bank_statement_imported', [
            'user_id' => $user->id,
            'import_id' => $import->id,
            'lines' => count($rows),
        ]);

        $import->load(['storeBank', 'ledgerAccount'])->loadCount('lines');

        return $this->ok([
            'import' => $this->importPayload($import),
        ], count($rows).' statement line(s) imported.', 201);
    }

    public function show(Request $request, BankStatementImport $import): JsonResponse
    {
        $this->authorizeImport($request, $import);

        $import->load([
            'storeBank',
            'ledgerAccount',
            'importedBy',
            'lines' => fn ($q) => $q->orderBy('transaction_date')->orderBy('id'),
            'lines.matchedJournalLine.entry',
        ]);

        $asOf = $import->statement_date?->toDateString();
        $ledgerClosing = $this->ledgerBalanceKobo($import->business_id, (int) $import->ledger_account_id, $asOf);
        $statementClosing = $import->closing_balance_kobo !== null ? (int) $import->closing_balance_kobo : null;

        $lines = $import->lines;
        $claimed = $this->claimedJournalLineIds($import->business_id);

        $candidates = $this->candidateQuery($import->business_id, (int) $import->ledger_account_id)
            ->whereNotIn('journal_lines.id', $claimed)
            ->orderByDesc('journal_entries.entry_date')
            ->orderByDesc('journal_lines.id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get()
            ->map(fn ($line) => $this->journalLinePayload($line))
            ->all();

        return $this->ok([
            'import' => $this->importPayload($import),
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
            'lines' => $lines->map(fn (BankStatementLine $line) => $this->linePayload($line))->values()->all(),
            'candidates' => $candidates,
        ]);
    }

    public function autoMatch(Request $request, BankStatementImport $import): JsonResponse
    {
        $this->authorizeImport($request, $import);
        $this->refuseWhenReconciled($import);

        // Every journal line already claimed anywhere in this business's
        // books, so auto-match never double-books the same ledger line.
        $usedJournalLineIds = $this->claimedJournalLineIds($import->business_id);

        $matched = 0;

        DB::transaction(function () use ($import, &$matched, &$usedJournalLineIds) {
            $lines = $import->lines()->where('status', 'unmatched')->orderBy('transaction_date')->orderBy('id')->get();

            foreach ($lines as $line) {
                $date = Carbon::parse($line->transaction_date);
                $amountKobo = (int) $line->amount_kobo;

                $candidate = $this->candidateQuery($import->business_id, (int) $import->ledger_account_id)
                    ->whereDate('journal_entries.entry_date', '>=', $date->copy()->subDays(7)->toDateString())
                    ->whereDate('journal_entries.entry_date', '<=', $date->copy()->addDays(7)->toDateString())
                    ->when(
                        $amountKobo >= 0,
                        fn ($q) => $q->where('journal_lines.debit_kobo', $amountKobo),
                        fn ($q) => $q->where('journal_lines.credit_kobo', abs($amountKobo)),
                    )
                    ->whereNotIn('journal_lines.id', $usedJournalLineIds)
                    ->orderByRaw('ABS(DATEDIFF(journal_entries.entry_date, ?))', [$date->toDateString()])
                    ->orderBy('journal_lines.id')
                    ->select('journal_lines.id as journal_line_id')
                    ->first();

                if (! $candidate) {
                    continue;
                }

                $usedJournalLineIds[] = $candidate->journal_line_id;

                $line->update([
                    'status' => 'matched',
                    'matched_journal_line_id' => $candidate->journal_line_id,
                ]);

                $matched++;
            }
        });

        Log::info('api.management.bank_statement_auto_matched', [
            'user_id' => $this->user($request)->id,
            'import_id' => $import->id,
            'matched' => $matched,
        ]);

        return $this->ok(['matched' => $matched], "{$matched} line(s) auto-matched.");
    }

    public function match(Request $request, BankStatementLine $line): JsonResponse
    {
        $this->authorizeLine($request, $line);
        $this->refuseWhenReconciled($line->import);

        $validated = $request->validate([
            'journal_line_id' => ['required', 'integer'],
        ]);

        $journalLine = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $line->business_id)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.id', $validated['journal_line_id'])
            ->select('journal_lines.id', 'journal_lines.ledger_account_id')
            ->first();

        if (! $journalLine) {
            return $this->error('That ledger entry does not belong to this business.');
        }

        // Legacy matched across accounts; a reconciliation line can only
        // legitimately clear against its own account's ledger lines.
        if ((int) $journalLine->ledger_account_id !== (int) $line->import->ledger_account_id) {
            return $this->error('That ledger entry is on a different account from this statement.');
        }

        $claimedByAnother = BankStatementLine::query()
            ->where('business_id', $line->business_id)
            ->where('matched_journal_line_id', $journalLine->id)
            ->whereKeyNot($line->getKey())
            ->exists();

        if ($claimedByAnother) {
            return $this->error('That ledger entry is already matched to another statement line.');
        }

        $line->update([
            'status' => 'matched',
            'matched_journal_line_id' => $journalLine->id,
        ]);

        return $this->ok(['line' => $this->linePayload($line->fresh())], 'Line matched.');
    }

    public function unmatch(Request $request, BankStatementLine $line): JsonResponse
    {
        $this->authorizeLine($request, $line);
        $this->refuseWhenReconciled($line->import);

        $line->update([
            'status' => 'unmatched',
            'matched_journal_line_id' => null,
        ]);

        return $this->ok(['line' => $this->linePayload($line->fresh())], 'Match removed.');
    }

    public function ignore(Request $request, BankStatementLine $line): JsonResponse
    {
        $this->authorizeLine($request, $line);
        $this->refuseWhenReconciled($line->import);

        $line->update(['status' => 'ignored', 'matched_journal_line_id' => null]);

        return $this->ok(['line' => $this->linePayload($line->fresh())], 'Line ignored.');
    }

    public function complete(Request $request, BankStatementImport $import): JsonResponse
    {
        $this->authorizeImport($request, $import);

        if ($import->status === 'reconciled') {
            return $this->error('This statement has already been reconciled.');
        }

        $validated = $request->validate([
            'statement_closing_balance_kobo' => ['required', 'integer', 'min:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $ledgerClosing = $this->ledgerBalanceKobo(
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

        $reconciliation = DB::transaction(function () use ($import, $validated, $statementClosing, $ledgerClosing, $request) {
            $reconciliation = BankReconciliation::create([
                'business_id' => $import->business_id,
                'store_bank_id' => $import->store_bank_id,
                'ledger_account_id' => $import->ledger_account_id,
                'bank_statement_import_id' => $import->id,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'statement_closing_balance_kobo' => $statementClosing,
                'cleared_balance_kobo' => $ledgerClosing,
                'difference_kobo' => $statementClosing - $ledgerClosing,
                'status' => 'completed',
                'completed_by' => $this->user($request)->id,
                'completed_at' => now(),
            ]);

            $import->update(['status' => 'reconciled']);

            return $reconciliation;
        });

        Log::info('api.management.bank_statement_reconciled', [
            'user_id' => $this->user($request)->id,
            'import_id' => $import->id,
            'reconciliation_id' => $reconciliation->id,
            'difference_kobo' => $reconciliation->difference_kobo,
        ]);

        return $this->ok([
            'reconciliation' => $this->reconciliationPayload($reconciliation->load(['storeBank', 'ledgerAccount'])),
            'import' => $this->importPayload($import->fresh()->loadCount('lines')),
        ], 'Reconciliation saved.', 201);
    }

    // -----------------------------------------------------------------
    // Parsing
    // -----------------------------------------------------------------

    /**
     * Parse a CSV/TXT statement leniently.
     *
     * Accepts comma/₦/space-grouped amounts, signed or parenthesised values,
     * a single amount column or separate debit/credit columns, and named or
     * positional headers. Dates are day-first (`31/12/2025`, `03/12/2025`
     * both read as December) because that is how the statements this module
     * ingests are written; legacy parsed them US-style and mis-dated them.
     *
     * @return array<int, array{date: string, description: ?string, reference: ?string, amount_kobo: int}>
     */
    private function parseStatement(string $path): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            return [];
        }

        $headers = null;
        $positional = false;
        $rows = [];

        // PHP 8.4 wants the escape argument spelled out; "\\" keeps the
        // pre-8.4 CSV behaviour.
        while (($data = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            if (count(array_filter($data, fn ($value) => $value !== null && trim((string) $value) !== '')) === 0) {
                continue;
            }

            if ($headers === null) {
                // Excel and several bank exports lead with a UTF-8 BOM, which
                // would otherwise turn "date" into "\u{FEFF}date".
                $data = array_map(fn ($value) => is_string($value) ? ltrim($value, "\xEF\xBB\xBF") : $value, $data);

                $normalised = array_map(fn ($value) => strtolower(trim((string) $value)), $data);

                // A first row that names any known column is a header row.
                if (array_intersect($normalised, self::ALL_ALIASES)) {
                    $headers = $normalised;

                    continue;
                }

                $headers = ['date', 'description', 'reference', 'amount'];
                $positional = true;
            }

            $columns = $this->mapColumns($headers, $data, $positional);

            $date = $this->parseDate($columns['date'] ?? null);
            $amount = $this->resolveAmount(
                $columns['amount'] ?? null,
                $columns['debit'] ?? null,
                $columns['credit'] ?? null,
            );

            if ($date === null || $amount === null) {
                continue;
            }

            $rows[] = [
                'date' => $date,
                'description' => $this->clean($columns['description'] ?? null),
                // `reference` is varchar(100); clamp it there so one long bank
                // narration cannot abort the whole import on insert.
                'reference' => $this->clean($columns['reference'] ?? null, 100),
                'amount_kobo' => $amount,
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Map one raw row onto logical columns, by header name when available
     * and positionally otherwise (legacy order: date, description, reference,
     * amount).
     *
     * @param  array<int, string>  $headers
     * @param  array<int, ?string>  $data
     * @return array<string, ?string>
     */
    private function mapColumns(array $headers, array $data, bool $positional = false): array
    {
        $columns = [];

        foreach ($headers as $index => $header) {
            $value = $data[$index] ?? null;

            foreach (self::COLUMN_ALIASES as $logical => $aliases) {
                if (in_array($header, $aliases, true) && ! array_key_exists($logical, $columns)) {
                    $columns[$logical] = $value;
                }
            }
        }

        if ($positional) {
            $columns = [
                'date' => $data[0] ?? null,
                'description' => $data[1] ?? null,
                'reference' => count($data) >= 4 ? $data[2] : null,
                // Headerless files of three columns put the amount last;
                // four-plus column files keep it in the legacy third slot.
                'amount' => count($data) >= 4
                    ? ($data[3] ?? $data[count($data) - 1] ?? null)
                    : ($data[count($data) - 1] ?? null),
            ];
        }

        if (empty($columns)) {
            $columns = [
                'date' => $data[0] ?? null,
                'description' => $data[1] ?? null,
                'reference' => $data[2] ?? null,
                'amount' => $data[count($data) - 1] ?? null,
            ];
        }

        return $columns;
    }

    /**
     * Resolve a signed kobo amount from an amount column, or from separate
     * debit/credit columns (legacy only understood the single column).
     */
    private function resolveAmount(?string $amount, ?string $debit, ?string $credit): ?int
    {
        if ($amount !== null && trim($amount) !== '') {
            return $this->parseKobo($amount);
        }

        $debitKobo = $debit !== null && trim($debit) !== '' ? $this->parseKobo($debit) : null;
        $creditKobo = $credit !== null && trim($credit) !== '' ? $this->parseKobo($credit) : null;

        if ($debitKobo === null && $creditKobo === null) {
            return null;
        }

        // The bank credits the business (money in, positive in the signed
        // amount_kobo convention); it debits it on the way out.
        return (int) abs($creditKobo ?? 0) - (int) abs($debitKobo ?? 0);
    }

    /**
     * Convert "₦1,234.56", "(250.00)" or "-12.5" to signed integer kobo.
     *
     * String maths only — money never passes through a float here.
     */
    private function parseKobo(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        $negative = false;

        if (preg_match('/^\((.*)\)$/', $value, $matches) === 1) {
            $negative = true;
            $value = $matches[1];
        }

        if (str_starts_with($value, '-') || str_starts_with($value, '−')) {
            $negative = true;
            $value = mb_substr($value, 1);
        }

        // The second whitespace entry is a non-breaking space — Excel and
        // several bank exports group thousands with it.
        $value = str_replace(['₦', 'NGN', 'ngn', ',', ' ', "\u{A0}"], '', $value);

        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');

        $kobo = ((int) $whole) * 100 + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $kobo++;
        }

        return $negative ? -$kobo : $kobo;
    }

    /**
     * Parse a statement date. Day-first for ambiguous d/m/Y input.
     *
     * A value that fits a known shape but not the calendar (31/02/2026) is
     * refused rather than rolled over into March — a silent month shift is
     * how a reconciliation stops balancing for no visible reason.
     */
    private function parseDate(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $value = trim($raw);

        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'm/d/Y', 'm-d-Y'] as $format) {
            if (! Carbon::hasFormat($value, $format)) {
                continue;
            }

            try {
                $parsed = Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                return null;
            }

            return $parsed && $parsed->format($format) === $value ? $parsed->toDateString() : null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function clean(?string $value, int $max = 255): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    // -----------------------------------------------------------------
    // Queries and guards
    // -----------------------------------------------------------------

    /**
     * Posted journal lines on one account, joined to their entries.
     */
    private function candidateQuery(int $businessId, int $ledgerAccountId)
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.ledger_account_id', $ledgerAccountId)
            ->select([
                'journal_lines.id',
                'journal_lines.description',
                'journal_lines.debit_kobo',
                'journal_lines.credit_kobo',
                'journal_entries.entry_number',
                'journal_entries.entry_date',
            ]);
    }

    /**
     * Journal line ids already claimed by any statement line in the business.
     *
     * @return array<int, int>
     */
    private function claimedJournalLineIds(int $businessId): array
    {
        return BankStatementLine::query()
            ->where('business_id', $businessId)
            ->whereNotNull('matched_journal_line_id')
            ->pluck('matched_journal_line_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Ledger balance for one account (debits - credits on posted entries).
     */
    private function ledgerBalanceKobo(?int $businessId, int $ledgerAccountId, ?string $asOf = null): int
    {
        return (int) JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.ledger_account_id', $ledgerAccountId)
            ->when($asOf, fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $asOf))
            ->selectRaw('COALESCE(SUM(debit_kobo), 0) - COALESCE(SUM(credit_kobo), 0) as balance')
            ->value('balance');
    }

    private function refuseWhenReconciled(?BankStatementImport $import): void
    {
        if ($import && $import->status === 'reconciled') {
            abort(422, 'This statement has already been reconciled.');
        }
    }

    private function authorizeImport(Request $request, BankStatementImport $import): void
    {
        if ((int) $import->business_id !== (int) $this->user($request)->business_id) {
            abort(403, 'You do not have access to this statement import.');
        }
    }

    private function authorizeLine(Request $request, BankStatementLine $line): void
    {
        if ((int) $line->business_id !== (int) $this->user($request)->business_id) {
            abort(403, 'You do not have access to this statement line.');
        }
    }

    // -----------------------------------------------------------------
    // Payloads
    // -----------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function bankAccountOptions(int $businessId): array
    {
        return LedgerAccount::query()
            ->where('business_id', $businessId)
            ->active()
            ->whereIn('subtype', self::BANK_SUBTYPES)
            ->orderBy('code')
            ->get()
            ->map(fn (LedgerAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'subtype' => $account->subtype,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storeBankOptions(int $businessId): array
    {
        return StoreBank::query()
            ->where('business_id', $businessId)
            ->orderBy('bank_name')
            ->get()
            ->map(fn (StoreBank $bank) => [
                'id' => $bank->id,
                'bank_name' => $bank->bank_name,
                'masked_account_number' => $bank->masked_account_number,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function importPayload(BankStatementImport $import): array
    {
        return [
            'id' => $import->id,
            'statement_date' => $import->statement_date?->toDateString(),
            'status' => $import->status,
            'file_name' => $import->file_path ? basename($import->file_path) : null,
            'opening_balance_kobo' => $import->opening_balance_kobo !== null ? (int) $import->opening_balance_kobo : null,
            'closing_balance_kobo' => $import->closing_balance_kobo !== null ? (int) $import->closing_balance_kobo : null,
            'lines_count' => (int) ($import->lines_count ?? $import->lines()->count()),
            'unmatched_count' => (int) ($import->unmatched_count ?? $import->lines()->where('status', 'unmatched')->count()),
            'matched_count' => (int) ($import->matched_count ?? $import->lines()->where('status', 'matched')->count()),
            'ledger_account' => $import->ledgerAccount ? [
                'id' => $import->ledgerAccount->id,
                'code' => $import->ledgerAccount->code,
                'name' => $import->ledgerAccount->name,
                'subtype' => $import->ledgerAccount->subtype,
            ] : null,
            'store_bank' => $import->storeBank ? [
                'id' => $import->storeBank->id,
                'bank_name' => $import->storeBank->bank_name,
                'masked_account_number' => $import->storeBank->masked_account_number,
            ] : null,
            'imported_by' => $import->relationLoaded('importedBy') ? $import->importedBy?->name : null,
            'created_at' => $import->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linePayload(BankStatementLine $line): array
    {
        $matched = $line->relationLoaded('matchedJournalLine') ? $line->matchedJournalLine : null;

        return [
            'id' => $line->id,
            'transaction_date' => $line->transaction_date?->toDateString(),
            'description' => $line->description,
            'reference' => $line->reference,
            'amount_kobo' => (int) $line->amount_kobo,
            'status' => $line->status,
            'matched_journal_line' => $matched ? [
                'id' => $matched->id,
                'entry_number' => $matched->entry?->entry_number,
                'entry_date' => $matched->entry?->entry_date?->toDateString(),
                'description' => $matched->description,
                'debit_kobo' => (int) $matched->debit_kobo,
                'credit_kobo' => (int) $matched->credit_kobo,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function journalLinePayload(JournalLine $line): array
    {
        return [
            'id' => (int) $line->id,
            'entry_number' => $line->entry_number,
            'entry_date' => $line->entry_date instanceof \DateTimeInterface
                ? $line->entry_date->format('Y-m-d')
                : (string) $line->entry_date,
            'description' => $line->description,
            'debit_kobo' => (int) $line->debit_kobo,
            'credit_kobo' => (int) $line->credit_kobo,
            // Signed net, so the picker can show which way the money went.
            'amount_kobo' => (int) $line->debit_kobo - (int) $line->credit_kobo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reconciliationPayload(BankReconciliation $row): array
    {
        return [
            'id' => $row->id,
            'start_date' => $row->start_date?->toDateString(),
            'end_date' => $row->end_date?->toDateString(),
            'statement_closing_balance_kobo' => (int) $row->statement_closing_balance_kobo,
            'cleared_balance_kobo' => (int) $row->cleared_balance_kobo,
            'difference_kobo' => (int) $row->difference_kobo,
            'status' => $row->status,
            'completed_at' => $row->completed_at?->toISOString(),
            'ledger_account' => $row->ledgerAccount ? [
                'id' => $row->ledgerAccount->id,
                'code' => $row->ledgerAccount->code,
                'name' => $row->ledgerAccount->name,
            ] : null,
            'store_bank' => $row->storeBank ? [
                'id' => $row->storeBank->id,
                'bank_name' => $row->storeBank->bank_name,
                'masked_account_number' => $row->storeBank->masked_account_number,
            ] : null,
        ];
    }

    /**
     * Header aliases understood by the parser, in logical-column order.
     *
     * @var array<string, array<int, string>>
     */
    private const COLUMN_ALIASES = [
        'date' => ['date', 'transaction date', 'trans date', 'value date', 'entry date', 'posting date'],
        'description' => ['description', 'narration', 'details', 'memo', 'particulars'],
        'reference' => ['reference', 'ref', 'reference no', 'ref no', 'reference number', 'cheque no', 'cheque number'],
        'amount' => ['amount', 'value', 'amount (ngn)', 'amount(ngn)', 'transaction amount'],
        'debit' => ['debit', 'debit amount', 'dr', 'money out', 'withdrawal'],
        'credit' => ['credit', 'credit amount', 'cr', 'money in', 'deposit', 'lodgement'],
    ];

    /**
     * Every alias above, flattened, for header-row detection.
     *
     * @var array<int, string>
     */
    private const ALL_ALIASES = [
        'date', 'transaction date', 'trans date', 'value date', 'entry date', 'posting date',
        'description', 'narration', 'details', 'memo', 'particulars',
        'reference', 'ref', 'reference no', 'ref no', 'reference number', 'cheque no', 'cheque number',
        'amount', 'value', 'amount (ngn)', 'amount(ngn)', 'transaction amount',
        'debit', 'debit amount', 'dr', 'money out', 'withdrawal',
        'credit', 'credit amount', 'cr', 'money in', 'deposit', 'lodgement',
    ];
}
