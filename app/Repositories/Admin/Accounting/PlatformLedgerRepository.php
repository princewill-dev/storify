<?php

namespace App\Repositories\Admin\Accounting;

use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\LedgerMapping;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WS-13 — platform-ledger queries.
 *
 * Every method is scoped to the platform books (`business_id = null`); a
 * tenant row never enters a platform query. This layer builds queries and
 * applies single-model persists; it never opens a transaction and never calls
 * abort() — the mapping save's transaction lives in PlatformAccountingService
 * and the controller owns the HTTP status each guard refusal maps to.
 */
final class PlatformLedgerRepository
{
    /**
     * The dashboard's cash & clearing panel accounts.
     *
     * @return Collection<int, LedgerAccount>
     */
    public function cashAccounts(): Collection
    {
        return LedgerAccount::query()
            ->whereNull('business_id')
            ->whereIn('subtype', ['cash', 'bank', 'gateway_clearing'])
            ->orderBy('code')
            ->get();
    }

    /**
     * The platform chart, filtered by the chart's code/name search.
     *
     * @return Collection<int, LedgerAccount>
     */
    public function accounts(?string $term): Collection
    {
        return LedgerAccount::query()
            ->whereNull('business_id')
            ->when($term, function ($query, string $term) {
                $query->where(function ($inner) use ($term) {
                    $inner->where('code', 'like', '%'.$term.'%')
                        ->orWhere('name', 'like', '%'.$term.'%');
                });
            })
            ->orderBy('code')
            ->get();
    }

    /**
     * Every platform account, inactive ones included: the settings picker
     * shows an inactive account a mapping already points at as a real option
     * instead of a blank select.
     *
     * @return Collection<int, LedgerAccount>
     */
    public function allAccounts(): Collection
    {
        return LedgerAccount::query()
            ->whereNull('business_id')
            ->orderBy('code')
            ->get();
    }

    /**
     * Debit-credit totals keyed by account id, one query for any set of
     * accounts (the legacy dashboard ran one per cash account).
     *
     * @param  array<int, int>  $accountIds
     * @return array<int, int>
     */
    public function balancesByAccount(array $accountIds): array
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
     * The dashboard's last-N journal entries (legacy's Recent Journal Entries
     * table), newest first with their line counts and debit/credit sums.
     *
     * @return Collection<int, JournalEntry>
     */
    public function recentEntries(int $limit): Collection
    {
        return JournalEntry::query()
            ->whereNull('business_id')
            ->withCount('lines')
            ->withSum('lines as total_debits', 'debit_kobo')
            ->withSum('lines as total_credits', 'credit_kobo')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The journal page: 20/page by default, newest first, searchable by entry
     * number, reference or memo (legacy's three-way search).
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateJournal(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->journalQuery($filters)
            ->withCount('lines')
            ->withSum('lines as total_debits', 'debit_kobo')
            ->withSum('lines as total_credits', 'credit_kobo')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The same filtered row set as the journal page, unpaginated for the CSV
     * export.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, JournalEntry>
     */
    public function journalForExport(array $filters): Collection
    {
        return $this->journalQuery($filters)
            ->withCount('lines')
            ->withSum('lines as total_debits', 'debit_kobo')
            ->withSum('lines as total_credits', 'credit_kobo')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Totals for the filtered set (not just the page) so the footer agrees
     * with the search, the same shape the management journal returns.
     *
     * @param  array<string, mixed>  $filters
     * @return array{debits: int, credits: int}
     */
    public function journalTotals(array $filters): array
    {
        $totals = $this->journalQuery($filters)
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->selectRaw('COALESCE(SUM(journal_lines.debit_kobo), 0) as debits, COALESCE(SUM(journal_lines.credit_kobo), 0) as credits')
            ->first();

        return [
            'debits' => (int) ($totals->debits ?? 0),
            'credits' => (int) ($totals->credits ?? 0),
        ];
    }

    /**
     * The shared journal query: platform scope only, newest first, filtered by
     * the validated q/status/from/to. One builder keeps the list, its totals
     * footer and the CSV export on the same rows.
     *
     * @param  array<string, mixed>  $filters
     */
    private function journalQuery(array $filters): Builder
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
     * Eager-load everything the entry detail payload shows.
     */
    public function loadEntryDetail(JournalEntry $entry): JournalEntry
    {
        $entry->load([
            'lines.account:id,code,name',
            'postedBy:id,name',
            'fiscalPeriod:id,name',
            'reversalOf:id,entry_number',
        ]);

        return $entry;
    }

    /**
     * The platform entry that reverses the given one, if any.
     */
    public function reversedBy(JournalEntry $entry): ?JournalEntry
    {
        return JournalEntry::query()
            ->whereNull('business_id')
            ->where('reversal_of_id', $entry->id)
            ->first(['id', 'entry_number']);
    }

    /**
     * @return Collection<string, LedgerMapping>
     */
    public function mappings(): Collection
    {
        return LedgerMapping::query()
            ->whereNull('business_id')
            ->with('account')
            ->get()
            ->keyBy('key');
    }

    /**
     * @return Collection<int, FiscalPeriod>
     */
    public function recentPeriods(int $limit): Collection
    {
        return FiscalPeriod::query()
            ->whereNull('business_id')
            ->orderByDesc('name')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, FiscalYear>
     */
    public function fiscalYears(int $limit): Collection
    {
        return FiscalYear::query()
            ->whereNull('business_id')
            ->orderByDesc('name')
            ->limit($limit)
            ->get();
    }

    public function findFiscalYear(string $name): ?FiscalYear
    {
        return FiscalYear::query()
            ->whereNull('business_id')
            ->where('name', $name)
            ->first();
    }

    public function saveMapping(string $key, int|string $accountId): void
    {
        LedgerMapping::updateOrCreate(
            ['business_id' => null, 'key' => $key],
            ['ledger_account_id' => $accountId]
        );
    }

    public function closePeriod(FiscalPeriod $period, int $userId): void
    {
        $period->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $userId,
        ]);
    }

    public function reopenPeriod(FiscalPeriod $period): void
    {
        // Allowed even when the parent year is closed: legacy had no reopen
        // for years, so refusing here would strand a period closed by mistake.
        $period->update([
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
        ]);
    }
}
