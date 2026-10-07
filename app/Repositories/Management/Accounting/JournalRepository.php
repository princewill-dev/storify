<?php

namespace App\Repositories\Management\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * WS-23 — data access for manual journal entries.
 *
 * Everything JournalController used to build inline as queries lives here:
 * the filtered list with its line counts and debit/credit sums, the filtered
 * totals aggregate across every matching entry, the eager loads the detail
 * payload renders, the forward reversal link, and the business-scoped account
 * lookup the line normaliser validates against.
 *
 * HTTP responses stay in the controller and workflows/transactions in
 * JournalService — nothing in here calls DB::transaction() or abort().
 */
final class JournalRepository
{
    /**
     * @param  array<string, mixed>  $filters  validated IndexJournalEntryRequest data
     */
    public function paginateForBusiness(int $businessId, array $filters): LengthAwarePaginator
    {
        return $this->baseQuery($businessId, $filters)
            ->withCount('lines')
            ->withSum('lines as total_debits', 'debit_kobo')
            ->withSum('lines as total_credits', 'credit_kobo')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * Filtered debit/credit totals across every matching entry, not just the
     * page the table happens to show.
     *
     * @param  array<string, mixed>  $filters
     */
    public function filteredTotals(int $businessId, array $filters): ?object
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.id', $this->baseQuery($businessId, $filters)->select('id'))
            ->selectRaw('COALESCE(SUM(journal_lines.debit_kobo), 0) as debits, COALESCE(SUM(journal_lines.credit_kobo), 0) as credits')
            ->first();
    }

    /**
     * The business's filtered journal query without the page window: shared
     * by the paginated rows and the totals aggregate so both see the same
     * rows.
     *
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(int $businessId, array $filters): Builder
    {
        return JournalEntry::query()
            ->where('business_id', $businessId)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('entry_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('entry_date', '<=', $to))
            // Legacy only searched entry_number; reference and memo were in
            // the placeholder but not in the query.
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.$term.'%';

                $q->where(fn ($inner) => $inner
                    ->where('entry_number', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhere('memo', 'like', $like));
            });
    }

    /**
     * Eager-load everything the detail payload renders in one pass.
     */
    public function loadForDetail(JournalEntry $entry): JournalEntry
    {
        $entry->loadMissing(['lines.account', 'fiscalPeriod', 'postedBy', 'reversalOf']);

        return $entry;
    }

    /**
     * The forward link legacy never had: a void original points at the entry
     * that reversed it so the detail screen can explain the void.
     */
    public function reversedBy(JournalEntry $entry): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('reversal_of_id', $entry->id)
            ->orderBy('id')
            ->first();
    }

    /**
     * Business-scoped account ids: the writable-account check in
     * JournalService::normaliseLines() must never accept another business's
     * ledger account (or an unknown id).
     *
     * @param  array<int, int>  $accountIds
     * @return array<int, int>
     */
    public function ownedLedgerAccountIds(int $businessId, array $accountIds): array
    {
        return LedgerAccount::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $accountIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
