<?php

namespace App\Repositories\Management\Accounting;

use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\StoreBank;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * WS-37 — bank reconciliation queries and persists.
 *
 * Everything the controller used to build inline: the import list with its
 * counts, the candidate ledger-line queries, the claimed-journal-line
 * lookups, the cleared-balance aggregate, the option lists, the response
 * eager loads and the row writes.
 *
 * Two rules hold here: no DB::transaction (transaction boundaries belong to
 * BankReconciliationService, which composes these calls) and no abort()
 * (authorisation stays at the HTTP edge). The auto-match "one journal line
 * claimed once" invariant is NOT here either — the in-memory claimed list
 * lives in the service, which feeds it back in through $excludeJournalLineIds.
 */
final class BankReconciliationRepository
{
    /**
     * Does the business have any account it can reconcile against?
     *
     * @param  array<int, string>  $bankSubtypes
     */
    public function hasBankAccounts(int $businessId, array $bankSubtypes): bool
    {
        return LedgerAccount::query()
            ->where('business_id', $businessId)
            ->whereIn('subtype', $bankSubtypes)
            ->exists();
    }

    /**
     * Statement imports, newest first, with their line counts.
     */
    public function paginateImports(int $businessId, int $perPage = 15): LengthAwarePaginator
    {
        return BankStatementImport::query()
            ->where('business_id', $businessId)
            ->with(['storeBank', 'ledgerAccount'])
            ->withCount([
                'lines',
                'lines as unmatched_count' => fn ($q) => $q->where('status', 'unmatched'),
                'lines as matched_count' => fn ($q) => $q->where('status', 'matched'),
            ])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, BankReconciliation>
     */
    public function recentReconciliations(int $businessId, int $limit = 10): Collection
    {
        return BankReconciliation::query()
            ->where('business_id', $businessId)
            ->with(['storeBank', 'ledgerAccount'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Active bank/cash/gateway clearing accounts for the import picker.
     *
     * @param  array<int, string>  $bankSubtypes
     * @return Collection<int, LedgerAccount>
     */
    public function bankAccountOptions(int $businessId, array $bankSubtypes): Collection
    {
        return LedgerAccount::query()
            ->where('business_id', $businessId)
            ->active()
            ->whereIn('subtype', $bankSubtypes)
            ->orderBy('code')
            ->get();
    }

    /**
     * @return Collection<int, StoreBank>
     */
    public function storeBankOptions(int $businessId): Collection
    {
        return StoreBank::query()
            ->where('business_id', $businessId)
            ->orderBy('bank_name')
            ->get();
    }

    public function findAccountOrFail(int $businessId, int $ledgerAccountId): LedgerAccount
    {
        return LedgerAccount::query()
            ->where('business_id', $businessId)
            ->findOrFail($ledgerAccountId);
    }

    public function loadImportSummary(BankStatementImport $import): BankStatementImport
    {
        return $import->load(['storeBank', 'ledgerAccount'])->loadCount('lines');
    }

    public function loadImportDetail(BankStatementImport $import): BankStatementImport
    {
        return $import->load([
            'storeBank',
            'ledgerAccount',
            'importedBy',
            'lines' => fn ($q) => $q->orderBy('transaction_date')->orderBy('id'),
            'lines.matchedJournalLine.entry',
        ]);
    }

    public function refreshImportWithLineCount(BankStatementImport $import): BankStatementImport
    {
        return $import->fresh()->loadCount('lines');
    }

    public function loadReconciliationRelations(BankReconciliation $reconciliation): BankReconciliation
    {
        return $reconciliation->load(['storeBank', 'ledgerAccount']);
    }

    /**
     * Journal line ids already claimed by any statement line in the business.
     *
     * @return array<int, int>
     */
    public function claimedJournalLineIds(int $businessId): array
    {
        return BankStatementLine::query()
            ->where('business_id', $businessId)
            ->whereNotNull('matched_journal_line_id')
            ->pluck('matched_journal_line_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The match picker's candidate pool: posted journal lines on one account,
     * joined to their entries, excluding ids already claimed by a statement
     * line (in this business's books).
     *
     * @param  array<int, int|string>  $excludeJournalLineIds
     * @return Collection<int, JournalLine>
     */
    public function candidateLines(int $businessId, int $ledgerAccountId, array $excludeJournalLineIds, int $limit): Collection
    {
        return $this->candidateQuery($businessId, $ledgerAccountId)
            ->whereNotIn('journal_lines.id', $excludeJournalLineIds)
            ->orderByDesc('journal_entries.entry_date')
            ->orderByDesc('journal_lines.id')
            ->limit($limit)
            ->get();
    }

    /**
     * The single best auto-match candidate for one statement line: same
     * account and direction, exact amount, entry date within ±7 days of the
     * statement date, excluding ids claimed so far this run. Closest date
     * first, then lowest journal line id, so the walk is deterministic — the
     * one-to-one guarantee itself is the service's in-memory claimed list.
     *
     * @param  array<int, int|string>  $excludeJournalLineIds
     */
    public function firstAutoMatchCandidate(
        int $businessId,
        int $ledgerAccountId,
        Carbon $date,
        int $amountKobo,
        array $excludeJournalLineIds,
    ): ?JournalLine {
        return $this->candidateQuery($businessId, $ledgerAccountId)
            ->whereDate('journal_entries.entry_date', '>=', $date->copy()->subDays(7)->toDateString())
            ->whereDate('journal_entries.entry_date', '<=', $date->copy()->addDays(7)->toDateString())
            ->when(
                $amountKobo >= 0,
                fn ($q) => $q->where('journal_lines.debit_kobo', $amountKobo),
                fn ($q) => $q->where('journal_lines.credit_kobo', abs($amountKobo)),
            )
            ->whereNotIn('journal_lines.id', $excludeJournalLineIds)
            ->orderByRaw('ABS(DATEDIFF(journal_entries.entry_date, ?))', [$date->toDateString()])
            ->orderBy('journal_lines.id')
            ->select('journal_lines.id as journal_line_id')
            ->first();
    }

    /**
     * Posted journal lines on one account, joined to their entries.
     */
    private function candidateQuery(int $businessId, int $ledgerAccountId): Builder
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
     * A posted journal line of this business, for a manual match.
     */
    public function findPostedJournalLine(int $businessId, int $journalLineId): ?JournalLine
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.id', $journalLineId)
            ->select('journal_lines.id', 'journal_lines.ledger_account_id')
            ->first();
    }

    /**
     * Has another statement line already claimed this journal line?
     */
    public function journalLineClaimedByAnother(int $businessId, int $journalLineId, int $exceptStatementLineId): bool
    {
        return BankStatementLine::query()
            ->where('business_id', $businessId)
            ->where('matched_journal_line_id', $journalLineId)
            ->whereKeyNot($exceptStatementLineId)
            ->exists();
    }

    /**
     * Ledger balance for one account (debits - credits on posted entries).
     */
    public function ledgerBalanceKobo(?int $businessId, int $ledgerAccountId, ?string $asOf = null): int
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

    /**
     * Still-unmatched statement lines, oldest statement date first (and id
     * within a date) — the auto-match walk order.
     *
     * @return Collection<int, BankStatementLine>
     */
    public function unmatchedLines(BankStatementImport $import): Collection
    {
        return $import->lines()
            ->where('status', 'unmatched')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createImport(array $attributes): BankStatementImport
    {
        return BankStatementImport::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createLine(array $attributes): BankStatementLine
    {
        return BankStatementLine::create($attributes);
    }

    public function markLineMatched(BankStatementLine $line, int $journalLineId): void
    {
        $line->update([
            'status' => 'matched',
            'matched_journal_line_id' => $journalLineId,
        ]);
    }

    public function markLineUnmatched(BankStatementLine $line): void
    {
        $line->update([
            'status' => 'unmatched',
            'matched_journal_line_id' => null,
        ]);
    }

    public function markLineIgnored(BankStatementLine $line): void
    {
        $line->update(['status' => 'ignored', 'matched_journal_line_id' => null]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createReconciliation(array $attributes): BankReconciliation
    {
        return BankReconciliation::create($attributes);
    }

    public function markImportReconciled(BankStatementImport $import): void
    {
        $import->update(['status' => 'reconciled']);
    }
}
